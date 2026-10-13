<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Контрагент: поставщик, подрядчик, клиент */
class Counterparty extends Model
{
    protected $table = 'counterparties';

    protected $fillable = ['workspace_id', 'kind', 'folder', 'name', 'inn', 'phone', 'contact', 'address', 'bank', 'status', 'status_reason', 'note', 'created_by'];

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function ops(): HasMany
    {
        return $this->hasMany(CashOp::class);
    }
}
