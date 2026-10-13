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
        ]);
        $ws = $this->ws();
        try {
            $ai->assertAllowed($ws);
            $r = $ai->ask($ws, $request->user(), 'chat', $data['system'], $data['messages'], $data['provider'] ?? null);
        } catch (AiException $e) {
            return response()->json(['error' => $e->getMessage()], $e->status);
        }
        $limit = $ws->aiLimit();

        return response()->json($r + ['used' => $ws->aiUsedThisMonth(), 'limit' => $limit]);
    }
}
