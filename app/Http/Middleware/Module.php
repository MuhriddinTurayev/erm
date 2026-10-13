<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** module:cash — доступ к разделу ERP по правам роли в компании */
class Module
{
    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless($request->user()?->canModule($module), 403, __('Нет доступа к этому разделу'));

        return $next($request);
    }
}
