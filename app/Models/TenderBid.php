<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Предложение участника тендера */
class TenderBid extends Model
{
    protected $fillable = ['tender_id', 'counterparty_id', 'amount', 'days', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }
}
