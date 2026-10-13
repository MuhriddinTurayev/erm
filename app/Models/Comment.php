<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Комментарий к задаче, заявке, договору, фотоотчёту */
class Comment extends Model
{
    protected $fillable = ['workspace_id', 'subject', 'subject_id', 'user_id', 'text'];

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
