<?php

use App\Http\Middleware\AdminPerm;
use App\Http\Middleware\Module;
use App\Http\Middleware\Role;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\Subscription;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Внешние сервисы (Meta, сайт, телефоны сотрудников) не присылают CSRF-токен — у них своя защита
        $middleware->preventRequestForgery(except: [
            'webhook/*',
            's/*',
            'api/lead',
            'api/w/*',
        ]);
        $middleware->web(prepend: [\App\Http\Middleware\FixEncoding::class, \App\Http\Middleware\NormalizeEmail::class], append: [SetLocale::class, \App\Http\Middleware\SameWorkspace::class]);
        // на сервере программа стоит за прокси (nginx, Traefik) во внутренней сети Docker: верим только ему —
        // тогда правильно определяются https и IP посетителя (вход, лимиты попыток, журнал входов)
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
        $middleware->alias(['role' => Role::class, 'subscription' => Subscription::class, 'module' => Module::class, 'perm' => AdminPerm::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn () => auth()->user()?->isSuperAdmin() ? '/admin' : '/crm');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
