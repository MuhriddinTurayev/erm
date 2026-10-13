<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Решение согласующего по заявке */
class Approval extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_request_id', 'step', 'user_id', 'decision', 'comment'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
