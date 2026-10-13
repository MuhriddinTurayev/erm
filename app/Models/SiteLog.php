<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запись журнала работ: фото и текст с объекта */
class SiteLog extends Model
{
    protected $fillable = ['workspace_id', 'project_id', 'user_id', 'date', 'text', 'photos', 'source', 'lat', 'lng', 'accuracy', 'taken_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'photos' => 'array', 'taken_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Адреса фото (только для вошедших пользователей компании) */
    public function photoUrls(): array
    {
        return array_map(fn ($i) => url('/files/log/'.$this->id.'/'.$i), array_keys($this->photos ?? []));
    }
}
