<?php

namespace App\Http\Controllers\Company;

use App\Models\CashDesk;
use App\Models\Contract;
use App\Models\PaymentRequest;
use App\Models\SiteLog;
use App\Models\SupplyRequest;
use App\Models\Task;
use App\Services\CompanyStats;
use App\Services\Erp\Cash;
use App\Services\Erp\Money;
use App\Services\Erp\Payments;
use App\Services\Erp\Stock;
use App\Services\StateWriter;
use Illuminate\Http\Request;

/** Мониторинг руководителя (настраиваемые виджеты), прогноз денег, фотоотчёты с геометкой */
class MonitorController extends ErpController
{
    public const WIDGETS = [
        'ai' => ['ИИ-анализ', 'sparkles'],
        'money' => ['Деньги в кассах', 'wallet'],
        'today' => ['Сегодня по объектам', 'calendar'],
        'approvals' => ['Ждут моего решения', 'file-check'],
        'forecast' => ['Прогноз денег на 8 недель', 'trending-up'],
        'limits' => ['Лимиты по статьям', 'gauge'],
        'projects' => ['Готовность объектов', 'building-2'],
        'tasks' => ['Задачи', 'list-checks'],
        'stock' => ['Склад: заканчивается', 'package'],
        'supply' => ['Снабжение', 'truck'],
        'debts' => ['Долги по договорам', 'scale'],
        'photos' => ['Последние фотоотчёты', 'camera'],
    ];

    private function widgets(): array
    {
        // widgets2 — выбор после появления «ИИ-анализа»; старый выбор (widgets) дополняем им
        $saved = $this->ws()->setting('widgets2.'.auth()->id());
        if (! is_array($saved) && is_array($old = $this->ws()->setting('widgets.'.auth()->id()))) {
            $saved = array_merge(['ai'], $old);
        }

        return is_array($saved) ? array_values(array_intersect($saved, array_keys(self::WIDGETS))) : array_keys(self::WIDGETS);
    }

    public function index(Request $request)
    {
        $ws = $this->ws();
        Cash::ensureDefaults($ws);
        $u = $request->user();
        $on = $this->widgets();
        $w = [];
        $base = Money::base($ws);
        $cur = CompanyStats::currency($ws);
        $money = $u->seesMoney();

        if (in_array('money', $on) && $money) {
            $bal = Cash::balances($ws);
            $w['money'] = ['total' => Cash::totalBase($ws), 'desks' => CashDesk::of($ws->id)->where('is_active', true)->get()->map(fn ($d) => ['d' => $d, 'b' => $bal[$d->id] ?? 0]), 'rate' => Money::usdRate($ws)];
        }
        // показатели сверху: деньги к прошлому периоду, что ждёт решения
        $stats = $money ? CompanyStats::build($ws, null, $u->allowedObjects()) : null;
        $kpi = $this->kpi($ws, $u, $stats);
        if (in_array('today', $on) && $money) {
            $w['today'] = $stats;
        }
        if (in_array('approvals', $on)) {
            $prs = PaymentRequest::of($ws->id)->where('status', 'pending')->with('user')->latest('id')->get()->filter(fn ($r) => Payments::canDecide($u, $r));
            $w['approvals'] = ['list' => $prs->take(6), 'n' => $prs->count(), 'toPay' => PaymentRequest::of($ws->id)->where('status', 'approved')->tap(fn ($q) => $this->scopeProjects($q))->count(),
                'exp' => \App\Models\Expense::where('workspace_id', $ws->id)->where('status', 'pending')->when($u->allowedObjects() !== null, fn ($q) => $q->whereIn('project_id', $u->allowedObjects()))->count()];
        }
        if (in_array('forecast', $on) && $money) {
            $w['forecast'] = Cash::forecast($ws, 8);
        }
        if (in_array('limits', $on) && $money) {
            $w['limits'] = array_values(array_filter(Cash::limits($ws), fn ($r) => $r['limit'] > 0 || $r['spent'] > 0));
        }
        if (in_array('projects', $on)) {
            $w['projects'] = collect($ws->projects())->filter(fn ($p) => $u->allowedObjects() === null || in_array((string) $p['id'], $u->allowedObjects(), true))
                ->map(function ($p) {
                    $t = $s = 0;
                    $late = 0;
                    foreach ((array) ($p['stages'] ?? []) as $st) {
                        $d = max(1, (int) ($st['dur'] ?? 1));
                        $t += $d;
                        $s += $d * (float) ($st['progress'] ?? 0);
                    }

                    return ['name' => $p['name'] ?? '', 'pct' => $t ? round($s / $t) : 0, 'status' => $p['status'] ?? '', 'demo' => \App\Models\Workspace::isDemo($p)];
                })->values();
        }
        if (in_array('tasks', $on)) {
            $tq = Task::of($ws->id)->where('status', '!=', 'done')->tap(fn ($q) => $this->scopeProjects($q));
            $w['tasks'] = ['open' => (clone $tq)->count(), 'overdue' => (clone $tq)->whereDate('due_date', '<', now())->count(), 'review' => (clone $tq)->where('status', 'review')->count(),
                'mine' => (clone $tq)->where('assignee_id', $u->id)->with('assignee')->orderBy('due_date')->limit(6)->get()];
        }
        if (in_array('stock', $on)) {
            $w['stock'] = Stock::low($ws);
        }
        if (in_array('supply', $on)) {
            $w['supply'] = SupplyRequest::of($ws->id)->whereIn('status', ['new', 'approved', 'ordered', 'partial'])->tap(fn ($q) => $this->scopeProjects($q))->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');
        }
        if (in_array('debts', $on) && $money) {
            $rows = [];
            foreach (Contract::of($ws->id)->where('status', 'active')->tap(fn ($q) => $this->scopeProjects($q))->with('counterparty')->get() as $c) {
                $left = \App\Services\Erp\Contracts::debtBase($ws, $c);
                if ($left > 0) {
                    $rows[] = ['c' => $c, 'left' => $left];
                }
            }
            usort($rows, fn ($a, $b) => $b['left'] <=> $a['left']);
            $w['debts'] = ['we' => array_sum(array_map(fn ($r) => $r['c']->direction === 'out' ? $r['left'] : 0, $rows)),
                'us' => array_sum(array_map(fn ($r) => $r['c']->direction === 'in' ? $r['left'] : 0, $rows)), 'top' => array_slice($rows, 0, 6),
                'inter' => Cash::interProject($ws)];
        }
        if (in_array('photos', $on)) {
            $w['photos'] = SiteLog::with('user')->where('workspace_id', $ws->id)->whereNotNull('photos')->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->limit(8)->get();
        }

        return view('company.erp.monitor', ['ws' => $ws, 'w' => $w, 'kpi' => $kpi, 'money' => $money, 'on' => $on, 'cur' => $cur, 'base' => $base, 'projects' => StateWriter::projects($ws)]);
    }

