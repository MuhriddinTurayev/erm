<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Движение по складу: приход, выдача, перемещение, корректировка */
class StockMove extends Model
{
    protected $fillable = ['workspace_id', 'item_id', 'warehouse_id', 'type', 'qty', 'price', 'batch', 'project_id', 'counterparty_id', 'supply_request_id', 'transfer_key', 'note', 'date', 'user_id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'price' => 'float', 'date' => 'date'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
