<?php

namespace App\Services\Bot;

use App\Models\Expense;
use App\Models\LeadInbox;
use App\Models\PaymentRequest;
use App\Models\SupplyRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\CompanyStats;
use App\Services\Erp\Cash;
use App\Services\Erp\Money;
use App\Services\Erp\Payments;
use App\Services\Erp\Supply;
use App\Services\StateWriter;
use App\Services\Telegram;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Разделы бота по ролям: меню внизу экрана и короткие сводки.
 * Прораб — материалы, корзина, заявки, расход; бухгалтер — очередь согласования; кассир — оплаты на сегодня;
 * менеджер — свободные квартиры и заявки клиентов; руководитель — общее состояние.
 */
class Sections
{
    private Workspace $ws;

    public function __construct(private User $u, private string $chat)
    {
        $this->ws = $u->workspace;
    }

    /** Кнопки меню (на языке пользователя): [ключ => подпись] по рядам */
    public static function menu(User $u): array
    {
        $m = fn (string $k) => $u->canModule($k);
        $sup = $u->canEdit() && $m('supply');
        $boss = Supply::canDecide($u);
        $rows = match (true) {
            $u->isAdmin() => [['status', 'queue'], ['orders', 'materials'], ['cash', 'help']],
            $u->role === 'accountant' => [['queue', 'orders'], ['materials', 'cash'], ['help']],
            $u->role === 'cashier' => [['pay', 'cash'], ['help']],
            $u->role === 'manager' => [['flats', 'leads'], ['report', 'help']],
            $u->role === 'foreman' || $sup => [['materials', 'cart'], ['myorders', 'use'], ['report', 'help']],
            $u->seesMoney() => [['status', 'cash'], ['help']],
            default => [['report', 'help']],
        };
        $labels = self::labels();

        return array_map(fn ($r) => array_map(fn ($k) => $labels[$k], array_values(array_filter($r, fn ($k) => $k !== 'queue' || $boss || $m('payments')))), $rows);
    }

    /** Подписи кнопок меню — по ним же узнаём нажатие */
    public static function labels(): array
    {
        return [
            'materials' => '🧱 '.__('Материалы'), 'cart' => '🛒 '.__('Корзина'), 'myorders' => '📦 '.__('Мои заявки'), 'use' => '📤 '.__('Расход материала'),
            'report' => '📓 '.__('Отчёт за день'), 'help' => '❓ '.__('Помощь'), 'status' => '📊 '.__('Общее состояние'), 'queue' => '✅ '.__('Очередь согласования'),
            'orders' => '📦 '.__('Заявки снабжения'), 'cash' => '💰 '.__('Касса'), 'pay' => '💳 '.__('Оплаты на сегодня'), 'flats' => '🔑 '.__('Свободные квартиры'),
            'leads' => '📞 '.__('Заявки клиентов'),
        ];
    }

    /** Нажата кнопка меню? → ключ раздела */
    public static function match(string $text): ?string
    {
        $t = trim($text);
        foreach (self::labels() as $k => $label) {
            if ($t === $label || $t === trim(Str::after($label, ' '))) {
                return $k;
            }
        }

        return null;
    }

    private function out(string $html, array $buttons = []): void
    {
        try {
            Telegram::message($this->chat, $html, $buttons);
        } catch (\Throwable $e) {
            Log::warning('Telegram sections: '.$e->getMessage());
        }
    }

    private function m(float $v): string
    {
        return money_s($v, CompanyStats::currency($this->ws));
    }

