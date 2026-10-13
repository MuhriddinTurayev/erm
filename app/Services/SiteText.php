<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Тексты сайта, изменённые администратором. Ключ — исходный русский текст из шаблона,
 * значение — свой текст для каждого языка. Нет своего — берётся обычный перевод.
 */
class SiteText
{
    /** Шаблоны сайта, тексты которых можно править */
    public const VIEWS = ['layouts/site', 'site/home', 'site/blog', 'site/post', 'site/page'];

    public static function all(): array
    {
        return Cache::remember('site_texts', 600, function () {
            $raw = PlatformSetting::get('site_texts');
            $v = $raw ? json_decode($raw, true) : [];

            return is_array($v) ? $v : [];
        });
    }

    public static function get(string $key, ?string $locale = null): ?string
    {
        $v = self::all()[$locale ?? app()->getLocale()][$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function save(array $data): void
    {
        PlatformSetting::put('site_texts', json_encode($data, JSON_UNESCAPED_UNICODE));
        Cache::forget('site_texts');
    }

    /** Все тексты шаблонов сайта по разделам: [раздел => [ключ, …]] */
    public static function keys(): array
    {
        $out = [];
        foreach (self::VIEWS as $v) {
            $f = resource_path('views/'.$v.'.blade.php');
            if (! is_file($f)) {
                continue;
            }
            $sec = __('Шапка и общее');
            foreach (preg_split('/\R/', file_get_contents($f)) as $line) {
                if (preg_match('/data-cms="([^"]+)"/u', $line, $m)) {
                    $sec = $m[1];
                }
                if (preg_match_all('/(?<![\w>$])st\(\'((?:[^\'\\\\]|\\\\.)*)\'/u', $line, $mm)) {
                    foreach ($mm[1] as $k) {
                        $k = stripslashes($k);
                        $out[$sec][$k] = true;
                    }
                }
            }
        }

        return array_map('array_keys', $out);
    }
}
