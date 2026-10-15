<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Форма уходит один раз. Каждая отправка несёт одноразовый номер `_once` (ставит assets/dialog.js);
 * тот же номер второй раз (двойное нажатие, повтор запроса браузером) не выполняется — ни оплаты, ни расхода дважды.
 * Если первая попытка упала с ошибкой сервера — номер освобождается, можно отправить снова.
 */
class OnceSubmit
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->input('_once');
        if ($request->isMethod('GET') || $request->isMethod('HEAD') || ! is_string($token) || ! preg_match('/^[A-Za-z0-9\-]{16,64}$/', $token)) {
            return $next($request);
        }
        $key = 'once:'.($request->user()?->id ?? $request->session()->getId()).':'.$token;
        if (! Cache::add($key, 1, now()->addHour())) {
            $msg = __('Это действие уже выполнено — повторное нажатие не учтено.');

            return $request->expectsJson() ? response()->json(['error' => $msg, 'duplicate' => true], 409) : back()->with('warn', $msg);
        }
        $res = $next($request);
        if ($res->getStatusCode() >= 500 || $res->getStatusCode() === 419) {
            Cache::forget($key);   // не выполнилось — повторить можно
        }

        return $res;
    }
}
