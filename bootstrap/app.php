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
        api: __DIR__.'/../routes/api.php',   // открытый API для других программ: /api/v1/…
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
        $middleware->web(prepend: [\App\Http\Middleware\FixEncoding::class, \App\Http\Middleware\NormalizeEmail::class], append: [SetLocale::class, \App\Http\Middleware\SameWorkspace::class, \App\Http\Middleware\OnceSubmit::class]);
        // на сервере программа стоит за прокси (nginx, Traefik) во внутренней сети Docker: верим только ему —
        // тогда правильно определяются https и IP посетителя (вход, лимиты попыток, журнал входов)
        $middleware->trustProxies(at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
        $middleware->alias(['role' => Role::class, 'subscription' => Subscription::class, 'module' => Module::class, 'perm' => AdminPerm::class, 'api.key' => \App\Http\Middleware\ApiAuth::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn () => auth()->user()?->isSuperAdmin() ? '/admin' : '/crm');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // открытый API: любая ошибка — JSON одного вида {"error": {"code", "message"}}
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->errors()]], 422);
            }
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = [400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 409 => 'conflict', 413 => 'too_large', 429 => 'rate_limited'][$status] ?? ($status >= 500 ? 'server_error' : 'error');
            $msg = $status >= 500 ? 'Server error' : ($e->getMessage() ?: ['not_found' => 'Not found', 'method_not_allowed' => 'Method not allowed'][$code] ?? 'Error');

            return response()->json(['error' => ['code' => $code, 'message' => $msg]], $status);
        });
    })->create();
