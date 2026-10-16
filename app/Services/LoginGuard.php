<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Учёт входов: история, известные устройства, оповещение о входе с нового устройства */
class LoginGuard
{
    /** Браузер и система по User-Agent: ['Chrome', 'Windows', 'laptop'] */
    public static function device(?string $ua): array
    {
        $ua = (string) $ua;
        $browser = match (true) {
            str_contains($ua, 'Telegram') => 'Telegram',
            str_contains($ua, 'YaBrowser') => 'Яндекс Браузер',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            $ua === '' => '—',
            default => __('Браузер'),
        };
        [$os, $kind] = match (true) {
            str_contains($ua, 'iPad') => ['iPad', 'tablet'],
            str_contains($ua, 'iPhone') => ['iPhone', 'smartphone'],
            str_contains($ua, 'Android') => [str_contains($ua, 'Mobile') ? 'Android' : 'Android', str_contains($ua, 'Mobile') ? 'smartphone' : 'tablet'],
            str_contains($ua, 'Windows') => ['Windows', 'laptop'],
            str_contains($ua, 'Mac OS') => ['macOS', 'laptop'],
            str_contains($ua, 'Linux') => ['Linux', 'laptop'],
            default => ['—', 'monitor'],
        };

        return [$browser, $os, $kind];
    }

    /** Метка браузера: случайный номер в cookie на 5 лет — по нему узнаём уже знакомое устройство */
    public const COOKIE = 'oftob_dev';

    /**
     * Вход: одно устройство — одна строка в истории. Повторный вход с того же устройства обновляет время, IP и счётчик;
     * новая строка (и оповещение) — только когда вошли с другого устройства.
     */
    public static function record(User $user, Request $request, string $method = 'password'): LoginEvent
    {
        [$b, $os] = self::device($request->userAgent());
        $device = self::hash($user->id, self::deviceId($request));
        $ev = LoginEvent::where('user_id', $user->id)->where('device', $device)->first()
            // входы до появления метки: устройство узнавали по браузеру и системе — такая строка становится этим устройством
            ?? LoginEvent::where('user_id', $user->id)->where('device', sha1($user->id.'|'.$b.'|'.$os))->orderBy('id')->first();
        $seen = ['method' => $method, 'ip' => $request->ip(), 'agent' => mb_substr((string) $request->userAgent(), 0, 255), 'device' => $device, 'last_at' => now()];
        if ($ev) {
            $ev->forceFill($seen + ['logins' => (int) $ev->logins + 1, 'ips' => self::ips((array) $ev->ips ?: ($ev->ip ? [[$ev->ip, $ev->last_at?->toIso8601String()]] : []), $request->ip())])->save();
        } else {
            $first = ! LoginEvent::where('user_id', $user->id)->exists();
            $ev = LoginEvent::create($seen + ['user_id' => $user->id, 'new_device' => ! $first, 'logins' => 1, 'ips' => self::ips([], $request->ip())]);
            if ($ev->new_device && $user->login_alerts) {
                self::alert($user, $ev, "$b, $os");
            }
        }
        $user->forceFill(['last_login_at' => now()])->save();

        return $ev;
    }

    /** С каких IP входили с этого устройства: последние 10, свежие первыми */
    private static function ips(array $list, ?string $ip): array
    {
        $list = array_values(array_filter($list, fn ($x) => is_array($x) && ($x[0] ?? null) !== $ip));
        array_unshift($list, [$ip, now()->toIso8601String()]);

        return array_slice($list, 0, 10);
    }

    /** Метка этого браузера (новая, если её ещё нет); срок cookie продлевается при каждом входе */
    private static function deviceId(Request $request): string
    {
        $id = (string) $request->cookie(self::COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $id)) {
            $id = Str::random(40);
        }
        Cookie::queue(Cookie::make(self::COOKIE, $id, 60 * 24 * 365 * 5, null, null, null, true, false, 'lax'));

        return $id;
    }

    private static function hash(int $userId, string $id): string
    {
        return hash('sha256', $userId.'|'.$id);
    }

    /** Отпечаток устройства, с которого открыта страница (null — метки нет) */
    public static function currentDevice(User $user, Request $request): ?string
    {
        $id = (string) $request->cookie(self::COOKIE);

        return preg_match('/^[A-Za-z0-9]{40}$/', $id) ? self::hash($user->id, $id) : null;
    }

    private static function alert(User $user, LoginEvent $ev, string $device): void
    {
        $prev = App::getLocale();
        App::setLocale($user->locale ?: $prev);
        try {
            $text = __('Вход в ваш аккаунт с нового устройства').":\n".$device."\nIP: ".$ev->ip."\n".now()->format('d.m.Y H:i')
                ."\n\n".__('Если это были не вы — откройте «Безопасность», завершите все сеансы и смените пароль.');
            if ($user->telegram_chat_id && Telegram::enabled()) {
                Telegram::message($user->telegram_chat_id, '🔐 <b>'.e(__('Новый вход')).'</b>'."\n".e($text), [[['text' => __('Безопасность'), 'url' => url('/security')]]]);
            }
            Mail::raw($text."\n\n".url('/security'), fn ($m) => $m->to($user->email)->subject(__('Новый вход в аккаунт').' — '.\App\Models\PlatformSetting::brand()));
        } catch (\Throwable $e) {
            Log::warning('Login alert: '.$e->getMessage());
        } finally {
            App::setLocale($prev);
        }
    }
}
