<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Касса или счёт компании */
class CashDesk extends Model
{
    protected $fillable = ['workspace_id', 'name', 'kind', 'currency', 'project_id', 'keeper_id', 'opening', 'is_active'];

    protected function casts(): array
    {
        return ['opening' => 'float', 'is_active' => 'boolean'];
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

    public function ops(): HasMany
    {
        return $this->hasMany(CashOp::class);
    }
}
