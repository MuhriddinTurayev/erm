<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    public const METHODS = ['transfer' => 'Перечисление', 'cash' => 'Наличные', 'card' => 'Карта', 'click' => 'Click', 'payme' => 'Payme', 'other' => 'Другое'];

    protected $fillable = ['workspace_id', 'plan_id', 'amount', 'currency', 'months', 'method', 'note', 'idem', 'paid_at', 'created_by'];

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'float'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
