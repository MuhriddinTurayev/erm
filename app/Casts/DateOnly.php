<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Дата без времени: в базу пишется «2026-10-15». Обычный cast «date» на SQLite пишет «2026-10-15 00:00:00» —
 * тогда where('date', '2026-10-15') и «по дату включительно» не находят записи (на MySQL с колонкой DATE этого не видно).
 * Читается так же, как «date»: Carbon на начало дня.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : Carbon::parse((string) $value)->toDateString();
    }
}
