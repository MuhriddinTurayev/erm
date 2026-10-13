<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Подписка компании не активна — работа в программе закрыта, открыта только страница тарифа */
class Subscription
{
    public function handle(Request $request, Closure $next)
    {
        $ws = $request->user()?->workspace;
        if ($ws && ! $ws->hasAccess()) {
            $msg = $ws->state() === 'suspended'
                ? __('Доступ компании приостановлен. Свяжитесь с администрацией платформы.')
                : __('Срок подписки истёк. Продлите тариф, чтобы продолжить работу.');
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => $msg, 'billing' => true], 402);
            }

            return redirect('/company/billing')->withErrors(['plan' => $msg]);
        }

        return $next($request);
    }
}
