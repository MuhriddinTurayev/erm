<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Подключение к сервису ИИ (ключ хранится зашифрованным) */
class AiProvider extends Model
{
    public const DRIVERS = [
        'gemini' => ['Google Gemini', 'gemini-2.5-flash', 'https://aistudio.google.com/apikey'],
        'openai' => ['OpenAI ChatGPT', 'gpt-4.1-mini', 'https://platform.openai.com/api-keys'],
        'deepseek' => ['DeepSeek', 'deepseek-chat', 'https://platform.deepseek.com/api_keys'],
        'anthropic' => ['Anthropic Claude', 'claude-opus-5-5', 'https://console.anthropic.com/settings/keys'],
        'compatible' => ['OpenAI-совместимый (Qwen, Grok, Mistral…)', '', ''],
    ];

    protected $fillable = ['driver', 'name', 'api_key', 'model', 'base_url', 'max_tokens', 'is_active', 'is_default', 'sort'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id');
    }

    public static function default(): ?self
    {
        return self::active()->first();
    }

    public function maskedKey(): string
    {
        $k = (string) $this->api_key;

        return $k === '' ? '—' : '••••'.substr($k, -4);
    }

    public function driverName(): string
    {
        return self::DRIVERS[$this->driver][0] ?? $this->driver;
    }

    /** Короткое описание для списка в программе */
    public function publicInfo(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'driver' => $this->driver, 'model' => $this->model];
    }
}