    /**
     * Четыре показателя над панелью. С деньгами: в кассах (к неделе назад), потрачено и поступило за месяц (к тем же дням прошлого месяца),
     * ждут решения. Без денег: задачи, просрочки, снабжение, готовность объектов.
     */
    private function kpi($ws, $u, ?array $stats): array
    {
        $only = $u->allowedObjects();
        $decide = 0;
        if ($u->canModule('payments')) {
            $decide += PaymentRequest::of($ws->id)->where('status', 'pending')->get()->filter(fn ($r) => Payments::canDecide($u, $r))->count();
        }
        if ($u->isAdmin()) {
            $decide += \App\Models\Expense::where('workspace_id', $ws->id)->where('status', 'pending')->count();
        }
        if ($u->canModule('supply') && \App\Services\Erp\Supply::canDecide($u)) {
            $decide += SupplyRequest::of($ws->id)->where('status', 'new')->count();
        }
        if ($stats) {
            $prev = CompanyStats::build($ws, $ws->localNow()->subMonthNoOverflow()->toDateString(), $only)['totals'];
            $total = Cash::totalBase($ws);
            $net7 = (float) \App\Models\CashOp::of($ws->id)->whereNull('transfer_key')->where('date', '>=', $ws->localNow()->subDays(6)->toDateString())
                ->selectRaw("sum(case when type = 'in' then amount_base else -amount_base end) s")->value('s');

            return ['money' => true, 'cash' => $total, 'cash_prev' => $total - $net7, 'out' => $stats['totals']['out_month'], 'out_prev' => $prev['out_month'],
                'in' => $stats['totals']['in_month'], 'in_prev' => $prev['in_month'], 'decide' => $decide];
        }
        $tq = Task::of($ws->id)->where('status', '!=', 'done')->tap(fn ($q) => $this->scopeProjects($q));
        $prog = collect($ws->projects())->filter(fn ($p) => $only === null || in_array((string) ($p['id'] ?? ''), $only, true))
            ->map(fn ($p) => ObjectController::summary($p)['progress']);

        return ['money' => false, 'tasks' => (clone $tq)->count(), 'overdue' => (clone $tq)->whereDate('due_date', '<', now())->count(),
            'supply' => SupplyRequest::of($ws->id)->whereIn('status', ['new', 'approved', 'ordered', 'partial'])->tap(fn ($q) => $this->scopeProjects($q))->count(),
            'progress' => $prog->count() ? round($prog->avg()) : 0, 'decide' => $decide];
    }

    public function saveWidgets(Request $request)
    {
        $list = array_values(array_intersect((array) $request->input('w', []), array_keys(self::WIDGETS)));
        $this->ws()->putSetting('widgets2.'.$request->user()->id, $list);

        return back()->with('ok', __('Панель настроена'));
    }

    public function forecast()
    {
        $ws = $this->ws();
        Cash::ensureDefaults($ws);

        return view('company.erp.forecast', ['ws' => $ws, 'rows' => Cash::forecast($ws, 12), 'inter' => Cash::interProject($ws), 'limits' => Cash::limits($ws),
            'cur' => CompanyStats::currency($ws), 'total' => Cash::totalBase($ws)]);
    }

    /** Фотоотчёт с объекта: фото + геометка + время съёмки (с телефона прямо из браузера) */
    public function photo(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate(['project_id' => ['required', 'string', 'max:40'], 'text' => ['nullable', 'string', 'max:2000'],
            'photos' => ['required', 'array', 'max:10'], 'photos.*' => ['image', 'max:15360'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'numeric', 'min:0']]);
        $this->checkProject($d['project_id']);
        $paths = [];
        foreach ($request->file('photos') as $f) {
            $paths[] = $f->store('logs/'.$ws->id.'/'.now()->format('Y-m'), 'local');
        }
        SiteLog::create(['workspace_id' => $ws->id, 'project_id' => $d['project_id'], 'user_id' => $u->id, 'date' => $ws->localNow()->toDateString(),
            'text' => $d['text'] ?? null, 'photos' => $paths, 'source' => 'web', 'lat' => $d['lat'] ?? null, 'lng' => $d['lng'] ?? null,
            'accuracy' => isset($d['accuracy']) ? (int) $d['accuracy'] : null, 'taken_at' => now()]);

        return back()->with('ok', __('Фотоотчёт сохранён'));
    }
}
