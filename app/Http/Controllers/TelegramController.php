<?php

namespace App\Http\Controllers;

use App\Services\Telegram;
use App\Services\TelegramBot;
use App\Services\TelegramPoller;
use App\Services\TelegramRelay;
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
        if (TelegramRelay::forward($u)) {   // относится ко второму сайту с этим же ботом — он заберёт сам
            return response()->json(['ok' => true]);
        }
        if (TelegramBot::firstTime($u)) {   // повторная доставка того же обновления — пропускаем
            $bot->handle($u);
        }

        return response()->json(['ok' => true]);
    }

    /** Мини-приложение «Пришёл / Ушёл»: страница без данных — GPS телефона уходит боту через Telegram */
    public function geoApp()
    {
        return response()->file(base_path('docs/tg/geo.html'), ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-cache']);
    }

    /** Один бот на двух сайтах: второй сайт забирает здесь свои сообщения (запрос подписан ключом из токена бота) */
    public function relay(Request $request)
    {
        return TelegramRelay::serve($request);
    }

    /** Страница «Мой Telegram» для любого сотрудника компании */
    public function me(Request $request)
    {
        $u = $request->user();
        if (! $u->telegram_chat_id && ! $u->tg_link_code) {
            $u->forceFill(['tg_link_code' => strtoupper(Str::random(8))])->save();
        }
        TelegramPoller::autostart();   // компьютер без планировщика — приём сообщений бота запускается сам
        $relay = TelegramPoller::active() && TelegramRelay::secondary();

        return view('company.telegram', [
            'u' => $u,
            'bot' => \App\Models\PlatformSetting::get('telegram_bot'),
            'enabled' => Telegram::enabled(),
            // бот без вебхука, а сообщения никто не забирает (не запущен планировщик) — он не отвечает
            // на компьютере фоновый приём сообщений запускается сам (autostart) — тогда «бот запускается», а не «запустите bot.bat»
            'deaf' => TelegramPoller::active() && ! TelegramPoller::listening() && ! TelegramPoller::shared() && ! $relay,
            'starting' => TelegramPoller::autoMode(),
            // тот же бот получает сообщения ещё и на другом сайте — коды и сообщения могут уходить туда
            'shared' => TelegramPoller::active() && TelegramPoller::shared() && ! $relay,
            // бот основного сайта: сообщения этого сайта приходят через него
            'relay' => $relay,
            'relayOk' => $relay && TelegramRelay::ok(),
            'hub' => TelegramRelay::hub(),
        ]);
    }

    public function unlink(Request $request)
    {
        TelegramRelay::unlinked($request->user()->telegram_chat_id);   // второй сайт отпускает этот Telegram основному
        $request->user()->forceFill(['telegram_chat_id' => null, 'tg_state' => null, 'tg_link_code' => strtoupper(Str::random(8))])->save();

        return back()->with('ok', __('Telegram отвязан'));
    }

    /** Проверить привязку сразу (если бот работает без вебхука) */
    public function check(Request $request, TelegramPoller $poller)
    {
        $conflict = false;
        $relayErr = null;
        try {
            // несколько секунд слушаем: если тот же бот опрашивает другой сайт, Telegram сразу ответит «Conflict».
            // Второй сайт (бот основного сайта) — забирает свои сообщения с основного
            $poller->once($request->boolean('pull') ? 0 : 3, true);
        } catch (\Throwable $e) {
            $conflict = str_contains($e->getMessage(), 'Conflict');
            $relayErr = TelegramRelay::secondary() ? $e->getMessage() : null;
        }
        if ($relayErr) {
            return back()->withErrors(['tg' => $relayErr]);
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
