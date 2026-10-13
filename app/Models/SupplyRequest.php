<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Заявка на снабжение */
class SupplyRequest extends Model
{
    protected $fillable = ['workspace_id', 'number', 'project_id', 'user_id', 'counterparty_id', 'warehouse_id', 'need_by', 'status', 'note'];

    protected function casts(): array
    {
        return ['need_by' => 'date'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplyLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
