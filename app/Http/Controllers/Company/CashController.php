<?php

namespace App\Http\Controllers\Company;

use App\Models\CashDesk;
use App\Models\CashOp;
use App\Models\Contract;
use App\Models\FinCategory;
use App\Services\Erp\Cash;
use App\Services\Erp\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Кассы и счета: остатки, приход/расход, переводы и обмен валюты, зарплата, статьи и лимиты */
class CashController extends ErpController
{
    public function index(Request $request)
    {
        $ws = $this->ws();
        Cash::ensureDefaults($ws);
        $desks = CashDesk::of($ws->id)->where('is_active', true)->orderBy('id')->get();
        $f = $request->only(['desk', 'type', 'cat', 'project', 'from', 'to', 'q']);
        $ops = CashOp::of($ws->id)->with(['desk', 'category', 'counterparty', 'user', 'contract'])
            ->when($f['desk'] ?? null, fn ($q, $v) => $q->where('cash_desk_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $v === 'transfer' ? $q->whereNotNull('transfer_key') : $q->where('type', $v)->whereNull('transfer_key'))
            ->when($f['cat'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['project'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('note', 'like', "%$v%")->orWhere('employee', 'like', "%$v%")))
            ->tap(fn ($q) => $this->scopeProjects($q))
            ->latest('date')->latest('id')->paginate(50)->withQueryString();
        $today = $ws->localNow()->toDateString();
        $day = CashOp::of($ws->id)->whereNull('transfer_key')->where('date', $today)->selectRaw("sum(case when type='in' then amount_base else 0 end) i, sum(case when type='out' then amount_base else 0 end) o")->first();

        return view('company.erp.cash', [
            'ws' => $ws, 'desks' => $desks, 'balances' => Cash::balances($ws), 'total' => Cash::totalBase($ws), 'ops' => $ops, 'f' => $f,
            'cats' => FinCategory::of($ws->id)->where('is_active', true)->orderBy('sort')->get(), 'projects' => $this->projects(),
            'counterparties' => $this->counterparties(), 'contracts' => Contract::of($ws->id)->where('status', 'active')->with('counterparty')->get(),
            'base' => Money::base($ws), 'rate' => Money::usdRate($ws), 'cbRate' => Money::cbRate(Money::base($ws)), 'day' => $day,
            'limits' => Cash::limits($ws), 'staff' => $this->staff(),
        ]);
    }

    public function store(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate([
            'cash_desk_id' => ['required', Rule::exists('cash_desks', 'id')->where('workspace_id', $ws->id)],
            'type' => ['required', Rule::in(['in', 'out'])], 'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'date' => ['required', 'date'], 'category_id' => ['nullable', Rule::exists('fin_categories', 'id')->where('workspace_id', $ws->id)],
            'project_id' => ['nullable', 'string', 'max:40'], 'counterparty_id' => ['nullable', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)],
            'contract_id' => ['nullable', Rule::exists('contracts', 'id')->where('workspace_id', $ws->id)],
            'employee' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:300'], 'rate' => ['nullable', 'numeric', 'min:0'],
        ]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        if ($request->input('payroll')) {
            $d['category_id'] = $d['category_id'] ?? FinCategory::of($ws->id)->where('name', 'Зарплата')->value('id');
            $request->validate(['employee' => ['required']]);
        }
        // крупный расход от не-руководителя — через заявку на оплату
        $desk = CashDesk::find($d['cash_desk_id']);
        $base = Money::toBase($ws, (float) $d['amount'], $desk->currency);
        if ($d['type'] === 'out' && ! $u->isAdmin() && $ws->approval_limit > 0 && $base >= $ws->approval_limit) {
            return back()->withInput()->withErrors(['amount' => __('Сумма больше лимита согласования (:s). Оформите заявку на оплату.', ['s' => money_s($ws->approval_limit, \App\Services\CompanyStats::currency($ws))])]);
        }
        if ($d['type'] === 'out' && ($ov = Cash::overLimit($ws, $d['category_id'] ?? null, $base)) && ! $u->isAdmin()) {
            return back()->withInput()->withErrors(['amount' => __('Превышен месячный лимит статьи «:c»: лимит :l, уже потрачено :s.', ['c' => __($ov[2]), 'l' => number_format($ov[0], 0, '.', ' '), 's' => number_format($ov[1], 0, '.', ' ')])]);
        }
        Cash::add($ws, $u, $d);

        return back()->with('ok', __('Операция проведена'));
    }

    public function transfer(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate([
            'from' => ['required', Rule::exists('cash_desks', 'id')->where('workspace_id', $ws->id)],
            'to' => ['required', 'different:from', Rule::exists('cash_desks', 'id')->where('workspace_id', $ws->id)],
            'amount' => ['required', 'numeric', 'min:0.01'], 'rate' => ['nullable', 'numeric', 'min:0.0001'], 'note' => ['nullable', 'string', 'max:300'], 'date' => ['required', 'date'],
        ]);
        $bal = Cash::balances($ws)[$d['from']] ?? 0;
        if ($d['amount'] > $bal + 0.001) {
            return back()->withInput()->withErrors(['amount' => __('В кассе только :s', ['s' => Money::fmt($bal, CashDesk::find($d['from'])->currency)])]);
        }
        Cash::transfer($ws, $u, (int) $d['from'], (int) $d['to'], (float) $d['amount'], isset($d['rate']) ? (float) $d['rate'] : null, $d['note'] ?? null, $d['date']);

        return back()->with('ok', __('Перевод выполнен'));
    }

    public function destroy(CashOp $op)
    {
        $this->mine($op);
        abort_unless(auth()->user()->isAdmin() || ($op->user_id === auth()->id() && $op->created_at->gt(now()->subDay())), 403, __('Удалять старые операции может только руководитель'));
        Cash::delete($this->ws(), auth()->user(), $op);

        return back()->with('ok', __('Операция удалена'));
    }

    /* ---------- настройка: кассы, статьи, курс (руководитель) ---------- */

    public function desks(Request $request)
    {
        $ws = $this->ws();
        $d = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'kind' => ['required', Rule::in(['cash', 'bank', 'card'])],
            'currency' => ['required', Rule::in(Money::currencies($ws))], 'project_id' => ['nullable', 'string', 'max:40'], 'keeper_id' => ['nullable', 'integer'],
            'opening' => ['nullable', 'numeric']]);
        $d['opening'] = (float) ($d['opening'] ?? 0);
        $d['is_active'] = ! $request->has('archive');
        if (! empty($d['id'])) {
            $desk = CashDesk::of($ws->id)->findOrFail($d['id']);
            unset($d['currency']);   // валюту кассы с операциями менять нельзя
            $desk->update($d);
        } else {
            CashDesk::create($d + ['workspace_id' => $ws->id]);
        }

        return back()->with('ok', __('Сохранено'));
    }

    public function categories(Request $request)
    {
        $ws = $this->ws();
        $rows = (array) $request->input('cats', []);
        foreach ($rows as $id => $r) {
            $c = FinCategory::of($ws->id)->find($id);
            if ($c && ! empty($r['name'])) {
                $c->update(['name' => mb_substr($r['name'], 0, 80), 'month_limit' => max(0, (float) ($r['limit'] ?? 0)), 'is_active' => ! empty($r['on'])]);
            }
        }
        if ($request->filled('new_name')) {
            FinCategory::create(['workspace_id' => $ws->id, 'name' => mb_substr($request->input('new_name'), 0, 80), 'kind' => $request->input('new_kind') === 'in' ? 'in' : 'out',
                'month_limit' => max(0, (float) $request->input('new_limit')), 'sort' => 100]);
        }

        return back()->with('ok', __('Сохранено'));
    }

    public function rate(Request $request)
    {
        $d = $request->validate(['usd_rate' => ['nullable', 'numeric', 'min:0']]);
        $this->ws()->update(['usd_rate' => (float) ($d['usd_rate'] ?? 0)]);

        return back()->with('ok', __('Курс сохранён'));
    }
}
