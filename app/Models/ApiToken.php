<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Ключ API для другой программы: права по разделам, срок, когда пользовались */
class ApiToken extends Model
{
    public const PREFIX = 'oft_';

    protected $fillable = ['workspace_id', 'user_id', 'name', 'token_hash', 'hint', 'scopes', 'last_used_at', 'last_ip', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Выпустить ключ. Возвращает [запись, ключ целиком] — ключ целиком больше нигде не хранится. */
    public static function issue(Workspace $ws, ?User $by, string $name, array $scopes, ?\DateTimeInterface $expires = null): array
    {
        $plain = self::PREFIX.Str::random(44);
        $t = self::create([
            'workspace_id' => $ws->id, 'user_id' => $by?->id, 'name' => $name, 'token_hash' => self::hashOf($plain),
            'hint' => self::PREFIX.'…'.substr($plain, -4), 'scopes' => array_values($scopes), 'expires_at' => $expires,
        ]);

        return [$t, $plain];
    }

    public function can(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
