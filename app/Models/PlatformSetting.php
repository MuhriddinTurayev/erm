<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Настройки платформы: название, контакты, Telegram-бот… Секретные значения хранятся зашифрованными. */
class PlatformSetting extends Model
{
    public const SECRET = ['telegram_token', 'google_client_secret'];

    public const DEFAULTS = [
        'brand' => 'Oftob_Sohtumon',
        'support_phone' => '+998 90 000 00 00',
        'support_telegram' => '',
        'support_email' => '',
        'registration_open' => '1',
        'default_plan' => 'business',
        'telegram_token' => '',
        'telegram_bot' => '',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function all_(): array
    {
        return Cache::remember('platform_settings', 300, function () {
            try {
                $rows = self::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                $rows = [];
            }

            return $rows;
        }) + self::DEFAULTS;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = self::all_()[$key] ?? null;
        if ($v !== null && $v !== '' && in_array($key, self::SECRET, true)) {
            try {
                $v = Crypt::decryptString($v);
            } catch (\Throwable) {
                $v = '';
            }
        }

        return ($v === null || $v === '') ? ($default ?? (self::DEFAULTS[$key] ?? null)) : $v;
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value !== null && $value !== '' && in_array($key, self::SECRET, true)) {
            $value = Crypt::encryptString($value);
        }
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('platform_settings');
    }

    public static function brand(): string
    {
        return (string) self::get('brand');
    }
}