    /** 📊 Руководителю: деньги, решения, материалы, задержки — одним сообщением */
    public function status(): void
    {
        $u = $this->u;
        $only = $u->allowedObjects();
        $L = ['📊 <b>'.__('Общее состояние').' · '.e($this->ws->name).'</b>', ''];
        if ($u->seesMoney()) {
            $s = CompanyStats::build($this->ws, null, $only)['totals'];
            $L[] = '💰 '.__('В кассах').': <b>'.$this->m(Cash::totalBase($this->ws)).'</b>';
            $L[] = '📉 '.__('Потрачено сегодня').': '.$this->m($s['out_day']).' · '.__('вчера').': '.$this->m($s['out_yest']);
            $L[] = '📈 '.__('Поступило сегодня').': '.$this->m($s['in_day']).' · '.__('за месяц').': '.$this->m($s['in_month']);
            $L[] = '';
        }
        $pay = PaymentRequest::of($this->ws->id)->where('status', 'pending')->get()->filter(fn ($r) => Payments::canDecide($u, $r))->count();
        $exp = $u->isAdmin() ? Expense::where('workspace_id', $this->ws->id)->where('status', 'pending')->count() : 0;
        $sup = Supply::canDecide($u) ? SupplyRequest::of($this->ws->id)->where('status', 'new')->count() : 0;
        $L[] = '✅ '.__('Ждут вашего решения').': <b>'.($pay + $exp + $sup).'</b>'.($pay + $exp + $sup ? ' ('.__('оплаты').' '.$pay.', '.__('расходы').' '.$exp.', '.__('материалы').' '.$sup.')' : '');
        $today = $this->ws->localNow()->toDateString();
        $late = SupplyRequest::of($this->ws->id)->whereIn('status', Supply::RECEIVABLE)->whereNotNull('need_by')->whereDate('need_by', '<', $today)->count();
        $L[] = '🚚 '.__('Материал опаздывает').': <b>'.$late.'</b>';
        $tasks = Task::of($this->ws->id)->where('status', '!=', 'done')->whereDate('due_date', '<', $today)->count();
        $L[] = '📋 '.__('Просроченные задачи').': <b>'.$tasks.'</b>';
        $low = $this->lowList(4);
        if ($low) {
            $L[] = '';
            $L[] = '⚠️ <b>'.__('Заканчивается на объектах').':</b>';
            array_push($L, ...$low);
        }
        $this->out(implode("\n", $L), [
            [['text' => '✅ '.__('Очередь согласования'), 'data' => 'sec:queue'], ['text' => '📦 '.__('Заявки снабжения'), 'data' => 'sq:0']],
            [['text' => __('Открыть панель'), 'url' => url('/company/monitor')]],
        ]);
    }

