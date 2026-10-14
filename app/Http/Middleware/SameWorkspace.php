<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Программа /crm шлёт в каждом запросе заголовок X-Oftob-Ws — id компании, с которой она открыта.
 * Если в этом браузере после этого вошли в другую компанию (другой логин, «войти как» у владельца платформы),
 * старая вкладка не должна ничего записать в новую компанию: отвечаем 409, вкладка перезагружается.
 */
class SameWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        $ws = $request->header('X-Oftob-Ws');
        $u = $request->user();
        if ($ws !== null && $ws !== '' && $u && (int) $ws !== (int) $u->workspace_id) {
            return response()->json(['switched' => true, 'ws' => (int) $u->workspace_id, 'error' => __('Вы вошли в другую компанию. Страница обновится.')], 409);
        }

        return $next($request);
    }
}
