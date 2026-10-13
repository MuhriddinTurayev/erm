<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Склад (общий или на объекте) */
class Warehouse extends Model
{
    protected $fillable = ['workspace_id', 'name', 'project_id', 'keeper_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function keeper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'keeper_id');
    }
}