    /** Материалы на исходе по всем объектам пользователя */
    private function lowList(int $limit): array
    {
        $out = [];
        foreach (StateWriter::projects($this->ws, $this->u->allowedObjects()) as $pid => $name) {
            if (! Catalog::warehouse($this->ws, (string) $pid)) {
                continue;
            }
            foreach (Catalog::stock($this->ws, (string) $pid) as $r) {
                if ($r['low']) {
                    $out[] = '• '.e($name).': '.e($r['item']->name).' — '.Catalog::n($r['qty']).' '.e(Supply::unit($r['item']->unit)).($r['days'] !== null ? ' (~'.__(':n дн.', ['n' => $r['days']]).')' : '');
                }
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** ✅ Очередь согласования: каждая заявка — отдельным сообщением с кнопками */
    public function queue(): void
    {
        $u = $this->u;
        $n = 0;
        foreach (PaymentRequest::of($this->ws->id)->where('status', 'pending')->latest('id')->limit(30)->get()->filter(fn ($r) => Payments::canDecide($u, $r))->take(5) as $pr) {
            $this->out(Payments::card($pr), [[['text' => '✅ '.__('Согласовать'), 'data' => 'pa:'.$pr->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'pn:'.$pr->id]]]);
            $n++;
        }
        if (Supply::canDecide($u)) {
            foreach (SupplyRequest::of($this->ws->id)->where('status', 'new')->orderByDesc('urgent')->oldest('id')->limit(5)->get() as $sr) {
                $this->out(Supply::card($sr, true), [[['text' => '✅ '.__('Согласовать'), 'data' => 'sa:'.$sr->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'sn:'.$sr->id]]]);
                $n++;
            }
        }
        if ($u->isAdmin()) {
            foreach (Expense::where('workspace_id', $this->ws->id)->where('status', 'pending')->oldest('id')->limit(5)->get() as $e) {
                $this->out(\App\Services\ExpenseService::card($e, $this->ws), [[['text' => '✅ '.__('Согласовать'), 'data' => 'ap:'.$e->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'rj:'.$e->id]]]);
                $n++;
            }
        }
        $this->out($n ? '☝️ '.__('Это всё, что ждёт вашего решения сейчас.') : '✅ '.__('Очередь пуста — решать нечего.'), [[['text' => __('Открыть на сайте'), 'url' => url('/company/payments')]]]);
    }

    /** 💳 Кассиру: утверждённые заявки к оплате (сначала срочные по сроку) и движение кассы за сегодня */
    public function payToday(): void
    {
        if (! $this->u->canModule('payments') && ! $this->u->canModule('cash')) {
            $this->out(__('Нет доступа к этому разделу'));

            return;
        }
        $today = $this->ws->localNow()->toDateString();
        $list = PaymentRequest::of($this->ws->id)->where('status', 'approved')->orderByRaw('due_date is null')->orderBy('due_date')->limit(15)->get();
        $L = ['💳 <b>'.__('К оплате').'</b>'];
        $sum = 0;
        foreach ($list as $pr) {
            $base = Money::toBase($this->ws, $pr->amount, $pr->currency);
            $sum += $base;
            $due = $pr->due_date ? ($pr->due_date->toDateString() < $today ? ' ⚠️ '.__('просрочено') : ($pr->due_date->toDateString() === $today ? ' · '.__('сегодня') : ' · '.$pr->due_date->format('d.m'))) : '';
            $L[] = '• №'.$pr->number.' — <b>'.Money::fmt($pr->amount, $pr->currency).'</b> · '.e(Str::limit($pr->purpose, 40)).$due;
        }
        if (! $list->count()) {
            $L[] = '✅ '.__('Утверждённых заявок к оплате нет.');
        } else {
            $L[] = '';
            $L[] = __('Всего').': <b>'.$this->m($sum).'</b>';
        }
        $in = (float) \App\Models\CashOp::of($this->ws->id)->whereNull('transfer_key')->where('type', 'in')->where('date', $today)->sum('amount_base');
        $out = (float) \App\Models\CashOp::of($this->ws->id)->whereNull('transfer_key')->where('type', 'out')->where('date', $today)->sum('amount_base');
        $L[] = '';
        $L[] = '📥 '.__('Сегодня пришло').': '.$this->m($in).' · 📤 '.__('ушло').': '.$this->m($out);
        $this->out(implode("\n", $L), [[['text' => __('Оплатить на сайте'), 'url' => url('/company/payments?tab=approved')]]]);
    }

    /** 🔑 Менеджеру: свободные квартиры по объектам (по комнатам и цена от) */
    public function flats(): void
    {
        if (! $this->u->canModule('sales')) {
            $this->out(__('Нет доступа к этому разделу'));

            return;
        }
        $cur = CompanyStats::currency($this->ws);
        $L = ['🔑 <b>'.__('Свободные квартиры').'</b>'];
        $b = [];
        foreach ($this->ws->projects() as $p) {
            $pid = (string) ($p['id'] ?? '');
            if (! isset(StateWriter::projects($this->ws, $this->u->allowedObjects())[$pid])) {
                continue;
            }
            $free = array_filter(Apartments::list($p), fn ($a) => $a['status'] === 'free');
            $L[] = '';
            $L[] = '🏢 <b>'.e($p['name'] ?? '').'</b> — '.__('свободно').': <b>'.count($free).'</b>';
            $by = [];
            foreach ($free as $a) {
                $k = $a['rooms'] ?: 0;
                $by[$k][] = (float) $a['price'];
            }
            ksort($by);
            foreach ($by as $r => $prices) {
                $L[] = '• '.($r ? $r.__('-комн.') : __('Коммерция')).': '.count($prices).' · '.__('от').' '.money_s(min($prices), $cur);
            }
            $b[] = [['text' => '🔑 '.Str::limit($p['name'] ?? '', 30), 'url' => url('/company/sales?o='.$pid)]];
        }
        $this->out(implode("\n", $L), $b);
    }

    /** 📞 Менеджеру: последние заявки клиентов */
    public function leads(): void
    {
        if (! $this->u->canModule('sales')) {
            $this->out(__('Нет доступа к этому разделу'));

            return;
        }
        $list = LeadInbox::where('workspace_id', $this->ws->id)->latest('id')->limit(8)->get();
        $L = ['📞 <b>'.__('Заявки клиентов').'</b>'];
        foreach ($list as $l) {
            $L[] = '• <b>'.e($l->name ?: '—').'</b> '.e($l->phone).' · '.e($l->src).($l->rooms ? ' · '.$l->rooms.__('-комн.') : '').' · '.$l->created_at?->format('d.m H:i');
        }
        if (! $list->count()) {
            $L[] = __('Новых заявок нет.');
        }
        $this->out(implode("\n", $L), [[['text' => __('Открыть заявки'), 'url' => url('/crm#leads')]]]);
    }
}
