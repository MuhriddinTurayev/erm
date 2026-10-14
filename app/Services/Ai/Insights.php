<?php

namespace App\Services\Ai;

use App\Models\Contract;
use App\Models\Expense;
use App\Models\PaymentRequest;
use App\Models\SupplyRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\CompanyStats;
use App\Services\Erp\Cash;
use App\Services\Erp\Contracts;
use App\Services\Erp\Money;
use App\Services\Erp\Stock;
use App\Services\StateWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * «ИИ-анализ» на страницах: собираем цифры раздела (только то, что пользователь и так видит),
 * сразу показываем выводы по правилам, а ИИ (если подключён и есть в тарифе) пишет разбор и советы.
 * Ответ ИИ кэшируется по самим цифрам: пока данные не изменились, повторный запрос не тратится.
 *
 * Пункт: [level => bad|warn|ok|info, title, text, href|null]
 */
class Insights
{
    public const TOPICS = ['company', 'cash', 'forecast', 'sales', 'supply', 'objects'];

    /** Куда ИИ может отправить пользователя (ссылки из ответа проверяем по этому списку) */
    private const LINKS = ['/company/monitor', '/company/cash', '/company/payments', '/company/approvals', '/company/forecast', '/company/overview', '/company/sales',
        '/company/supply', '/company/stock', '/company/tasks', '/company/contracts', '/company/parties', '/company/tenders', '/company/journal', '/company/objects'];

    private const TTL = 6 * 3600;

    public function __construct(private AiService $ai) {}

    /** Может ли пользователь видеть разбор этого раздела */
    public static function allowed(User $u, string $topic): bool
    {
        return match ($topic) {
            'company' => $u->canModule('monitoring'),
            'cash', 'objects' => $u->seesMoney(),
            'forecast' => $u->canModule('forecast') && $u->seesMoney(),
            'sales' => $u->canModule('sales'),
            'supply' => $u->canModule('supply'),
            default => false,
        };
    }

    /** Может ли компания сейчас спросить ИИ (тариф, лимит, подключение) */
    public function aiReady(Workspace $ws, bool $auto = false): bool
    {
        if (! $ws->aiAllowed() || ! $this->ai->providerFor($ws)) {
            return false;
        }
        $limit = $ws->aiLimit();
        // сам (при открытии страницы) ИИ не тратит последние 10% лимита — они остаются для чата и кнопки «Обновить»
        $reserve = $auto ? max(3, (int) ceil($limit * 0.1)) : 0;

        return $limit <= 0 || $ws->aiUsedThisMonth() < $limit - $reserve;
    }

    /**
     * Разбор раздела. $mode: fast — правила и готовый ответ ИИ из кэша; ai — спросить ИИ (если нет в кэше); refresh — спросить заново.
     *
     * @return array{source: string, summary: string, items: array, ai_pending: bool, at: string, note: ?string}
     */
    public function analyze(Workspace $ws, User $u, string $topic, string $mode = 'fast', ?string $pid = null): array
    {
        $data = $this->facts($ws, $u, $topic, $pid);
        $rules = $data['rules'];
        $loc = app()->getLocale();
        $key = 'ins:'.$ws->id.':'.$topic.':'.md5(json_encode([$data['facts'], $loc, $u->allowedObjects(), $u->seesMoney()]));
        $base = ['source' => 'rules', 'summary' => $data['summary'], 'items' => $rules, 'ai_pending' => false, 'at' => $ws->localNow()->format('H:i'), 'note' => null];

        $cached = $mode === 'refresh' ? null : Cache::get($key);
        if ($cached) {
            return $cached + ['ai_pending' => false, 'note' => null];
        }
        $ready = $this->aiReady($ws, $mode !== 'refresh');
        if ($mode === 'fast' || ! $ready) {
            return array_merge($base, ['ai_pending' => $ready && $mode === 'fast', 'note' => $ready ? null : $this->whyNoAi($ws)]);
        }
        try {
            $r = $this->ask($ws, $u, $topic, $data);
        } catch (\Throwable $e) {
            Log::warning('Insights '.$topic.': '.$e->getMessage());

            return array_merge($base, ['note' => __('ИИ сейчас недоступен — показаны выводы по правилам.')]);
        }
        $res = ['source' => 'ai', 'summary' => $r['summary'] ?: $data['summary'], 'items' => $r['items'] ?: $rules, 'at' => $ws->localNow()->format('H:i')];
        Cache::put($key, $res, self::TTL);

        return $res + ['ai_pending' => false, 'note' => null];
    }

