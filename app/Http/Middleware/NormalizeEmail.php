<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Поле email из любой формы — в нижнем регистре и без пробелов по краям ещё до проверки:
 * «Ali@Mail.uz» и «ali@mail.uz» — один и тот же адрес (проверка уникальности, вход, регистрация).
 */
class NormalizeEmail
{
    public function handle(Request $request, Closure $next)
    {
        foreach (['email', 'report_email', 'support_email'] as $k) {
            $v = $request->request->get($k);
            if (is_string($v) && str_contains($v, '@')) {
                $request->request->set($k, mb_strtolower(trim($v)));
            }
        }

        return $next($request);
    }
}
