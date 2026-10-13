<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Файл, прикреплённый к записи */
class Attachment extends Model
{
    protected $fillable = ['workspace_id', 'subject', 'subject_id', 'path', 'name', 'size', 'user_id'];

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
