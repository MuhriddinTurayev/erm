<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/** Telegram-бот платформы (токен задаёт супер-админ) */
class Telegram
{
    public static function token(): string
    {
        return (string) PlatformSetting::get('telegram_token', '');
    }

    public static function enabled(): bool
    {
        return self::token() !== '';
    }

    /** Сообщение с кнопками под ним. $buttons — [[['text' => …, 'data' => …], …], …] (ряды) */
    public static function message(string $chatId, string $html, array $buttons = []): array
    {
        $p = ['chat_id' => $chatId, 'text' => mb_substr($html, 0, 4000), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($buttons) {
            $p['reply_markup'] = ['inline_keyboard' => self::kb($buttons)];
        }

        return self::call('sendMessage', $p);
    }

    public static function edit(string $chatId, int $messageId, string $html, array $buttons = []): void
    {
        $p = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => mb_substr($html, 0, 4000), 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
            'reply_markup' => ['inline_keyboard' => self::kb($buttons)]];
        try {
            self::call('editMessageText', $p);
        } catch (\Throwable) {
        }
    }

    public static function answer(string $callbackId, string $text = ''): void
    {
        try {
            self::call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190)]);
        } catch (\Throwable) {
        }
    }

    private static function kb(array $rows): array
    {
        return array_map(fn ($row) => array_map(fn ($b) => isset($b['url']) ? ['text' => $b['text'], 'url' => $b['url']] : ['text' => $b['text'], 'callback_data' => $b['data']], $row), $rows);
    }

    /** Скачать файл (фото) по file_id: [содержимое, расширение] */
    public static function download(string $fileId): array
    {
        $f = self::call('getFile', ['file_id' => $fileId]);
        $path = (string) ($f['file_path'] ?? '');
        $r = Http::timeout(60)->get('https://api.telegram.org/file/bot'.self::token().'/'.$path);
        if (! $r->successful()) {
            throw new \RuntimeException('Telegram file');
        }

        return [$r->body(), strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg')];
    }

    /** Новые сообщения боту (long polling) */
    public static function updates(int $offset, int $timeout = 25): array
    {
        return self::call('getUpdates', ['offset' => $offset, 'timeout' => $timeout, 'allowed_updates' => ['message', 'callback_query']], null, $timeout + 10);
    }

    public static function setWebhook(string $url, string $secret): void
    {
        self::call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']]);
    }

    public static function deleteWebhook(): void
    {
        self::call('deleteWebhook', []);
    }

    /** Секрет для адреса вебхука — от токена бота */
    public static function secret(): string
    {
        return substr(hash('sha256', 'tg|'.self::token().'|'.config('app.key')), 0, 32);
    }

    private static function call(string $method, array $params = [], ?string $token = null, int $timeout = 20): array
    {
        $token = $token ?? self::token();
        if ($token === '') {
            throw new \RuntimeException(__('Telegram-бот не настроен'));
        }
        try {
            $r = Http::timeout($timeout)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('Нет связи с Telegram'));
        }
        $j = $r->json() ?? [];
        if (! ($j['ok'] ?? false)) {
            throw new \RuntimeException('Telegram: '.($j['description'] ?? $r->status()));
        }

        return (array) ($j['result'] ?? []);
    }

    /** Проверка токена: возвращает имя бота */
    public static function me(?string $token = null): string
    {
        return (string) (self::call('getMe', [], $token)['username'] ?? '');
    }

    /** Сообщение в HTML-разметке Telegram; длинное — по частям */
    public static function send(string $chatId, string $html): void
    {
        foreach (self::chunks($html, 3900) as $part) {
            self::call('sendMessage', ['chat_id' => $chatId, 'text' => $part, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        }
    }

    /** Найти чат, из которого прислали код привязки: «/start КОД» */
    public static function findChatByCode(string $code): ?array
    {
        foreach (array_reverse(self::call('getUpdates', ['limit' => 100, 'allowed_updates' => ['message']])) as $u) {
            $m = $u['message'] ?? null;
            if ($m && str_contains(strtoupper((string) ($m['text'] ?? '')), strtoupper($code))) {
                $c = $m['chat'];

                return ['id' => (string) $c['id'], 'name' => trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? '')) ?: ($c['title'] ?? $c['username'] ?? '')];
            }
        }

        return null;
    }

    private static function chunks(string $s, int $max): array
    {
        if (mb_strlen($s) <= $max) {
            return [$s];
        }
        $out = [];
        $cur = '';
        foreach (explode("\n", $s) as $line) {
            if (mb_strlen($cur) + mb_strlen($line) + 1 > $max && $cur !== '') {
                $out[] = $cur;
                $cur = '';
            }
            $cur .= ($cur === '' ? '' : "\n").$line;
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }
}
