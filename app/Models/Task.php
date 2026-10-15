<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Задача сотруднику */
class Task extends Model
{
    protected $fillable = ['workspace_id', 'title', 'description', 'project_id', 'assignee_id', 'author_id', 'due_date', 'priority', 'status', 'progress', 'done_at', 'approved_by'];

    protected function casts(): array
    {
        return ['due_date' => \App\Casts\DateOnly::class, 'done_at' => 'datetime'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
