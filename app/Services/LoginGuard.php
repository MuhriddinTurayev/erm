<?php

namespace App\Services;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    public static function record(User $user, Request $request, string $method = 'password'): LoginEvent
    {
        [$b, $os] = self::device($request->userAgent());
        $device = sha1($user->id.'|'.$b.'|'.$os);
        $known = LoginEvent::where('user_id', $user->id)->where('device', $device)->exists();
        $first = ! LoginEvent::where('user_id', $user->id)->exists();
        $ev = LoginEvent::create(['user_id' => $user->id, 'method' => $method, 'ip' => $request->ip(), 'agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'device' => $device, 'new_device' => ! $known && ! $first]);
        $user->forceFill(['last_login_at' => now()])->save();
        if ($ev->new_device && $user->login_alerts) {
            self::alert($user, $ev, "$b, $os");
        }

        return $ev;
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
