<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Тендер: сравнение предложений поставщиков и подрядчиков */
class Tender extends Model
{
    protected $fillable = ['workspace_id', 'title', 'description', 'project_id', 'supply_request_id', 'deadline', 'status', 'winner_bid_id', 'user_id', 'approved_by'];

    protected function casts(): array
    {
        return ['deadline' => 'date'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(TenderBid::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
