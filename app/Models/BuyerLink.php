<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ссылка на личный кабинет покупателя квартиры */
class BuyerLink extends Model
{
    protected $fillable = ['token', 'workspace_id', 'project_id', 'apt_id', 'tg_chat_id', 'last_reminded'];

    protected function casts(): array
    {
        return ['last_reminded' => 'date'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function url(): string
    {
        return url('/b/'.$this->token);
    }
}
