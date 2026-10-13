<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Статья блога сайта (на трёх языках) */
class Post extends Model
{
    protected $fillable = ['slug', 'title', 'excerpt', 'body', 'cover', 'is_published', 'published_at'];

    protected function casts(): array
    {
        return ['title' => 'array', 'excerpt' => 'array', 'body' => 'array', 'is_published' => 'boolean', 'published_at' => 'datetime'];
    }

    /** Текст на текущем языке (или на русском, если перевода нет) */
    public function t(string $field, ?string $locale = null): string
    {
        $v = (array) $this->$field;

        return (string) (($v[$locale ?? app()->getLocale()] ?? '') ?: ($v['ru'] ?? '') ?: (array_values(array_filter($v))[0] ?? ''));
    }

    public function html(): string
    {
        return \Illuminate\Support\Str::markdown($this->t('body'), ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public function scopePublished($q)
    {
        return $q->where('is_published', true)->where('published_at', '<=', now());
    }
}
