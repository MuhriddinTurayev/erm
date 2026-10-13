<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/** Язык интерфейса: ?lang= → сессия → пользователь → компания → русский */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $supported = array_keys(config('oftob.locales'));
        $user = $request->user();
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, $supported, true)) {
            $request->session()->put('locale', $lang);
            if ($user && $user->locale !== $lang) {
                $user->forceFill(['locale' => $lang])->save();
            }
        } else {
            $lang = $request->session()->get('locale') ?: $user?->locale ?: $user?->workspace?->locale;
        }

        App::setLocale(in_array($lang, $supported, true) ? $lang : 'ru');

        return $next($request);
    }
}
