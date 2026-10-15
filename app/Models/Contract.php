<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Договор с контрагентом */
class Contract extends Model
{
    protected $fillable = ['workspace_id', 'counterparty_id', 'project_id', 'number', 'date', 'subject', 'direction', 'amount', 'currency', 'due_date', 'status', 'didox_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'due_date' => \App\Casts\DateOnly::class, 'amount' => 'float'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function ops(): HasMany
    {
        return $this->hasMany(CashOp::class);
    }
}
