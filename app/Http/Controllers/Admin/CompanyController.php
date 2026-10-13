<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\Workspace;
use App\Services\CompanyStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Компании-клиенты: тариф, срок, оплаты, блокировка, вход от имени руководителя */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $state = (string) $request->query('state');
        $list = Workspace::with('plan')->withCount('users')
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")
                ->orWhereHas('users', fn ($u) => $u->where('email', 'like', "%{$q}%"))))
            ->latest()->get();
        if ($state !== '') {
            $list = $list->filter(fn ($w) => $w->state() === $state)->values();
        }

        return view('admin.companies', ['list' => $list, 'q' => $q, 'state' => $state, 'plans' => Plan::orderBy('sort')->get()]);
    }

    public function show(Workspace $workspace)
    {
        $workspace->load('plan');

        return view('admin.company', [
            'w' => $workspace,
            'plans' => Plan::orderBy('sort')->get(),
            'users' => $workspace->users()->orderBy('name')->get(),
            'payments' => $workspace->payments()->with('plan')->latest('paid_at')->get(),
            's' => CompanyStats::build($workspace),
            'ai' => AiUsage::where('workspace_id', $workspace->id)->where('created_at', '>=', now()->startOfMonth())->where('ok', true)->count(),
            'reports' => $workspace->reports()->orderByDesc('date')->limit(7)->get(),
        ]);
    }

    public function update(Request $request, Workspace $workspace)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'plan_id' => ['nullable', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::in(['trial', 'active', 'suspended'])],
            'trial_ends_at' => ['nullable', 'date'],
            'paid_until' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'clear_request' => ['nullable', 'boolean'],
        ]);
        foreach (['trial_ends_at', 'paid_until'] as $k) {
            $data[$k] = ! empty($data[$k]) ? \Illuminate\Support\Carbon::parse($data[$k])->endOfDay() : null;
        }
        if ($request->boolean('clear_request')) {
            $data['plan_request'] = null;
        }
        unset($data['clear_request']);
        $workspace->update($data);

        return back()->with('ok', __('Сохранено'));
    }

    /** Оплата: записываем и продлеваем доступ */
    public function pay(Request $request, Workspace $workspace)
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', Rule::in(array_keys(SubscriptionPayment::METHODS))],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);
        $plan = Plan::findOrFail($data['plan_id']);
        SubscriptionPayment::create($data + ['workspace_id' => $workspace->id, 'currency' => $plan->currency, 'created_by' => $request->user()->id]);
        $workspace->plan_id = $plan->id;
        $workspace->plan_request = null;
        $workspace->extend((int) $data['months']);

        return back()->with('ok', __('Оплата записана. Доступ до :d', ['d' => $workspace->paid_until->format('d.m.Y')]));
    }

    public function impersonate(Request $request, Workspace $workspace)
    {
        $admin = $workspace->users()->where('role', 'admin')->orderBy('id')->first() ?? $workspace->users()->orderBy('id')->first();
        if (! $admin) {
            return back()->withErrors(['w' => __('В компании нет пользователей')]);
        }
        $me = $request->user()->id;
        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->put('impersonator', $me);

        return redirect('/crm');
    }

    public function destroy(Request $request, Workspace $workspace)
    {
        $request->validate(['confirm' => ['required', 'in:'.$workspace->name]], ['confirm.in' => __('Введите точное название компании для подтверждения')]);
        $workspace->users()->delete();
        $workspace->delete();

        return redirect('/admin/companies')->with('ok', __('Компания удалена'));
    }
}
