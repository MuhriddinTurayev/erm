<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/** Компания-клиент платформы */
class Workspace extends Model
{
    protected $fillable = ['name', 'data', 'version', 'plan_id', 'status', 'trial_ends_at', 'paid_until', 'phone', 'locale', 'timezone',
        'report_enabled', 'report_time', 'report_chats', 'report_email', 'report_link_code', 'report_ai', 'ai_provider_id', 'note', 'plan_request', 'approval_limit', 'public_key', 'public_enabled', 'usd_rate', 'settings'];

    // Данные CRM большие — не отдаём их случайно вместе с моделью
    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'paid_until' => 'datetime',
            'report_enabled' => 'boolean',
            'report_ai' => 'boolean',
            'approval_limit' => 'float',
            'public_enabled' => 'boolean',
            'usd_rate' => 'float',
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(DailyReport::class);
    }

    public function aiUsage(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    /** Подключения создаются при первом обращении — со случайными ключами */
    public function integrationOrCreate(): Integration
    {
        return $this->integration()->firstOrCreate([], [
            'hook_id' => bin2hex(random_bytes(10)),
            'verify_token' => 'oftob-'.bin2hex(random_bytes(8)),
            'api_key' => bin2hex(random_bytes(12)),
        ]);
    }

    /** Настройка компании (цепочка согласования, права ролей…) */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function putSetting(string $key, mixed $value): void
    {
        $s = $this->settings ?? [];
        data_set($s, $key, $value);
        $this->settings = $s;
        $this->save();
    }

    /** Разделы ERP, доступные роли в этой компании */
    public function roleModules(string $role): array
    {
        if ($role === 'admin') {
            return array_keys(config('oftob.modules'));
        }

        // «Объекты» видит каждый (только свои объекты)
        return array_values(array_unique(array_merge(['objects'], (array) $this->setting('role_modules.'.$role, config('oftob.role_modules.'.$role, [])))));
    }

    /** Цепочка согласования заявок на оплату: роли по порядку */
    public function approvalChain(): array
    {
        return array_values((array) $this->setting('chain', ['accountant', 'admin']));
    }

    /* ---------- Подписка ---------- */

    /** До какого момента оплачен доступ (пробный период или оплата) */
    public function accessUntil(): ?Carbon
    {
        if ($this->status === 'trial') {
            return $this->trial_ends_at;
        }

        return $this->paid_until;
    }

    /** Можно ли работать в программе */
    public function hasAccess(): bool
    {
        if ($this->status === 'suspended') {
            return false;
        }
        $until = $this->accessUntil();

        return $until === null || $until->isFuture();
    }

    /** trial | active | expired | suspended — для подписи в интерфейсе */
    public function state(): string
    {
        if ($this->status === 'suspended') {
            return 'suspended';
        }

        return $this->hasAccess() ? ($this->status === 'trial' ? 'trial' : 'active') : 'expired';
    }

    public function daysLeft(): ?int
    {
        $until = $this->accessUntil();

        return $until ? (int) max(0, ceil(now()->diffInHours($until, false) / 24)) : null;
    }

    /** Продлить доступ на N месяцев (от сегодня или от конца текущей оплаты) */
    public function extend(int $months): void
    {
        $from = $this->paid_until && $this->paid_until->isFuture() ? $this->paid_until->copy() : now();
        $this->paid_until = $from->addMonthsNoOverflow($months)->endOfDay();
        $this->status = 'active';
        $this->save();
    }

    /* ---------- Ограничения тарифа ---------- */

    public function maxObjects(): int
    {
        return (int) ($this->plan?->max_objects ?? 0);
    }

    public function maxUsers(): int
    {
        return (int) ($this->plan?->max_users ?? 0);
    }

    public function aiAllowed(): bool
    {
        return (bool) ($this->plan?->ai_enabled ?? true);
    }

    /** Лимит запросов к ИИ в месяц, 0 — без ограничений */
    public function aiLimit(): int
    {
        return (int) ($this->plan?->ai_requests ?? 0);
    }

    public function aiUsedThisMonth(): int
    {
        return $this->aiUsage()->where('kind', 'chat')->where('ok', true)->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /** Объекты (проекты) компании из данных CRM */
    public function projects(): array
    {
        $raw = $this->getAttribute('data') ?? self::whereKey($this->id)->value('data');
        $d = json_decode((string) $raw, true);

        return is_array($d) && is_array($d['projects'] ?? null) ? $d['projects'] : [];
    }

    /** Демо-объект не считается в лимит тарифа */
    public static function isDemo(array $p): bool
    {
        return ! empty($p['demo']) || (bool) preg_match('/демо|demo/iu', (string) ($p['name'] ?? ''));
    }

    public static function countObjects(array $projects): int
    {
        return count(array_filter($projects, fn ($p) => is_array($p) && ! self::isDemo($p)));
    }

    /** Местное время компании */
    public function localNow(): Carbon
    {
        return now($this->timezone ?: 'Asia/Tashkent');
    }

    public function stateLabel(): string
    {
        return [
            'trial' => __('Пробный период'),
            'active' => __('Активна'),
            'expired' => __('Срок истёк'),
            'suspended' => __('Заблокирована'),
        ][$this->state()];
    }
}
