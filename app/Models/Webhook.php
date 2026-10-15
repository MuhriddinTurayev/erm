<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Вебхук: адрес другой программы, куда сообщаем о выбранных событиях (с подписью); принадлежит ключу API */
class Webhook extends Model
{
    protected $fillable = ['workspace_id', 'api_token_id', 'user_id', 'url', 'secret', 'events', 'is_active', 'fails', 'last_status', 'last_at', 'last_error'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean', 'last_at' => 'datetime'];
    }

    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'api_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wants(string $event): bool
    {
        return in_array($event, (array) $this->events, true) || in_array('*', (array) $this->events, true);
    }
}
