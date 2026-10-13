<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\AuditDiff;
use App\Services\StateAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Данные CRM компании: чтение и сохранение с защитой от одновременной перезаписи */
class StateController extends Controller
{
    public function version()
    {
        return response()->json(['version' => (int) Workspace::whereKey($this->ws()->id)->value('version')]);
    }

    public function show(Request $request)
    {
        $ws = Workspace::whereKey($this->ws()->id)->first(['id', 'version', 'data']);

        return response()->json(['version' => (int) $ws->version, 'data' => StateAccess::forUser($ws->data, $request->user())]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canEdit(), 403, __('Только просмотр'));

        $base = (int) $request->input('version');
        $data = $request->input('data');
        if (! is_string($data) || $data === '') {
            return response()->json(['error' => __('Нет данных')], 422);
        }
        if (strlen($data) > config('oftob.max_state_bytes')) {
            return response()->json(['error' => __('Слишком много данных')], 413);
        }
        $decoded = json_decode($data, true);
        if (! is_array($decoded) || ! isset($decoded['projects']) || ! is_array($decoded['projects'])) {
            return response()->json(['error' => __('Неверный формат данных')], 422);
        }

        $wsId = $this->ws()->id;

        return DB::transaction(function () use ($wsId, $base, $decoded, $data, $user) {
            $ws = Workspace::with('plan')->whereKey($wsId)->lockForUpdate()->first();
            $stored = Workspace::whereKey($wsId)->value('data');
            if ((int) $ws->version !== $base) {
                // кто-то сохранил раньше — отдаём свежие данные, программа объединит изменения
                return response()->json(['conflict' => true, 'version' => (int) $ws->version, 'data' => StateAccess::forUser($stored, $user)], 409);
            }
            $next = StateAccess::merge($stored, $decoded, $user);
            if (StateAccess::exceedsLimit($ws, $stored, $next)) {
                return response()->json(['error' => __('По вашему тарифу можно вести не больше :n объектов. Перейдите на тариф выше.', ['n' => $ws->maxObjects()]), 'limit' => true], 403);
            }
            $ws->data = $user->allowedObjects() === null ? $data : json_encode($next, JSON_UNESCAPED_UNICODE);
            $ws->version = $base + 1;
            $ws->save();
            try {
                AuditDiff::record($wsId, $user->id, $stored, $ws->data);
            } catch (\Throwable $e) {
                report($e);   // история не должна мешать сохранению
            }

            return response()->json(['version' => (int) $ws->version]);
        });
    }
}
