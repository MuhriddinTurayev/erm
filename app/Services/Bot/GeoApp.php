<?php

namespace App\Services\Bot;

use App\Models\WorkerToken;
use App\Services\WorkerGeo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Кнопка «Пришёл / Ушёл» — мини-приложение Telegram (docs/tg/geo.html): берёт GPS телефона и возвращает его боту
 * сообщением web_app_data. Такое сообщение приходит ТОЛЬКО от этой кнопки — через 📎 его не отправить и точку на карте не выбрать.
 * В адрес кнопки зашит одноразовый код: ответ принимается только с кодом последней выданной кнопки этого чата.
 * Мини-приложению нужен https: свой сайт (если он на https) или GitHub Pages. Пока ни один адрес не открывается —
 * кнопка обычная (request_location).
 */
class GeoApp
{
    /** Метка в странице — по ней проверяем, что по адресу именно наше мини-приложение */
    public const MARK = 'oftob-geo-app';

    public const DEFAULT_URL = 'https://muhriddinturayev.github.io/erm/tg/geo.html';

    /** Рабочий https-адрес мини-приложения или null (тогда — обычная кнопка). Проверка — раз в час, неудача — раз в 10 минут */
    public static function url(): ?string
    {
        $hit = Cache::get('tg-geo-url');
        if ($hit !== null) {
            return $hit ?: null;
        }
        $found = '';
        foreach (self::candidates() as $u) {
            try {
                if (str_contains((string) Http::timeout(5)->get($u)->body(), self::MARK)) {
                    $found = $u;
                    break;
                }
            } catch (\Throwable) {
            }
        }
        Cache::put('tg-geo-url', $found, $found ? now()->addHour() : now()->addMinutes(10));

        return $found ?: null;
    }

    private static function candidates(): array
    {
        $own = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($own, PHP_URL_HOST);
        // свой сайт — только если он на https и открывается с телефона (не oftob.local, не localhost, не внутренний IP)
        $public = $host !== '' && ! preg_match('/(^localhost$|\.(local|test|localhost|lan|internal)$)/i', $host)
            && (! filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));

        return array_values(array_unique(array_filter([
            (string) config('oftob.tg_geo_url'),
            $public && str_starts_with($own, 'https://') ? $own.'/tg/geo' : '',
            self::DEFAULT_URL,
        ])));
    }

    /** Кнопка внизу экрана: мини-приложение (с новым одноразовым кодом) или, пока его нет, обычная «отправить местоположение» */
    public static function button(WorkerToken $w, string $chat, string $label, bool $open): array
    {
        $url = self::url();
        if (! $url) {
            return ['text' => $label, 'request_location' => true];
        }
        $n = Str::random(12);
        $keep = array_slice((array) Cache::get('tg-geo:'.$chat, []), -2);   // пара прошлых кодов — на случай гонки сообщений
        Cache::put('tg-geo:'.$chat, [...$keep, $n.'|'.$w->id], now()->addDays(3));

        return ['text' => $label, 'web_app' => ['url' => $url.'?'.http_build_query(['n' => $n, 'm' => $open ? 'out' : 'in', 'l' => WorkerGeo::lang($w)])]];
    }

    /**
     * Ответ мини-приложения → ['latitude', 'longitude', 'horizontal_accuracy'] или null (чужой/старый код, мусор).
     * Код одноразовый: после приёма сгорает.
     */
    public static function read(WorkerToken $w, string $chat, string $data): ?array
    {
        $d = json_decode($data, true);
        if (! is_array($d) || ! is_string($d['n'] ?? null) || ! is_numeric($d['lat'] ?? null) || ! is_numeric($d['lng'] ?? null)) {
            return null;
        }
        $codes = (array) Cache::get('tg-geo:'.$chat, []);
        $key = $d['n'].'|'.$w->id;
        if (! in_array($key, $codes, true)) {
            return null;
        }
        Cache::put('tg-geo:'.$chat, array_values(array_diff($codes, [$key])), now()->addDays(3));
        $lat = (float) $d['lat'];
        $lng = (float) $d['lng'];
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng, 'horizontal_accuracy' => is_numeric($d['acc'] ?? null) ? (float) $d['acc'] : null];
    }
}
