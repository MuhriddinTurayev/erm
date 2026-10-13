<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** perm:companies — право администратора платформы на раздел; perm:owner — только владелец */
class AdminPerm
{
    public function handle(Request $request, Closure $next, string $perm)
    {
        $u = $request->user();
        $ok = $perm === 'owner' ? $u?->isOwner() : $u?->adminCan($perm);
        abort_unless($ok, 403, __('Нет доступа к этому разделу'));

        return $next($request);
    }
}
