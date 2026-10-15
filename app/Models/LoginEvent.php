<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Устройство, с которого входили: первый и последний вход, способ, IP, сколько раз */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'method', 'ip', 'agent', 'device', 'new_device', 'last_at', 'logins'];

    protected function casts(): array
    {
        return ['new_device' => 'boolean', 'created_at' => 'datetime', 'last_at' => 'datetime', 'logins' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
