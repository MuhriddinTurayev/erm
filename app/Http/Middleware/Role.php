<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * role:superadmin — только владелец платформы
 * role:company    — любой сотрудник компании (активный)
 * role:admin      — руководитель компании
 * role:money      — те, кто видит деньги (руководитель, бухгалтер, кассир, просмотр)
 */
class Role
{
    public function handle(Request $request, Closure $next, string $need)
    {
        $u = $request->user();
        if (! $u) {
            return redirect('/login');
        }
        if ($u->is_active === false) {
            auth()->logout();

            return redirect('/login')->withErrors(['email' => __('Ваш доступ отключён. Обратитесь к руководителю.')]);
        }

        $ok = match ($need) {
            'superadmin' => $u->isSuperAdmin(),
            'company' => $u->workspace_id !== null,
            'admin' => $u->workspace_id !== null && $u->isAdmin(),
            'money' => $u->workspace_id !== null && $u->seesMoney(),
            default => false,
        };

        if (! $ok) {
            if ($u->isSuperAdmin() && $need !== 'superadmin') {
                return redirect('/admin');
            }
            abort(403, __('Нет доступа к этому разделу'));
        }

        return $next($request);
    }
}
