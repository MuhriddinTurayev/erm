<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Вход в API по ключу: «Authorization: Bearer oft_…» или «X-Api-Key: oft_…».
 * Проверяет срок ключа, подписку компании, лимит запросов и право на раздел (api:objects:read) — по роли, с которой выдан ключ.
 */
class ApiAuth
{
    public const PER_MINUTE = 120;

    public static function fail(int $status, string $code, string $message, array $headers = [])
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $request->headers->set('Accept', 'application/json');
        $plain = (string) ($request->bearerToken() ?: $request->header('X-Api-Key'));
        if ($plain === '') {
            return self::fail(401, 'unauthenticated', 'API key required: Authorization: Bearer <key>');
        }
        $token = str_starts_with($plain, ApiToken::PREFIX) ? ApiToken::with('workspace')->where('token_hash', ApiToken::hashOf($plain))->first() : null;
        if (! $token || ! $token->workspace) {
            return self::fail(401, 'invalid_key', 'Invalid API key');
        }
        if ($token->revoked_at) {
            return self::fail(401, 'key_revoked', 'API key was revoked');
        }
        if ($token->expires_at && $token->expires_at->isPast()) {
            return self::fail(401, 'key_expired', 'API key expired');
        }
        if (! $token->workspace->hasAccess()) {
            return self::fail(402, 'subscription_inactive', 'Company subscription is not active');
        }
        if (! \App\Services\Api\Scopes::enabled()) {
            return self::fail(403, 'api_disabled', 'API is temporarily disabled on the platform');
        }
        $limitKey = 'api:'.$token->id;
        if (RateLimiter::tooManyAttempts($limitKey, self::PER_MINUTE)) {
            $wait = RateLimiter::availableIn($limitKey);

            return self::fail(429, 'rate_limited', "Too many requests. Retry in {$wait}s", ['Retry-After' => $wait, 'X-RateLimit-Limit' => self::PER_MINUTE, 'X-RateLimit-Remaining' => 0]);
        }
        RateLimiter::hit($limitKey, 60);
        if ($scope && ! $token->can($scope)) {
            return self::fail(403, 'insufficient_scope', "This key has no access: {$scope}");
        }
        // когда пользовались — не чаще раза в минуту, чтобы не писать в базу на каждый запрос
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute()) || $token->last_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }
        $request->attributes->set('api_token', $token);
        // названия складов, касс, статей — на языке запроса: ?lang=uz или заголовок Accept-Language
        $lang = strtolower(substr((string) ($request->query('lang') ?: $request->header('Accept-Language', 'ru')), 0, 2));
        app()->setLocale(in_array($lang, ['ru', 'uz', 'tg'], true) ? $lang : 'ru');

        $res = $next($request);
        $res->headers->set('X-RateLimit-Limit', (string) self::PER_MINUTE);
        $res->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($limitKey, self::PER_MINUTE)));

        return $res;
    }
}
