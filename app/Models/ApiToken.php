<?php

namespace App\Models;

use App\Services\Api\Scopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ключ API. Выдаёт администратор платформы: кому, для какой компании, с какой ролью и на какие объекты.
 * Права ключа — права этой роли в компании (компания меняет права роли — меняется и ключ).
 */
class ApiToken extends Model
{
    public const PREFIX = 'oft_';

    protected $fillable = ['workspace_id', 'user_id', 'name', 'role', 'object_ids', 'read_only', 'contact', 'purpose', 'token_hash', 'hint', 'scopes',
        'last_used_at', 'last_ip', 'expires_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    private ?array $effective = null;

    protected function casts(): array
    {
        return ['scopes' => 'array', 'object_ids' => 'array', 'read_only' => 'boolean', 'last_used_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    /** Кто выдал (администратор платформы) */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Выдать ключ компании с ролью. Возвращает [запись, ключ целиком] — ключ целиком больше нигде не хранится.
     * $o: role, object_ids, read_only, contact, purpose, expires_at
     */
    public static function issue(Workspace $ws, ?User $by, string $name, array $o): array
    {
        $plain = self::PREFIX.Str::random(44);
        $t = new self(['workspace_id' => $ws->id, 'user_id' => $by?->id, 'name' => $name, 'token_hash' => self::hashOf($plain), 'hint' => self::PREFIX.'…'.substr($plain, -4)]);
        $t->fill(array_intersect_key($o, array_flip(['role', 'object_ids', 'read_only', 'contact', 'purpose', 'expires_at'])));
        $t->scopes = $t->effectiveScopes();
        $t->save();

        return [$t, $plain];
    }

    /** Права ключа сейчас: по роли в компании (только чтение — без записи) */
    public function effectiveScopes(): array
    {
        if ($this->effective !== null) {
            return $this->effective;
        }
        $ws = $this->workspace;

        return $this->effective = $this->role && $ws ? Scopes::forRole($ws, $this->role, (bool) $this->read_only) : array_values((array) $this->scopes);
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->effectiveScopes(), true);
    }

    /** null — все объекты компании; иначе — только эти */
    public function allowedObjects(): ?array
    {
        $ids = array_values(array_filter(array_map('strval', (array) $this->object_ids)));

        return $ids ?: null;
    }

    public function seesObject(?string $id): bool
    {
        $only = $this->allowedObjects();

        return $only === null || $id === null || $id === '' || in_array((string) $id, $only, true);
    }

    public function roleName(): string
    {
        return $this->role ? __(config('oftob.roles.'.$this->role, $this->role)) : __('свои права');
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
