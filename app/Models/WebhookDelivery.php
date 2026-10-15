<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Отправка вебхука: событие, попытки, ответ получателя */
class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_id', 'uuid', 'event', 'payload', 'attempts', 'status', 'ok', 'error', 'ms'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
