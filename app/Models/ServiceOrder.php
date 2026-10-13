<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Сервисная заявка: ремонт, услуга, работы подрядчика */
class ServiceOrder extends Model
{
    protected $fillable = ['workspace_id', 'number', 'title', 'description', 'project_id', 'counterparty_id', 'assignee_id', 'amount', 'due_date', 'status', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'due_date' => 'date'];
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
