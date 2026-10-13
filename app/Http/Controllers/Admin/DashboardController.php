<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Workspace;

/** Панель владельца платформы */
class DashboardController extends Controller
{
    public function index()
    {
        $all = Workspace::with('plan')->get();
        $by = $all->groupBy(fn ($w) => $w->state());
        $mrr = $all->filter(fn ($w) => $w->state() === 'active')->sum(fn ($w) => (float) ($w->plan?->price_month ?? 0));
        $month = now()->startOfMonth();

        // регистрации за 30 дней
        $reg = [];
        for ($i = 29; $i >= 0; $i--) {
            $reg[now()->subDays($i)->toDateString()] = 0;
        }
        foreach (Workspace::where('created_at', '>=', now()->subDays(29)->startOfDay())->pluck('created_at') as $c) {
            $k = $c->toDateString();
            if (isset($reg[$k])) {
                $reg[$k]++;
            }
        }

        return view('admin.dashboard', [
            'total' => $all->count(),
            'active' => $by->get('active', collect())->count(),
            'trial' => $by->get('trial', collect())->count(),
            'expired' => $by->get('expired', collect())->count() + $by->get('suspended', collect())->count(),
            'users' => User::whereNotNull('workspace_id')->count(),
            'mrr' => $mrr,
            'paidMonth' => SubscriptionPayment::where('paid_at', '>=', $month)->sum('amount'),
            'aiMonth' => AiUsage::where('created_at', '>=', $month)->where('ok', true)->count(),
            'aiErrors' => AiUsage::where('created_at', '>=', $month)->where('ok', false)->count(),
            'aiProviders' => AiProvider::where('is_active', true)->count(),
            'requests' => Workspace::with('plan')->whereNotNull('plan_request')->latest('updated_at')->get(),
            'endingSoon' => $all->filter(fn ($w) => in_array($w->state(), ['trial', 'active'], true) && $w->daysLeft() !== null && $w->daysLeft() <= 5)->sortBy(fn ($w) => $w->daysLeft()),
            'latest' => Workspace::with('plan')->latest()->limit(8)->get(),
            'reg' => $reg,
            'planSplit' => $all->groupBy(fn ($w) => $w->plan?->title() ?? '—')->map->count(),
        ]);
    }
}
