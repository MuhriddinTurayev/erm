<?php

namespace App\Services\Erp;

use App\Models\AuditLog;
use App\Models\CashDesk;
use App\Models\CashOp;
use App\Models\Contract;
use App\Models\FinCategory;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\StateWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Кассы и счета компании: остатки, приход/расход, переводы с конвертацией, зарплата,
 * лимиты по статьям, взаиморасчёты между объектами и прогноз движения денег.
 * Операция с объектом сразу попадает в бухгалтерию объекта — общие отчёты и касса компании остаются точными.
 */
class Cash
{
    public const DEFAULT_CATS = [
        'out' => ['Материалы', 'Зарплата', 'Подрядчики', 'Техника и топливо', 'Аренда', 'Налоги и сборы', 'Офис и прочее'],
        'in' => ['Продажа квартир', 'Аванс заказчика', 'Займы и инвестиции', 'Прочие поступления'],
    ];

    /** Создать стандартные кассу и статьи при первом входе в раздел */
    public static function ensureDefaults(Workspace $ws): void
    {
        if (! FinCategory::of($ws->id)->exists()) {
            $i = 0;
            foreach (self::DEFAULT_CATS as $kind => $names) {
                foreach ($names as $n) {
                    FinCategory::create(['workspace_id' => $ws->id, 'name' => $n, 'kind' => $kind, 'sort' => $i++]);
                }
            }
        }
        if (! CashDesk::of($ws->id)->exists()) {
            CashDesk::create(['workspace_id' => $ws->id, 'name' => 'Основная касса', 'kind' => 'cash', 'currency' => Money::base($ws)]);
            CashDesk::create(['workspace_id' => $ws->id, 'name' => 'Долларовая касса', 'kind' => 'cash', 'currency' => 'USD']);
            CashDesk::create(['workspace_id' => $ws->id, 'name' => 'Расчётный счёт', 'kind' => 'bank', 'currency' => Money::base($ws)]);
        }
    }

    /** Остатки касс: [desk_id => сумма в валюте кассы] */
    public static function balances(Workspace $ws): array
    {
        $sums = CashOp::of($ws->id)->selectRaw("cash_desk_id, sum(case when type='in' then amount else -amount end) s")->groupBy('cash_desk_id')->pluck('s', 'cash_desk_id');
        $out = [];
        foreach (CashDesk::of($ws->id)->get() as $d) {
            $out[$d->id] = round($d->opening + (float) ($sums[$d->id] ?? 0), 2);
        }

        return $out;
    }

    public static function totalBase(Workspace $ws): float
    {
        $sum = 0;
        $bal = self::balances($ws);
        foreach (CashDesk::of($ws->id)->where('is_active', true)->get() as $d) {
            $sum += Money::toBase($ws, $bal[$d->id] ?? 0, $d->currency);
        }

        return round($sum, 2);
    }

    /**
     * Провести операцию. $d: desk, type, amount, date, category_id, project_id, counterparty_id, contract_id, note, employee, payment_request_id, rate
     */
    public static function add(Workspace $ws, User $by, array $d): CashOp
    {
        return DB::transaction(fn () => self::addLocked($ws, $by, $d));
    }

    /** Остаток кассы с блокировкой строки кассы до конца транзакции (две операции одновременно не уведут кассу в минус) */
    public static function lockedBalance(Workspace $ws, int $deskId): float
    {
        $desk = CashDesk::of($ws->id)->whereKey($deskId)->lockForUpdate()->firstOrFail();
        $s = CashOp::of($ws->id)->where('cash_desk_id', $desk->id)->selectRaw("sum(case when type='in' then amount else -amount end) s")->value('s');

        return round((float) $desk->opening + (float) $s, 2);
    }

