<?php

namespace App\Http\Controllers\Company;

use App\Models\CashDesk;
use App\Models\Contract;
use App\Models\FinCategory;
use App\Models\PaymentRequest;
use App\Services\Erp\Cash;
use App\Services\Erp\Money;
use App\Services\Erp\Payments;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Заявки на оплату и цепочка согласования */
class PaymentController extends ErpController
{
    public function index(Request $request)
    {
        $ws = $this->ws();
        Cash::ensureDefaults($ws);
        $u = $request->user();
        $tab = $request->query('tab', 'mine');
        $q = PaymentRequest::of($ws->id)->with(['user', 'counterparty', 'category'])->tap(fn ($x) => $this->scopeProjects($x));
        $all = (clone $q)->latest('id')->limit(400)->get();
        $forMe = $all->filter(fn ($r) => Payments::canDecide($u, $r));
        $list = match ($tab) {
            'mine' => $forMe->isNotEmpty() || ! $u->canEdit() ? $forMe : $all->where('status', 'pending'),
            'approved' => $all->where('status', 'approved'),
            'paid' => $all->where('status', 'paid'),
            'rejected' => $all->whereIn('status', ['rejected', 'cancelled']),
            default => $all,
        };

        return view('company.erp.payments', [
            'ws' => $ws, 'list' => $list, 'tab' => $tab, 'forMe' => $forMe->count(), 'counts' => $all->countBy('status'),
            'projects' => $this->projects(), 'counterparties' => $this->counterparties(),
            'cats' => FinCategory::of($ws->id)->where('kind', 'out')->where('is_active', true)->orderBy('sort')->get(),
            'contracts' => Contract::of($ws->id)->where('status', 'active')->where('direction', 'out')->with('counterparty')->get(),
            'chain' => $ws->approvalChain(), 'currencies' => Money::currencies($ws), 'desks' => CashDesk::of($ws->id)->where('is_active', true)->get(),
        ]);
    }

    public function show(PaymentRequest $pr)
    {
        $this->mine($pr);
        if ($pr->project_id) {
            $this->checkProject($pr->project_id);
        }
        $ws = $this->ws();

        return view('company.erp.payment', [
            'ws' => $ws, 'pr' => $pr->load(['approvals.user', 'user', 'counterparty', 'contract', 'category', 'cashOp.desk']),
            'canDecide' => Payments::canDecide(auth()->user(), $pr), 'projects' => $this->projects(),
            'desks' => CashDesk::of($ws->id)->where('is_active', true)->get(), 'balances' => Cash::balances($ws),
            'over' => $pr->status === 'pending' ? Cash::overLimit($ws, $pr->category_id, Money::toBase($ws, $pr->amount, $pr->currency)) : null,
        ] + $this->thread('payment', $pr->id));
    }

    public function store(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'], 'currency' => ['required', Rule::in(Money::currencies($ws))], 'purpose' => ['required', 'string', 'max:300'],
            'project_id' => ['nullable', 'string', 'max:40'], 'counterparty_id' => ['nullable', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)],
            'contract_id' => ['nullable', Rule::exists('contracts', 'id')->where('workspace_id', $ws->id)],
            'category_id' => ['nullable', Rule::exists('fin_categories', 'id')->where('workspace_id', $ws->id)], 'due_date' => ['nullable', 'date'],
        ]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        try {
            $pr = Payments::create($ws, $u, $d);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['counterparty_id' => $e->getMessage()]);
        }

        return redirect('/company/payments/'.$pr->id)->with('ok', $pr->status === 'approved' ? __('Заявка утверждена') : __('Заявка отправлена на согласование'));
    }

    public function decide(Request $request, PaymentRequest $pr)
    {
        $this->mine($pr);
        $ok = Payments::decide($pr, $request->user(), $request->input('action') === 'approve', $request->input('comment'));

        return back()->with($ok ? 'ok' : 'warn', $ok ? __('Решение сохранено') : __('Сейчас решение по заявке принимает другой сотрудник'));
    }

    /** Массовое согласование отмеченных заявок */
    public function bulk(Request $request)
    {
        $n = 0;
        foreach (PaymentRequest::of($this->ws()->id)->whereIn('id', (array) $request->input('ids', []))->get() as $pr) {
            $n += Payments::decide($pr, $request->user(), $request->input('action') === 'approve') ? 1 : 0;
        }

        return back()->with('ok', __('Обработано заявок: :n', ['n' => $n]));
    }

    public function pay(Request $request, PaymentRequest $pr)
    {
        $this->mine($pr);
        $u = $this->editor();
        abort_unless($u->isAdmin() || in_array($u->role, ['accountant', 'cashier'], true), 403);
        $ws = $this->ws();
        $d = $request->validate(['cash_desk_id' => ['required', Rule::exists('cash_desks', 'id')->where('workspace_id', $ws->id)], 'date' => ['required', 'date']]);
        if ($pid = CashDesk::of($ws->id)->whereKey($d['cash_desk_id'])->value('project_id')) {
            $this->checkProject($pid);   // касса объекта — только для его сотрудников
        }
        try {
            Payments::pay($ws, $u, $pr, (int) $d['cash_desk_id'], $d['date']);
        } catch (\DomainException $e) {
            return back()->withErrors(['cash_desk_id' => $e->getMessage()]);
        }

        return back()->with('ok', __('Оплачено'));
    }

    public function cancel(PaymentRequest $pr)
    {
        $this->mine($pr);
        $ok = \Illuminate\Support\Facades\DB::transaction(function () use ($pr) {
            $fresh = PaymentRequest::whereKey($pr->id)->lockForUpdate()->first();
            abort_unless($fresh && (auth()->user()->isAdmin() || $fresh->user_id === auth()->id()), 403);
            if (! in_array($fresh->status, ['pending', 'approved'], true)) {
                return false;   // уже оплачена или решена — отменять нечего
            }
            $fresh->update(['status' => 'cancelled']);

            return true;
        });

        return back()->with($ok ? 'ok' : 'warn', $ok ? __('Заявка отменена') : __('Заявка уже оплачена или отклонена'));
    }

    /** Настройка цепочки согласования (руководитель) */
    public function chain(Request $request)
    {
        $roles = array_values(array_filter((array) $request->input('chain', []), fn ($r) => array_key_exists($r, config('oftob.roles')) && $r !== 'viewer'));
        $this->ws()->putSetting('chain', array_values(array_unique($roles)) ?: ['admin']);

        return back()->with('ok', __('Цепочка согласования сохранена'));
    }
}
