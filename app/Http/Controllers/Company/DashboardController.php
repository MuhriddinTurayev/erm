<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\CompanyStats;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Сводка руководителю: деньги, расходы сегодня и вчера, на что уходят, объекты */
    public function overview(Request $request)
    {
        $ws = $this->ws();
        $date = $request->query('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $s = CompanyStats::build($ws, $date, $request->user()->allowedObjects());

        return view('company.overview', ['ws' => $ws, 's' => $s]);
    }

    /** Тариф и оплата */
    public function billing()
    {
        $ws = $this->ws()->load('plan');

        return view('company.billing', [
            'ws' => $ws,
            'plans' => Plan::active()->get(),
            'objects' => \App\Models\Workspace::countObjects($ws->projects()),
            'users' => $ws->users()->count(),
            'aiUsed' => $ws->aiUsedThisMonth(),
            'payments' => $ws->payments()->latest('paid_at')->limit(20)->get(),
            'support' => ['phone' => PlatformSetting::get('support_phone'), 'telegram' => PlatformSetting::get('support_telegram'), 'email' => PlatformSetting::get('support_email')],
        ]);
    }

    /** Заявка на смену тарифа — увидит супер-админ */
    public function requestPlan(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $plan = Plan::active()->where('slug', $request->input('plan'))->firstOrFail();
        $months = max(1, min(24, (int) $request->input('months', 1)));
        $ws = $this->ws();
        $ws->plan_request = $plan->slug.':'.$months;
        $ws->save();

        return back()->with('ok', __('Заявка отправлена. Мы свяжемся с вами для оплаты тарифа «:p».', ['p' => $plan->title()]));
    }
}
