<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Тариф платформы */
class Plan extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'price_month', 'price_year', 'currency', 'max_objects', 'max_users',
        'ai_enabled', 'ai_requests', 'daily_report', 'features', 'trial_days', 'is_active', 'is_featured', 'sort'];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'features' => 'array',
            'price_month' => 'float',
            'price_year' => 'float',
            'ai_enabled' => 'boolean',
            'daily_report' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** Название на языке интерфейса */
    public function title(?string $locale = null): string
    {
        return self::pick($this->name, $locale);
    }

    public function about(?string $locale = null): string
    {
        return self::pick($this->description, $locale);
    }

    /** @return string[] пункты витрины на языке интерфейса */
    public function featureList(?string $locale = null): array
    {
        $l = $locale ?: app()->getLocale();
        $f = $this->features ?? [];

        return array_values(array_filter((array) ($f[$l] ?? $f['ru'] ?? [])));
    }

    public static function pick($value, ?string $locale = null): string
    {
        $l = $locale ?: app()->getLocale();
        if (! is_array($value)) {
            return (string) $value;
        }

        return (string) ($value[$l] ?? $value['ru'] ?? reset($value) ?: '');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort')->orderBy('price_month');
    }

    public function limitText(int $value): string
    {
        return $value === 0 ? '∞' : (string) $value;
    }
}
