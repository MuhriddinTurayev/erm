<?php

namespace App\Http\Controllers;

use App\Services\Telegram;
use App\Services\TelegramBot;
use App\Services\TelegramPoller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Вебхук бота (на хостинге с https) и привязка Telegram сотрудника */
class TelegramController extends Controller
{
    public function webhook(Request $request, string $secret, TelegramBot $bot)
    {
        abort_unless(Telegram::enabled() && hash_equals(Telegram::secret(), $secret), 404);
        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        abort_unless($header === '' || hash_equals(Telegram::secret(), $header), 403);
        $u = $request->json()->all();
        if (TelegramBot::firstTime($u)) {   // повторная доставка того же обновления — пропускаем
            $bot->handle($u);
        }

        return response()->json(['ok' => true]);
    }

    /** Страница «Мой Telegram» для любого сотрудника компании */
    public function me(Request $request)
    {
        $u = $request->user();
        if (! $u->telegram_chat_id && ! $u->tg_link_code) {
            $u->forceFill(['tg_link_code' => strtoupper(Str::random(8))])->save();
        }

        return view('company.telegram', [
            'u' => $u,
            'bot' => \App\Models\PlatformSetting::get('telegram_bot'),
            'enabled' => Telegram::enabled(),
            // бот без вебхука, а сообщения никто не забирает (не запущен планировщик) — он не отвечает
            'deaf' => TelegramPoller::active() && ! TelegramPoller::listening(),
            // тот же бот получает сообщения ещё и на другом сайте — коды и сообщения могут уходить туда
            'shared' => TelegramPoller::active() && TelegramPoller::shared(),
        ]);
    }

    public function unlink(Request $request)
    {
        $request->user()->forceFill(['telegram_chat_id' => null, 'tg_state' => null, 'tg_link_code' => strtoupper(Str::random(8))])->save();

        return back()->with('ok', __('Telegram отвязан'));
    }

    /** Проверить привязку сразу (если бот работает без вебхука) */
    public function check(Request $request, TelegramPoller $poller)
    {
        $conflict = false;
        try {
            // несколько секунд слушаем: если тот же бот опрашивает другой сайт, Telegram сразу ответит «Conflict»
            $poller->once($request->boolean('pull') ? 0 : 3);
        } catch (\Throwable $e) {
            $conflict = str_contains($e->getMessage(), 'Conflict');
        }

        if ($request->boolean('pull')) {   // «Получить сообщения сейчас» — бот ответит на то, что ему уже написали
            return back()->with('ok', __('Сообщения боту получены — ответы уже в Telegram'));
        }
        if ($request->user()->fresh()->telegram_chat_id) {
            return back()->with('ok', __('Telegram привязан'));
        }

        return back()->withErrors(['tg' => $conflict || TelegramPoller::shared()
            ? __('Код сюда не дошёл: этот бот сейчас работает и на другом сайте (например, на сервере), и Telegram отдаёт сообщения ему. Подключите Telegram на том сайте или дайте этому сайту отдельного бота.')
            : __('Бот ещё не получил код. Откройте бота по ссылке, нажмите «Start» и проверьте снова.')]);
    }
}
