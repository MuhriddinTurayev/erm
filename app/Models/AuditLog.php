<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** История изменений данных компании */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'user_id', 'project', 'action', 'summary'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
