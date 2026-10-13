<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Статья доходов или расходов с месячным лимитом */
class FinCategory extends Model
{
    protected $table = 'fin_categories';

    protected $fillable = ['workspace_id', 'name', 'kind', 'month_limit', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['month_limit' => 'float', 'is_active' => 'boolean'];
    }

    /** Записи компании */
    public function scopeOf($q, int $workspaceId)
    {
        return $q->where($this->getTable().'.workspace_id', $workspaceId);
    }
}
