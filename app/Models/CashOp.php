<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Операция по кассе: приход, расход, перевод */
class CashOp extends Model
{
    protected $fillable = ['workspace_id', 'cash_desk_id', 'type', 'date', 'amount', 'rate', 'amount_base', 'category_id', 'project_id', 'counterparty_id', 'contract_id', 'payment_request_id', 'transfer_key', 'employee', 'note', 'tx_id', 'user_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'float', 'rate' => 'float', 'amount_base' => 'float'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function desk(): BelongsTo
    {
        return $this->belongsTo(CashDesk::class, 'cash_desk_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinCategory::class, 'category_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
