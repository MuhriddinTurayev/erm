<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Расход из Telegram или ожидающий согласования руководителя */
class Expense extends Model
{
    protected $fillable = ['workspace_id', 'project_id', 'user_id', 'source', 'date', 'amount', 'cat', 'desc', 'who', 'receipt', 'status', 'tx_id',
        'decided_by', 'decided_at', 'reason', 'tg'];

    protected function casts(): array
    {
        return ['date' => \App\Casts\DateOnly::class, 'amount' => 'float', 'decided_at' => 'datetime', 'tg' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function receiptUrl(): ?string
    {
        return $this->receipt ? url('/files/receipt/'.$this->id) : null;
    }
}
