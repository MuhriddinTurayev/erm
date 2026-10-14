<?php

namespace App\Http\Controllers\Company;

use App\Services\Ai\Insights;
use Illuminate\Http\Request;

/** ИИ-анализ раздела (карточка «ИИ-анализ» на мониторинге, в кассах, прогнозе, продажах, снабжении, сводке) */
class InsightController extends ErpController
{
    public function show(Request $request, string $topic, Insights $insights)
    {
        $u = $request->user();
        abort_unless(Insights::allowed($u, $topic), 403);
        $pid = $request->query('o');
        $pid = is_string($pid) && $pid !== '' ? $this->checkProject($pid) : null;
        $mode = in_array($request->query('mode'), ['fast', 'ai', 'refresh'], true) ? $request->query('mode') : 'fast';

        return response()->json($insights->analyze($this->ws(), $u, $topic, $mode, $pid));
    }
}
