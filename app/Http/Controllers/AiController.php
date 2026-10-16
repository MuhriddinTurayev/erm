<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiException;
use App\Services\Ai\AiService;
use Illuminate\Http\Request;

/** ИИ-консультант в программе: запросы идут через сервер, ключи видит только супер-админ */
class AiController extends Controller
{
    public function chat(Request $request, AiService $ai)
    {
        $data = $request->validate([
            'system' => ['required', 'string', 'max:200000'],
            'messages' => ['required', 'array', 'min:1', 'max:60'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:30000'],
            'provider' => ['nullable', 'integer'],
            'stream' => ['nullable', 'boolean'],
        ]);
        $ws = $this->ws();
        try {
            $ai->assertAllowed($ws);
        } catch (AiException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
        if ($request->boolean('stream')) {
            return $this->stream($ai, $ws, $request->user(), $data);
        }
        try {
            $r = $ai->ask($ws, $request->user(), 'chat', $data['system'], $data['messages'], $data['provider'] ?? null);
        } catch (AiException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
        $limit = $ws->aiLimit();

        return response()->json($r + ['used' => $ws->aiUsedThisMonth(), 'limit' => $limit]);
    }

    /**
     * Ответ печатается по мере того, как ИИ его пишет (Server-Sent Events):
     * {"d":"кусок текста"} … {"done":1,"used":…,"limit":…} или {"error":"…"}
     */
    private function stream(AiService $ai, $ws, $user, array $data)
    {
        return response()->stream(function () use ($ai, $ws, $user, $data) {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            $send = function (array $x) {
                echo 'data: '.json_encode($x, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
                @flush();

                return ! connection_aborted();
            };
            $send(['s' => 1]);   // сразу: соединение открыто, ИИ думает
            try {
                $r = $ai->stream($ws, $user, 'chat', $data['system'], $data['messages'], $data['provider'] ?? null, fn (string $t) => $send(['d' => $t]));
                $send(['done' => 1, 'used' => $ws->aiUsedThisMonth(), 'limit' => $ws->aiLimit(), 'provider' => $r['provider']]);
            } catch (AiException $e) {
                $send(['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                report($e);
                $send(['error' => __('Ошибка сервиса ИИ')]);
            }
        }, 200, ['Content-Type' => 'text/event-stream; charset=UTF-8', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }
}
