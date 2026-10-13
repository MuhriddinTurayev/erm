<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Строка заявки на снабжение */
class SupplyLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['supply_request_id', 'item_id', 'qty', 'price', 'received'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'price' => 'float', 'received' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
