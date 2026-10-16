<?php

namespace App\Services;

use App\Models\BuyerLink;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Один Telegram-бот — два сайта (сервер и копия на компьютере). Telegram отдаёт сообщения только одному получателю, поэтому:
 *  - основной сайт получает все сообщения бота;
 *  - второй сайт (в его настройках указан адрес основного) сам забирает с основного свои сообщения:
 *    код привязки, выданный на нём, и всё, что пишут из Telegram, привязанного на нём.
 * Отвечает каждый сайт сам — отправлять сообщения через бота можно с любого сайта.
 * Где последний раз нажали «Start» с кодом, с тем аккаунтом Telegram и работает.
 * Запросы подписаны ключом из токена бота: его знают только сайты с этим ботом.
 */
class TelegramRelay
{
    private const KEEP_HOURS = 24;   // сколько ждут сообщения, если второй сайт выключен

    /* ---------------- общее ---------------- */

    /** Адрес основного сайта бота; указан — этот сайт второй и сам Telegram не опрашивает */
    public static function hub(): string
    {
        return rtrim(trim((string) PlatformSetting::get('telegram_hub', '')), '/');
    }

    public static function secondary(): bool
    {
        return self::hub() !== '' && Telegram::enabled();
    }

    private static function sign(string $ts, string $body): string
    {
        return hash_hmac('sha256', $ts.'|'.$body, hash('sha256', 'oftob-relay|'.Telegram::token()));
    }

    /** Чат обновления: личные сообщения и нажатия кнопок */
    public static function chatOf(array $u): ?string
    {
        $c = $u['message']['chat'] ?? $u['edited_message']['chat'] ?? $u['callback_query']['message']['chat'] ?? null;

        return $c && ($c['type'] ?? '') === 'private' ? (string) $c['id'] : null;
    }

    /** Код привязки в сообщении: «/start КОД» по ссылке или сам код (8 заглавных букв и цифр) */
    public static function codeOf(array $u): ?string
    {
        $t = trim((string) ($u['message']['text'] ?? ''));
        if (preg_match('/^\/start\s+(\S+)/u', $t, $m)) {
            return $m[1];
        }

        return preg_match('/^[A-Z0-9]{8}$/', $t) ? $t : null;
    }

    /** Код выдан на этом сайте */
    public static function knownCode(string $code): bool
    {
        if (str_starts_with($code, 'b_')) {
            return BuyerLink::where('token', substr($code, 2))->exists();
        }
        if (str_starts_with($code, 'w_')) {   // личная ссылка сотрудника (приход на объект)
            return \App\Models\WorkerToken::where('token', substr($code, 2))->exists();
        }
        $c = strtoupper($code);

        return User::where('tg_link_code', $c)->exists() || Workspace::where('report_link_code', $c)->exists();
    }

    /**
     * Telegram привязали на этом сайте. Второй сайт сообщит основному, что сообщения этого чата теперь его;
     * основной заберёт чат обратно у второго.
     */
    public static function linked(string $chat, string $locale = 'uz'): void
    {
        if (self::secondary()) {
            Cache::put('tg-relay-claims', [$chat => $locale] + (array) Cache::get('tg-relay-claims', []), 86400 * 7);
            Cache::put('tg-relay-unclaims', array_values(array_diff((array) Cache::get('tg-relay-unclaims', []), [$chat])), 86400 * 7);

            return;
        }
        $s = self::state();
        if (isset($s['claimed'][$chat])) {
            unset($s['claimed'][$chat]);
            $s['released'] = array_values(array_unique(array_merge($s['released'], [$chat])));   // второй сайт отвяжет у себя
            self::save($s);
            DB::table('tg_relay_queue')->where('chat_id', $chat)->delete();
        }
    }

    /** Отвязали на этом сайте (кнопка «Отвязать») — второй сайт отпускает чат */
    public static function unlinked(?string $chat): void
    {
        if ($chat && self::secondary()) {
            Cache::put('tg-relay-unclaims', array_values(array_unique(array_merge((array) Cache::get('tg-relay-unclaims', []), [$chat]))), 86400 * 7);
            $c = (array) Cache::get('tg-relay-claims', []);
            unset($c[$chat]);
            Cache::put('tg-relay-claims', $c, 86400 * 7);
        }
    }

