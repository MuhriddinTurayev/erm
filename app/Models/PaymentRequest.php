<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Заявка на оплату с цепочкой согласования */
class PaymentRequest extends Model
{
    protected $fillable = ['workspace_id', 'number', 'user_id', 'project_id', 'counterparty_id', 'contract_id', 'category_id', 'amount', 'currency', 'purpose', 'due_date', 'status', 'step', 'chain', 'cash_op_id', 'tg'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'due_date' => \App\Casts\DateOnly::class, 'chain' => 'array', 'tg' => 'array'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinCategory::class, 'category_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function cashOp(): BelongsTo
    {
        return $this->belongsTo(CashOp::class, 'cash_op_id');
    }
}