    private function whyNoAi(Workspace $ws): ?string
    {
        if (! $ws->aiAllowed()) {
            return __('Подробный разбор ИИ доступен на тарифе с ИИ — сейчас показаны выводы по правилам.');
        }
        if (! $this->ai->providerFor($ws)) {
            return null;   // ИИ не подключён на платформе — молча работаем по правилам
        }

        return __('Лимит запросов к ИИ на этот месяц исчерпан — показаны выводы по правилам.');
    }

    /* ------------------------------------------------------------------ ИИ */

    private function ask(Workspace $ws, User $u, string $topic, array $data): array
    {
        $lang = ['ru' => 'русском', 'uz' => 'узбекском (латиница, как в Узбекистане)', 'tg' => 'таджикском (кириллица)'][app()->getLocale()] ?? 'русском';
        $what = [
            'company' => 'общее состояние строительной компании за сегодня: деньги, долги, согласования, задачи, склад, продажи',
            'cash' => 'деньги в кассах и расходы по статьям за месяц',
            'forecast' => 'прогноз движения денег по неделям: будет ли кассовый разрыв и что с ним делать',
            'sales' => 'продажи квартир объекта: темп, оплаты покупателей, просрочки рассрочки, остаток квартир',
            'supply' => 'снабжение материалами: заявки, сроки, склад',
            'objects' => 'сравнение объектов: расходы, поступления, баланс, готовность, отставание',
        ][$topic];
        $system = "Ты — финансовый директор и опытный руководитель стройки. Анализируешь {$what}. "
            ."Используй ТОЛЬКО цифры из данных пользователя, ничего не выдумывай. Пиши на {$lang} языке, просто и по делу, для руководителя без финансового образования. "
            .'Суммы пиши коротко с валютой (например «12,5 млн», «1,2 млрд»). '
            .'Ответь ТОЛЬКО JSON без пояснений: {"summary":"1–2 предложения: главное","items":[{"level":"bad|warn|ok|info","title":"коротко, до 60 символов","text":"что видно по цифрам и что конкретно сделать, до 220 символов","link":"ссылка из списка или null"}]}. '
            .'От 2 до 5 пунктов, сначала самое срочное (bad), потом warn, в конце хорошее (ok). Ссылки только из списка: '.implode(', ', self::LINKS).'.';
        $msg = "Данные (валюта: {$data['currency']}, сегодня {$ws->localNow()->format('d.m.Y')}):\n".json_encode($data['facts'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nВыводы по правилам (можно уточнить и дополнить):\n".json_encode(array_map(fn ($i) => $i['title'].': '.$i['text'], $data['rules']), JSON_UNESCAPED_UNICODE);
        // запас токенов: у «думающих» моделей (Gemini 2.5) размышления входят в лимит ответа
        $r = $this->ai->ask($ws, $u, 'insight', $system, [['role' => 'user', 'content' => $msg]], null, 8000);
        if (! preg_match('/\{.*\}/s', $r['text'], $m) || ! is_array($j = json_decode($m[0], true))) {
            throw new AiException('bad json');
        }
        $items = [];
        foreach (array_slice((array) ($j['items'] ?? []), 0, 5) as $it) {
            $title = trim((string) ($it['title'] ?? ''));
            $text = trim((string) ($it['text'] ?? ''));
            if ($title === '' && $text === '') {
                continue;
            }
            $link = (string) ($it['link'] ?? '');
            $items[] = ['level' => in_array($it['level'] ?? '', ['bad', 'warn', 'ok', 'info'], true) ? $it['level'] : 'info',
                'title' => mb_substr($title, 0, 90), 'text' => mb_substr($text, 0, 400), 'href' => in_array(strtok($link, '?'), self::LINKS, true) ? $link : null];
        }

        return ['summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 400), 'items' => $items];
    }

    /* ------------------------------------------------------------------ цифры и правила */

    /** @return array{facts: array, rules: array, summary: string, currency: string} */
    public function facts(Workspace $ws, User $u, string $topic, ?string $pid = null): array
    {
        $cur = CompanyStats::currency($ws);
        $f = [];
        $R = [];
        $M = fn ($v) => money_s((float) $v, $cur);
        $only = $u->allowedObjects();
        $scope = fn ($q) => $only === null ? $q : $q->where(fn ($x) => $x->whereIn('project_id', $only)->orWhereNull('project_id'));
        $today = $ws->localNow();

        if (in_array($topic, ['company', 'cash', 'objects'], true) && $u->seesMoney()) {
            $s = CompanyStats::build($ws, null, $only);
            $prev = CompanyStats::build($ws, $today->copy()->subMonthNoOverflow()->toDateString(), $only)['totals'];
            $T = $s['totals'];
            $f['month'] = ['spent' => round($T['out_month']), 'spent_same_days_last_month' => round($prev['out_month']), 'income' => round($T['in_month']),
                'income_same_days_last_month' => round($prev['in_month']), 'spent_today' => round($T['out_day']), 'spent_yesterday' => round($T['out_yest'])];
            $f['top_categories_month'] = array_map('round', array_slice($s['cats_month'], 0, 6, true));
            if ($prev['out_month'] > 0 && $T['out_month'] > $prev['out_month'] * 1.25) {
                $R[] = $this->it('warn', __('Расходы растут быстрее прошлого месяца'), __('С начала месяца потрачено :a — на :p% больше, чем за те же дни прошлого месяца (:b).',
                    ['a' => $M($T['out_month']), 'p' => round(($T['out_month'] / $prev['out_month'] - 1) * 100), 'b' => $M($prev['out_month'])]), '/company/overview');
            }
            if ($topic === 'objects' || $topic === 'company') {
                $f['objects'] = array_map(fn ($o) => ['name' => $o['name'], 'spent_month' => round($o['out_month']), 'income_month' => round($o['in_month']),
                    'balance' => round($o['balance']), 'progress_pct' => $o['progress'], 'late_stages' => $o['overdue'], 'demo' => $o['demo']], array_slice($s['objects'], 0, 8));
                foreach ($s['objects'] as $o) {
                    if ($o['demo']) {
                        continue;
                    }
                    if ($o['overdue'] > 0) {
                        $R[] = $this->it('warn', __('«:o» отстаёт от графика', ['o' => $o['name']]), __('Просрочено этапов: :n. Проверьте график работ и людей на объекте.', ['n' => $o['overdue']]), '/company/overview');
                    }
                    if ($o['balance'] < 0) {
                        $R[] = $this->it('bad', __('«:o» тратит больше, чем получил', ['o' => $o['name']]), __('Баланс объекта :b. Расходы покрываются из других денег компании.', ['b' => $M($o['balance'])]), '/company/overview');
                    }
                }
            }
        }

        if (in_array($topic, ['company', 'cash'], true) && $u->seesMoney()) {
            $bal = Cash::balances($ws);
            $f['cash_total'] = round(Cash::totalBase($ws));
            $f['desks'] = [];
            foreach (\App\Models\CashDesk::of($ws->id)->where('is_active', true)->get() as $d) {
                $f['desks'][] = ['name' => __($d->name), 'currency' => $d->currency, 'balance' => round($bal[$d->id] ?? 0)];
                if (($bal[$d->id] ?? 0) < 0) {
                    $R[] = $this->it('bad', __('Касса «:d» в минусе', ['d' => __($d->name)]), __('Остаток :b — проверьте последние операции и переводы.', ['b' => Money::fmt($bal[$d->id], $d->currency)]), '/company/cash');
                }
            }
            foreach (Cash::limits($ws) as $l) {
                if ($l['limit'] > 0 && $l['pct'] >= 100) {
                    $R[] = $this->it('bad', __('Превышен лимит: :c', ['c' => __($l['cat']->name)]), __('Потрачено :s при лимите :l (:p%).', ['s' => $M($l['spent']), 'l' => $M($l['limit']), 'p' => $l['pct']]), '/company/cash');
                } elseif ($l['limit'] > 0 && $l['pct'] >= 85) {
                    $R[] = $this->it('warn', __('Лимит почти исчерпан: :c', ['c' => __($l['cat']->name)]), __('Потрачено :p% месячного лимита (:s из :l).', ['s' => $M($l['spent']), 'l' => $M($l['limit']), 'p' => $l['pct']]), '/company/cash');
                }
                if ($l['limit'] > 0) {
                    $f['limits'][] = ['category' => __($l['cat']->name), 'spent' => round($l['spent']), 'limit' => round($l['limit'])];
                }
            }
        }

        if (in_array($topic, ['company', 'forecast'], true) && $u->seesMoney() && $u->canModule('forecast')) {
            $fc = Cash::forecast($ws, $topic === 'forecast' ? 12 : 8);
            $f['forecast_weeks'] = array_map(fn ($r) => ['week_from' => $r['from']->format('d.m'), 'in' => round($r['in']), 'out' => round($r['out']), 'close' => round($r['close'])], $fc);
            $neg = collect($fc)->first(fn ($r) => $r['close'] < 0);
            if ($neg) {
                $R[] = $this->it('bad', __('Кассовый разрыв с :d', ['d' => $neg['from']->format('d.m')]), __('Не хватит :s. Перенесите часть оплат поставщикам или ускорьте платежи покупателей.', ['s' => $M(abs($neg['close']))]), '/company/forecast');
            } elseif ($fc) {
                $min = collect($fc)->min('close');
                $R[] = $this->it('ok', __('Кассового разрыва не видно'), __('Минимальный остаток в ближайшие недели — :s.', ['s' => $M($min)]), '/company/forecast');
            }
            if ($topic === 'forecast') {
                $big = collect($fc)->sortByDesc('out')->first();
                if ($big && $big['out'] > 0) {
                    $R[] = $this->it('info', __('Самая тяжёлая неделя — с :d', ['d' => $big['from']->format('d.m')]), __('К оплате :s: :w', ['s' => $M($big['out']), 'w' => implode('; ', array_slice($big['items_out'], 0, 3))]), '/company/payments');
                }
            }
        }

        if ($topic === 'company') {
            if ($u->canModule('payments')) {
                $pend = $scope(PaymentRequest::of($ws->id)->where('status', 'pending'))->get();
                $mine = $pend->filter(fn ($r) => \App\Services\Erp\Payments::canDecide($u, $r));
                $old = $mine->filter(fn ($r) => $r->created_at && $r->created_at->lt(now()->subDays(2)));
                $f['payments'] = ['pending_total' => $pend->count(), 'waiting_for_me' => $mine->count(), 'waiting_for_me_sum' => round($mine->sum(fn ($r) => Money::toBase($ws, $r->amount, $r->currency))),
                    'older_2_days' => $old->count(), 'approved_not_paid' => $scope(PaymentRequest::of($ws->id)->where('status', 'approved'))->count()];
                if ($old->count()) {
                    $R[] = $this->it('warn', __('Заявки на оплату ждут больше 2 дней'), __(':n заявок ждут вашего решения — поставщики и прорабы ждут денег.', ['n' => $old->count()]), '/company/payments');
                }
            }
            if ($u->isAdmin()) {
                $n = Expense::where('workspace_id', $ws->id)->where('status', 'pending')->count();
                $f['telegram_expenses_pending'] = $n;
                if ($n) {
                    $R[] = $this->it('warn', __('Расходы из Telegram ждут согласования'), __('Не согласовано: :n. Пока они не проведены, бухгалтерия объекта неполная.', ['n' => $n]), '/company/approvals');
                }
            }
            if ($u->canModule('tasks')) {
                $tq = $scope(Task::of($ws->id)->where('status', '!=', 'done'));
                $late = (clone $tq)->whereDate('due_date', '<', $today->toDateString())->count();
                $f['tasks'] = ['open' => (clone $tq)->count(), 'overdue' => $late, 'on_review' => (clone $tq)->where('status', 'review')->count()];
                if ($late) {
                    $R[] = $this->it($late >= 5 ? 'bad' : 'warn', __('Просроченные задачи: :n', ['n' => $late]), __('Поговорите с исполнителями и перенесите сроки или людей.'), '/company/tasks');
                }
            }
            if ($u->canModule('warehouse')) {
                $low = Stock::low($ws);
                $f['stock_low'] = array_map(fn ($r) => ['item' => $r['item']->name, 'qty' => $r['qty'], 'min' => (float) $r['item']->min_qty], array_slice($low, 0, 6));
                if ($low) {
                    $R[] = $this->it('warn', __('На складе заканчивается: :n поз.', ['n' => count($low)]), implode(', ', array_map(fn ($r) => $r['item']->name, array_slice($low, 0, 4))), '/company/stock');
                }
            }
            if ($u->seesMoney() && $u->canModule('contracts')) {
                $we = $us = 0;
                foreach ($scope(Contract::of($ws->id)->where('status', 'active'))->get() as $c) {
                    $left = Contracts::debtBase($ws, $c);
                    $c->direction === 'out' ? $we += max(0, $left) : $us += max(0, $left);
                }
                $f['debts'] = ['we_owe' => round($we), 'owed_to_us' => round($us)];
            }
        }

        if (in_array($topic, ['company', 'supply'], true) && $u->canModule('supply')) {
            $q = $scope(SupplyRequest::of($ws->id));
            $f['supply'] = (clone $q)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')->all();
            $stale = (clone $q)->where('status', 'new')->where('created_at', '<', now()->subDays(2))->count();
            $late = (clone $q)->whereIn('status', ['approved', 'ordered', 'partial'])->whereNotNull('need_by')->whereDate('need_by', '<', $today->toDateString())->count();
            $notOrdered = (clone $q)->where('status', 'approved')->where('updated_at', '<', now()->subDays(3))->count();
            $f['supply_problems'] = ['new_waiting_over_2_days' => $stale, 'past_need_date' => $late, 'approved_not_ordered_3_days' => $notOrdered];
            if ($stale) {
                $R[] = $this->it('warn', __('Заявки на материалы ждут согласования'), __(':n заявок ждут больше 2 дней — стройка может встать без материала.', ['n' => $stale]), '/company/supply?status=new');
            }
            if ($late) {
                $R[] = $this->it('bad', __('Материал не привезли к сроку'), __('Просрочено заявок: :n. Позвоните поставщикам и уточните даты.', ['n' => $late]), '/company/supply');
            }
            if ($notOrdered) {
                $R[] = $this->it('warn', __('Согласовано, но не заказано'), __(':n заявок согласованы больше 3 дней назад и до сих пор не заказаны у поставщика.', ['n' => $notOrdered]), '/company/supply?status=approved');
            }
            if ($topic === 'supply' && $u->canModule('warehouse')) {
                $low = Stock::low($ws);
                $f['stock_low'] = array_map(fn ($r) => ['item' => $r['item']->name, 'qty' => $r['qty'], 'min' => (float) $r['item']->min_qty], array_slice($low, 0, 8));
                if ($low) {
                    $R[] = $this->it('warn', __('На складе заканчивается: :n поз.', ['n' => count($low)]), implode(', ', array_map(fn ($r) => $r['item']->name, array_slice($low, 0, 5))), '/company/stock');
                }
            }
        }

        if (in_array($topic, ['company', 'sales'], true) && $u->canModule('sales')) {
            $projects = $ws->projects();
            $names = StateWriter::projects($ws, $only);
            $sum = ['flats' => 0, 'free' => 0, 'booked' => 0, 'sold' => 0, 'revenue' => 0, 'paid' => 0, 'overdue' => 0, 'overdue_deals' => 0, 'paid_this_month' => 0, 'paid_last_month' => 0, 'deals_last_30_days' => 0];
            $month = $today->format('Y-m');
            $lastMonth = $today->copy()->subMonthNoOverflow()->format('Y-m');
            $since30 = $today->copy()->subDays(30)->toDateString();
            $overdueTop = [];
            foreach ($projects as $p) {
                $id = (string) ($p['id'] ?? '');
                if (! isset($names[$id]) || ($pid && $pid !== $id) || Workspace::isDemo($p) && ! $pid) {
                    continue;
                }
                foreach (Apartments::list($p) as $a) {
                    $sum['flats']++;
                    $st = $a['status'];
                    $sum[$st === 'reserve' ? 'free' : $st] = ($sum[$st === 'reserve' ? 'free' : $st] ?? 0) + 1;
                    if (! in_array($st, ['sold', 'booked'], true)) {
                        continue;
                    }
                    $sum['revenue'] += (float) $a['price'];
                    $sum['paid'] += (float) $a['paid'];
                    // дата сделки не хранится — считаем по первому платежу покупателя
                    $first = collect((array) ($a['payments'] ?? []))->pluck('date')->filter()->min();
                    if ($first && $first >= $since30) {
                        $sum['deals_last_30_days']++;
                    }
                    foreach ((array) ($a['payments'] ?? []) as $x) {
                        $d = (string) ($x['date'] ?? '');
                        if (str_starts_with($d, $month)) {
                            $sum['paid_this_month'] += (float) ($x['amount'] ?? 0);
                        } elseif (str_starts_with($d, $lastMonth)) {
                            $sum['paid_last_month'] += (float) ($x['amount'] ?? 0);
                        }
                    }
                    $od = Apartments::overdue($a, $today->toDateString());
                    if ($od > 0) {
                        $sum['overdue'] += $od;
                        $sum['overdue_deals']++;
                        $overdueTop[] = ['object' => $p['name'] ?? '', 'apt' => apt_num($a['num']), 'buyer' => $a['buyer'], 'overdue' => round($od)];
                    }
                }
            }
            usort($overdueTop, fn ($x, $y) => $y['overdue'] <=> $x['overdue']);
            $f['sales'] = array_map(fn ($v) => is_float($v) ? round($v) : $v, $sum);
            $f['sales_overdue_top'] = array_slice($overdueTop, 0, 5);
            if ($sum['overdue_deals']) {
                $R[] = $this->it('bad', __('Покупатели просрочили оплату'), __(':n покупателей должны :s по графику рассрочки. Позвоните им и отправьте напоминание.', ['n' => $sum['overdue_deals'], 's' => $M($sum['overdue'])]), '/company/sales');
            }
            if ($sum['flats'] && $topic === 'sales') {
                $free = $sum['free'];
                $R[] = $this->it('info', __('Свободно :f из :t помещений', ['f' => $free, 't' => $sum['flats']]), __('За 30 дней новых сделок: :n. Оплат в этом месяце: :s.', ['n' => $sum['deals_last_30_days'], 's' => $M($sum['paid_this_month'])]), '/company/sales');
            }
            if ($sum['paid_last_month'] > 0 && $sum['paid_this_month'] >= $sum['paid_last_month']) {
                $R[] = $this->it('ok', __('Оплаты покупателей не хуже прошлого месяца'), __('В этом месяце :a, в прошлом :b.', ['a' => $M($sum['paid_this_month']), 'b' => $M($sum['paid_last_month'])]), '/company/sales');
            }
        }

        // сначала срочное
        $order = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($R, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
        $R = array_slice($R, 0, 6);
        if (! $R) {
            $R[] = $this->it('ok', __('Срочных проблем не видно'), __('Цифры раздела в норме. Загляните сюда завтра — разбор обновляется сам.'), null);
        }
        $bad = count(array_filter($R, fn ($i) => $i['level'] === 'bad'));
        $warn = count(array_filter($R, fn ($i) => $i['level'] === 'warn'));
        $summary = $bad ? trans_choice('{1}:n срочный вопрос|[2,4]:n срочных вопроса|[5,*]:n срочных вопросов', $bad, ['n' => $bad]).($warn ? ', '.trans_choice('{1}:n требует внимания|[2,*]:n требуют внимания', $warn, ['n' => $warn]) : '').'.'
            : ($warn ? trans_choice('{1}:n вопрос требует внимания|[2,4]:n вопроса требуют внимания|[5,*]:n вопросов требуют внимания', $warn, ['n' => $warn]).'.' : __('Всё под контролем.'));

        return ['facts' => $f, 'rules' => $R, 'summary' => $summary, 'currency' => $cur];
    }

    private function it(string $level, string $title, string $text, ?string $href): array
    {
        return ['level' => $level, 'title' => $title, 'text' => $text, 'href' => $href];
    }
}
