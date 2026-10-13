<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Вход в систему: способ, IP, устройство */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'method', 'ip', 'agent', 'device', 'new_device'];

    protected function casts(): array
    {
        return ['new_device' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