    /** Курс операции: в валюте компании — 1; в другой — официальный или введённый (не дальше допустимого от официального) */
    public static function checkedRate(Workspace $ws, User $by, string $currency, $rate): float
    {
        if ($currency === Money::base($ws)) {
            return 1.0;
        }
        $rate = (float) $rate;
        if ($rate <= 0) {
            return Money::rate($ws, $currency);
        }
        $pct = $by->isAdmin() ? 50 : 15;
        if (! Money::rateOk($ws, $currency, $rate, $pct)) {
            throw new \DomainException(__('Курс :r слишком отличается от официального (:o). Проверьте курс — допустимо отклонение до :p%.',
                ['r' => $rate, 'o' => round(Money::rate($ws, $currency), 2), 'p' => $pct]));
        }

        return $rate;
    }

    private static function addLocked(Workspace $ws, User $by, array $d): CashOp
    {
        $desk = CashDesk::of($ws->id)->findOrFail($d['cash_desk_id']);
        if (! $desk->is_active) {
            throw new \DomainException(__('Касса «:n» в архиве — операции по ней не проводятся', ['n' => $desk->name]));
        }
        // связанные записи — только своей компании
        foreach (['counterparty_id' => \App\Models\Counterparty::class, 'contract_id' => Contract::class, 'category_id' => FinCategory::class, 'payment_request_id' => PaymentRequest::class] as $k => $cls) {
            if (! empty($d[$k]) && ! $cls::where('workspace_id', $ws->id)->whereKey($d[$k])->exists()) {
                throw new \DomainException(__('Запись не найдена'));
            }
        }
        $amount = round((float) $d['amount'], 2);
        if ($amount <= 0) {
            throw new \DomainException(__('Сумма должна быть больше нуля'));
        }
        $bal = self::lockedBalance($ws, $desk->id);
        // та же операция того же сотрудника только что проведена — это двойное нажатие (сайт, Telegram, API), второй раз не проводим
        if (empty($d['transfer_key']) && ! self::once($ws, 'op', [$by->id, $desk->id, $d['type'], $amount, $d['project_id'] ?? null, $d['category_id'] ?? null,
            $d['counterparty_id'] ?? null, $d['payment_request_id'] ?? null, $d['employee'] ?? null, trim((string) ($d['note'] ?? ''))])) {
            throw new \DomainException(self::twice());
        }
        if ($d['type'] === 'out' && $amount > $bal + 0.001) {
            throw new \DomainException(__('В кассе «:n» только :s', ['n' => $desk->name, 's' => Money::fmt($bal, $desk->currency)]));
        }
        $rate = self::checkedRate($ws, $by, $desk->currency, $d['rate'] ?? null);
        $d['amount'] = $amount;
        $op = CashOp::create([
            'workspace_id' => $ws->id, 'cash_desk_id' => $desk->id, 'type' => $d['type'], 'date' => $d['date'] ?? $ws->localNow()->toDateString(),
            'amount' => round((float) $d['amount'], 2), 'rate' => $rate, 'amount_base' => Money::toBase($ws, (float) $d['amount'], $desk->currency, $rate),
            'category_id' => $d['category_id'] ?? null, 'project_id' => $d['project_id'] ?? null, 'counterparty_id' => $d['counterparty_id'] ?? null,
            'contract_id' => $d['contract_id'] ?? null, 'payment_request_id' => $d['payment_request_id'] ?? null, 'transfer_key' => $d['transfer_key'] ?? null,
            'employee' => $d['employee'] ?? null, 'note' => $d['note'] ?? null, 'user_id' => $by->id,
        ]);
        self::postToProject($ws, $op);
        if (empty($d['transfer_key'])) {
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'project' => $op->project_id ? (StateWriter::projects($ws)[$op->project_id] ?? null) : null,
                'action' => 'cash.add', 'summary' => $desk->name.': '.($op->type === 'in' ? '+' : '−').Money::fmt($op->amount, $desk->currency)
                    .($op->category ? ' · '.$op->category->name : '').($op->note ? ' · '.$op->note : '').($op->employee ? ' · '.$op->employee : '')]);
        }

        return $op;
    }

    /**
     * Защита от двойного нажатия: одинаковая операция за $sec секунд проходит один раз.
     * Метка ставится в той же транзакции, что и операция: не прошла операция (нет денег, ошибка) — метки тоже нет.
     */
    public static function once(Workspace $ws, string $kind, array $parts, int $sec = 15): bool
    {
        return Cache::add('dup:'.$kind.':'.$ws->id.':'.sha1(json_encode($parts)), 1, $sec);
    }

    public static function twice(): string
    {
        return __('Такая же операция только что проведена — похоже, кнопку нажали дважды. Второй раз не проводим. Если это действительно ещё одна операция — повторите через несколько секунд.');
    }

    /** Отразить операцию в бухгалтерии объекта (в валюте компании) */
    private static function postToProject(Workspace $ws, CashOp $op): void
    {
        if (! $op->project_id || $op->transfer_key || ! StateWriter::project($ws, $op->project_id)) {
            return;
        }
        $cp = $op->counterparty;
        $txId = StateWriter::addTx($ws->id, $op->project_id, [
            'date' => $op->date->toDateString(), 'type' => $op->type, 'cat' => $op->category?->name ?? ($op->employee ? 'Зарплата' : 'Офис и прочее'),
            'amount' => $op->amount_base, 'who' => $op->employee ?: ($cp?->name ?? ''), 'desc' => trim(($op->note ?? '').($op->desk->currency === 'USD' ? ' ($'.number_format($op->amount, 2, '.', ' ').')' : '')),
            'link' => ['kind' => 'cash', 'id' => $op->id],
        ]);
        $op->forceFill(['tx_id' => $txId])->saveQuietly();
    }

    /** Перевод с кассы объекта или на неё: запись в бухгалтерии этого объекта (между кассами одного объекта — не нужна) */
    private static function postTransfer(Workspace $ws, CashOp $op, CashDesk $desk, CashDesk $other): void
    {
        if (! $desk->project_id || $desk->project_id === $other->project_id || ! StateWriter::project($ws, $desk->project_id)) {
            return;
        }
        $txId = StateWriter::addTx($ws->id, $desk->project_id, [
            'date' => $op->date->toDateString(), 'type' => $op->type, 'cat' => $op->type === 'in' ? 'Перевод из другой кассы' : 'Перевод в другую кассу',
            'amount' => $op->amount_base, 'who' => $other->name, 'desc' => (string) ($op->note ?? ''), 'link' => ['kind' => 'cash', 'id' => $op->id],
        ]);
        $op->forceFill(['project_id' => $desk->project_id, 'tx_id' => $txId])->saveQuietly();
    }

    public static function delete(Workspace $ws, User $by, CashOp $op): void
    {
        $ops = $op->transfer_key ? CashOp::of($ws->id)->where('transfer_key', $op->transfer_key)->get() : collect([$op]);
        foreach ($ops as $o) {
            if ($o->tx_id && $o->project_id) {
                StateWriter::mutate($ws->id, function (array &$data) use ($o) {
                    foreach ($data['projects'] as &$p) {
                        if ((string) ($p['id'] ?? '') === $o->project_id) {
                            $p['tx'] = array_values(array_filter((array) ($p['tx'] ?? []), fn ($t) => ($t['id'] ?? '') !== $o->tx_id));
                        }
                    }
                });
            }
            PaymentRequest::where('cash_op_id', $o->id)->update(['status' => 'approved', 'cash_op_id' => null]);
            $o->delete();
        }
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'action' => 'cash.del',
            'summary' => ($op->desk?->name ?? '').': '.($op->type === 'in' ? '+' : '−').Money::fmt($op->amount, $op->desk?->currency ?? 'UZS').($op->note ? ' · '.$op->note : '')]);
    }

    /** Перевод между кассами, в т.ч. сум ⇄ доллар по курсу */
    public static function transfer(Workspace $ws, User $by, int $from, int $to, float $amount, ?float $rate, ?string $note, ?string $date): array
    {
        $a = CashDesk::of($ws->id)->findOrFail($from);
        $b = CashDesk::of($ws->id)->findOrFail($to);
        if ($a->id === $b->id) {
            throw new \DomainException(__('Выберите разные кассы'));
        }
        $key = Str::lower(Str::random(12));
        // курс сделки: сколько валюты компании за 1 USD — не дальше допустимого от официального
        $usd = ($a->currency !== $b->currency || $a->currency === 'USD') ? self::checkedRate($ws, $by, 'USD', $rate) : 1.0;
        $received = $amount;
        if ($a->currency !== $b->currency) {
            $received = $a->currency === 'USD' ? $amount * $usd : $amount / $usd;
        }
        return DB::transaction(function () use ($ws, $by, $a, $b, $amount, $received, $usd, $key, $note, $date) {
            // блокируем обе кассы в одном порядке (по id), чтобы встречные переводы не зависли
            foreach (collect([$a->id, $b->id])->sort() as $id) {
                self::lockedBalance($ws, $id);
            }
            if (! self::once($ws, 'transfer', [$by->id, $a->id, $b->id, round($amount, 2), trim((string) $note)])) {
                throw new \DomainException(self::twice());
            }
            $base = ['date' => $date, 'transfer_key' => $key, 'note' => $note];
            $o1 = self::add($ws, $by, $base + ['cash_desk_id' => $a->id, 'type' => 'out', 'amount' => $amount, 'rate' => $a->currency === 'USD' ? $usd : 1]);
            $o2 = self::add($ws, $by, $base + ['cash_desk_id' => $b->id, 'type' => 'in', 'amount' => round($received, 2), 'rate' => $b->currency === 'USD' ? $usd : 1]);
            // касса объекта получила деньги (финансирование объекта) или отдала — это видно и в бухгалтерии объекта
            self::postTransfer($ws, $o1, $a, $b);
            self::postTransfer($ws, $o2, $b, $a);
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'action' => 'cash.edit',
                'summary' => 'Перевод: '.$a->name.' → '.$b->name.' · '.Money::fmt($amount, $a->currency).($a->currency !== $b->currency ? ' = '.Money::fmt($received, $b->currency).' (курс '.$usd.')' : '')]);

            return [$o1, $o2];
        });
    }

    /** Расход по статьям за месяц и лимиты */
    public static function limits(Workspace $ws, ?string $month = null): array
    {
        $month = $month ?: $ws->localNow()->format('Y-m');
        $spent = CashOp::of($ws->id)->where('type', 'out')->whereNull('transfer_key')->where('date', 'like', $month.'%')
            ->selectRaw('category_id, sum(amount_base) s')->groupBy('category_id')->pluck('s', 'category_id');
        $out = [];
        foreach (FinCategory::of($ws->id)->where('kind', 'out')->orderBy('sort')->get() as $c) {
            $s = (float) ($spent[$c->id] ?? 0);
            $out[] = ['cat' => $c, 'spent' => $s, 'limit' => $c->month_limit, 'pct' => $c->month_limit > 0 ? round($s / $c->month_limit * 100) : null];
        }

        return $out;
    }

    /** Превысит ли расход лимит статьи: [лимит, уже потрачено] или null */
    public static function overLimit(Workspace $ws, ?int $catId, float $amountBase): ?array
    {
        $c = $catId ? FinCategory::of($ws->id)->find($catId) : null;
        if (! $c || $c->month_limit <= 0) {
            return null;
        }
        $spent = (float) CashOp::of($ws->id)->where('type', 'out')->whereNull('transfer_key')->where('category_id', $c->id)
            ->where('date', 'like', $ws->localNow()->format('Y-m').'%')->sum('amount_base');

        return $spent + $amountBase > $c->month_limit ? [$c->month_limit, $spent, $c->name] : null;
    }

    /**
     * Взаиморасчёты объектов: касса объекта A оплатила расходы объекта B.
     * [[from, to, amount]] — объект «to» должен объекту «from».
     */
    public static function interProject(Workspace $ws): array
    {
        $rows = CashOp::of($ws->id)->join('cash_desks', 'cash_desks.id', '=', 'cash_ops.cash_desk_id')
            ->whereNotNull('cash_desks.project_id')->whereNotNull('cash_ops.project_id')->whereColumn('cash_desks.project_id', '!=', 'cash_ops.project_id')
            ->whereNull('cash_ops.transfer_key')
            ->selectRaw("cash_desks.project_id as src, cash_ops.project_id as dst, sum(case when cash_ops.type='out' then cash_ops.amount_base else -cash_ops.amount_base end) s")
            ->groupBy('src', 'dst')->get();
        $net = [];
        foreach ($rows as $r) {
            $k = $r->src < $r->dst ? $r->src.'|'.$r->dst : $r->dst.'|'.$r->src;
            $net[$k] = ($net[$k] ?? 0) + ($r->src < $r->dst ? 1 : -1) * (float) $r->s;
        }
        $names = StateWriter::projects($ws);
        $out = [];
        foreach ($net as $k => $v) {
            if (abs($v) < 1) {
                continue;
            }
            [$a, $b] = explode('|', $k);
            [$from, $to] = $v > 0 ? [$a, $b] : [$b, $a];
            $out[] = ['from' => $names[$from] ?? $from, 'to' => $names[$to] ?? $to, 'amount' => abs($v)];
        }

        return $out;
    }

    /**
     * Прогноз движения денег по неделям: остаток касс + платежи покупателей по графикам рассрочки
     * − одобренные заявки на оплату − остатки по договорам с поставщиками (по сроку).
     */
    public static function forecast(Workspace $ws, int $weeks = 12): array
    {
        $today = $ws->localNow()->startOfDay();
        $start = $today->copy()->startOfWeek();
        $rows = [];
        for ($i = 0; $i < $weeks; $i++) {
            $rows[$i] = ['from' => $start->copy()->addWeeks($i), 'in' => 0.0, 'out' => 0.0, 'items_in' => [], 'items_out' => []];
        }
        $idx = function (Carbon $d) use ($start, $weeks, $today): ?int {
            if ($d->lt($today)) {
                $d = $today;   // просроченное ожидаем на этой неделе
            }
            $i = intdiv($start->diffInDays($d, false), 7);

            return $i >= 0 && $i < $weeks ? (int) $i : null;
        };
        $names = StateWriter::projects($ws);

        // поступления от покупателей
        foreach ($ws->projects() as $p) {
            if (Workspace::isDemo($p)) {
                continue;
            }
            foreach (Apartments::list($p) as $a) {
                if (! in_array($a['status'], ['sold', 'booked'], true)) {
                    continue;
                }
                foreach (Apartments::schedule($a) as $r) {
                    if (! $r['paid'] && ($i = $idx(Carbon::parse($r['date']))) !== null) {
                        $rows[$i]['in'] += $r['amount'];
                        $rows[$i]['items_in'][] = ($p['name'] ?? '').' · '.__('Кв.').' '.apt_num($a['num']).' · '.$a['buyer'];
                    }
                }
            }
        }
        // одобренные, но не оплаченные заявки
        foreach (PaymentRequest::of($ws->id)->where('status', 'approved')->get() as $r) {
            $i = $idx($r->due_date ? Carbon::parse($r->due_date) : $today);
            if ($i !== null) {
                $rows[$i]['out'] += Money::toBase($ws, $r->amount, $r->currency);
                $rows[$i]['items_out'][] = '№'.$r->number.' · '.$r->purpose;
            }
        }
        // остатки по договорам, где платим мы
        foreach (Contract::of($ws->id)->where('status', 'active')->where('direction', 'out')->whereNotNull('due_date')->with('counterparty')->get() as $c) {
            // уже одобренные заявки по договору учтены выше — их не считаем второй раз (в валюте компании по их валюте)
            $approved = PaymentRequest::where('contract_id', $c->id)->where('status', 'approved')->get()->sum(fn ($r) => Money::toBase($ws, (float) $r->amount, $r->currency));
            $left = Contracts::debtBase($ws, $c) - $approved;
            if ($left > 0 && ($i = $idx(Carbon::parse($c->due_date))) !== null) {
                $rows[$i]['out'] += $left;
                $rows[$i]['items_out'][] = __('Договор').' '.$c->number.' · '.$c->counterparty?->name;
            }
        }
        $bal = self::totalBase($ws);
        foreach ($rows as &$r) {
            $r['open'] = $bal;
            $bal += $r['in'] - $r['out'];
            $r['close'] = $bal;
        }

        return $rows;
    }
}