    /* ---------------- основной сайт ---------------- */

    private static function state(): array
    {
        $s = json_decode((string) PlatformSetting::get('tg_relay', ''), true);

        return (is_array($s) ? $s : []) + ['claimed' => [], 'released' => [], 'site' => ''];
    }

    private static function save(array $s): void
    {
        PlatformSetting::put('tg_relay', (string) json_encode($s, JSON_UNESCAPED_UNICODE));
    }

    /** Второй сайт забирал сообщения в последние полторы минуты */
    public static function peerOnline(): bool
    {
        return (int) Cache::get('tg-relay-peer', 0) > time() - 90;
    }

    /** Адрес второго сайта (для подсказок) */
    public static function peerSite(): string
    {
        return (string) self::state()['site'];
    }

    /**
     * Обновление относится ко второму сайту? Тогда оно ставится ему в очередь, а здесь не обрабатывается.
     * Коды этого сайта и его сотрудники — как обычно, здесь.
     */
    public static function forward(array $u): bool
    {
        if (self::secondary() || ! ($chat = self::chatOf($u))) {
            return false;
        }
        $code = self::codeOf($u);
        if ($code !== null && self::knownCode($code)) {
            return false;   // «Start» с кодом этого сайта — Telegram переходит сюда (linked() заберёт чат)
        }
        $s = self::state();
        $claimed = isset($s['claimed'][$chat]);
        // чужой код — возможно, выдан на втором сайте; если тот не на связи, ответим здесь («код не найден»)
        if (! $claimed && ($code === null || ! self::peerOnline())) {
            return false;
        }
        DB::table('tg_relay_queue')->insert(['chat_id' => $chat, 'payload' => json_encode($u, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        if ($claimed && ! self::peerOnline() && Cache::add('tg-relay-away:'.$chat, 1, 900)) {
            // второй сайт выключен: сообщение дождётся его, но человек должен знать, почему нет ответа
            $prev = App::getLocale();
            App::setLocale((string) ($s['claimed'][$chat]['l'] ?? 'uz'));
            try {
                Telegram::message($chat, '⏳ '.__('Ваш Telegram подключён к сайту :s, а он сейчас не на связи. Сообщение выполнится, когда сайт снова откроют (ждёт сутки).', ['s' => e($s['site'] ?: '—')]));
            } catch (\Throwable) {
            } finally {
                App::setLocale($prev);
            }
        }

        return true;
    }

    /** Адрес для второго сайта: отдать его сообщения, принять привязки и отвязки */
    public static function serve(Request $r)
    {
        abort_unless(Telegram::enabled() && ! self::secondary(), 404);
        $ts = (string) $r->header('X-Relay-Ts');
        $body = $r->getContent();
        abort_unless(ctype_digit($ts) && abs(time() - (int) $ts) < 300 && hash_equals(self::sign($ts, $body), (string) $r->header('X-Relay-Sig')), 403);
        $in = json_decode($body, true) ?: [];
        if ((int) Cache::get('tg-relay-peer', 0) < time() - 20) {
            Cache::put('tg-relay-peer', time(), 86400);   // запрос каждые 2 секунды — отметку обновляем реже
        }
        $s = $before = self::state();
        if (! empty($in['site'])) {
            $s['site'] = mb_substr(strip_tags((string) $in['site']), 0, 200);
        }
        if (($ack = (int) ($in['ack'] ?? 0)) && DB::table('tg_relay_queue')->where('id', '<=', $ack)->exists()) {
            DB::table('tg_relay_queue')->where('id', '<=', $ack)->delete();   // второй сайт обработал
        }
        $s['released'] = array_values(array_diff($s['released'], array_map('strval', (array) ($in['released_ack'] ?? []))));
        $took = [];
        foreach ((array) ($in['claim'] ?? []) as $chat => $loc) {
            $chat = (string) $chat;
            // Telegram привязан на втором сайте: его сообщения — туда, здесь этот Telegram отвязываем
            $s['claimed'][$chat] = ['at' => time(), 'l' => in_array($loc, ['uz', 'ru', 'tg'], true) ? $loc : 'uz'];
            $s['released'] = array_values(array_diff($s['released'], [$chat]));
            User::where('telegram_chat_id', $chat)->update(['telegram_chat_id' => null, 'tg_state' => null]);
            $took[] = $chat;
        }
        foreach ((array) ($in['unclaim'] ?? []) as $chat) {
            unset($s['claimed'][(string) $chat]);
            $took[] = (string) $chat;
        }
        if ($s !== $before) {
            self::save($s);   // настройки (и их кэш) трогаем, только если что-то изменилось
        }
        if (Cache::add('tg-relay-gc', 1, 3600)) {
            DB::table('tg_relay_queue')->where('created_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
        }
        $rows = DB::table('tg_relay_queue')->orderBy('id')->limit(50)->get();

        return response()->json([
            'updates' => $rows->map(fn ($x) => ['id' => (int) $x->id, 'u' => json_decode($x->payload, true)])->all(),
            'release' => $s['released'],
            'took' => $took,
        ]);
    }

    /* ---------------- второй сайт ---------------- */

    /** Связь с основным сайтом была в последнюю минуту */
    public static function ok(): bool
    {
        return (int) Cache::get('tg-relay-ok', 0) > time() - 60;
    }

    /** Забрать с основного сайта свои сообщения и обработать их здесь. Возвращает число обработанных */
    public static function pull(TelegramBot $bot): int
    {
        if (! self::secondary()) {
            return 0;
        }
        $lock = Cache::lock('tg-relay-pull', 40);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $claims = (array) Cache::get('tg-relay-claims', []);
            $unclaims = (array) Cache::get('tg-relay-unclaims', []);
            $body = (string) json_encode(['site' => config('app.url'), 'ack' => (int) Cache::get('tg-relay-ack', 0), 'claim' => (object) $claims,
                'unclaim' => $unclaims, 'released_ack' => (array) Cache::get('tg-relay-rel-ack', [])]);
            $ts = (string) time();
            try {
                $r = Http::timeout(15)->withHeaders(['X-Relay-Ts' => $ts, 'X-Relay-Sig' => self::sign($ts, $body)])
                    ->withBody($body, 'application/json')->post(self::hub().'/webhook/tg-relay');
            } catch (\Throwable) {
                throw new \RuntimeException(__('Нет связи с основным сайтом бота: :s', ['s' => self::hub()]));
            }
            if (! $r->successful()) {
                throw new \RuntimeException(__('Основной сайт бота не принял запрос (:c): проверьте адрес и что там тот же бот.', ['c' => $r->status()]));
            }
            if ((int) Cache::get('tg-relay-ok', 0) < time() - 10) {
                Cache::put('tg-relay-ok', time(), 86400);   // запрос каждые 2 секунды — отметку обновляем реже
            }
            // доставленные привязки и отвязки больше не отправляем (новые, появившиеся за это время, остаются)
            if ($took = array_map('strval', (array) $r->json('took'))) {
                Cache::put('tg-relay-claims', array_diff_key((array) Cache::get('tg-relay-claims', []), array_flip($took)), 86400 * 7);
                Cache::put('tg-relay-unclaims', array_values(array_diff((array) Cache::get('tg-relay-unclaims', []), $took)), 86400 * 7);
            }
            // основной сайт забрал Telegram себе (там нажали «Start» с его кодом) — здесь отвязываем
            $rel = array_map('strval', (array) $r->json('release'));
            if ($rel) {
                User::whereIn('telegram_chat_id', $rel)->update(['telegram_chat_id' => null, 'tg_state' => null]);
            }
            if ($rel !== (array) Cache::get('tg-relay-rel-ack', [])) {
                Cache::put('tg-relay-rel-ack', $rel, 86400);
            }
            $n = 0;
            foreach ((array) $r->json('updates') as $x) {
                Cache::put('tg-relay-ack', max((int) Cache::get('tg-relay-ack', 0), (int) ($x['id'] ?? 0)), 86400 * 7);
                $u = (array) ($x['u'] ?? []);
                if ($u && TelegramBot::firstTime($u)) {
                    $bot->handle($u);
                    $n++;
                }
            }

            return $n;
        } finally {
            $lock->release();
        }
    }
}
