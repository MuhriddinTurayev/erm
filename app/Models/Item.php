<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Номенклатура: материал, инструмент */
class Item extends Model
{
    protected $fillable = ['workspace_id', 'name', 'unit', 'sku', 'group', 'min_qty', 'price'];

    protected function casts(): array
    {
        return ['min_qty' => 'float', 'price' => 'float'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function moves(): HasMany
    {
        return $this->hasMany(StockMove::class);
    }
}
