<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ограничение частоты для внешних адресов (вебхуки, заявки с сайта, телефоны сотрудников)
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        Paginator::useBootstrapFive();

        // даты по-узбекски — латиницей (у Carbon «uz» по умолчанию кириллица)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Events\LocaleUpdated::class, function ($e) {
            \Illuminate\Support\Carbon::setLocale($e->locale === 'uz' ? 'uz_Latn' : $e->locale);
        });

        // общие данные страниц сайта (шаблон сайта рендерится после страницы — переменные нужны обоим)
        \Illuminate\Support\Facades\View::composer(['layouts.site', 'site.*'], function ($view) {
            static $shared;
            $shared ??= (function () {
                $u = auth()->user();
                $s = fn ($k) => \App\Models\PlatformSetting::get($k);

                return [
                    'brand' => \App\Models\PlatformSetting::brand(),
                    'appUrl' => $u ? ($u->isSuperAdmin() ? '/admin' : '/crm') : null,
                    'regOpen' => $s('registration_open') === '1',
                    'support' => ['phone' => $s('support_phone'), 'telegram' => $s('support_telegram'), 'email' => $s('support_email')],
                ];
            })();
            $view->with($shared);
        });
    }
}
