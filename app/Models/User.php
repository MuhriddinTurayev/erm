<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['workspace_id', 'name', 'email', 'phone', 'password', 'role', 'locale', 'object_ids', 'is_active', 'last_login_at',
        'telegram_chat_id', 'tg_link_code', 'tg_project', 'tg_state', 'admin_perms', 'google_id', 'login_alerts'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'object_ids' => 'array',
            'tg_state' => 'array',
            'admin_perms' => 'array',
            'login_alerts' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Email всегда в нижнем регистре: вход не зависит от того, как его набрали */
    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($v) => $v === null ? null : mb_strtolower(trim((string) $v)));
    }

    /** Найти по email без учёта регистра */
    public static function byEmail(?string $email): ?self
    {
        $e = mb_strtolower(trim((string) $email));

        return $e === '' ? null : static::where('email', $e)->first() ?? static::whereRaw('lower(email) = ?', [$e])->first();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Сотрудник платформы: владелец или назначенный им администратор */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin' || $this->role === 'padmin';
    }

    /** Владелец платформы — может всё, в том числе назначать администраторов */
    public function isOwner(): bool
    {
        return $this->role === 'superadmin';
    }

    /** Право администратора платформы на раздел (companies, payments, plans, ai, site, settings) */
    public function adminCan(string $perm): bool
    {
        return $this->isOwner() || ($this->role === 'padmin' && in_array($perm, (array) $this->admin_perms, true));
    }

    /** Доступ к разделу ERP (cash, payments, warehouse…) */
    public function canModule(string $module): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->workspace && in_array($module, $this->workspace->roleModules((string) $this->role), true);
    }

    /** Руководитель компании */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canEdit(): bool
    {
        return $this->role !== 'viewer';
    }

    /** Видит ли деньги компании (касса, бухгалтерия, отчёты) */
    public function seesMoney(): bool
    {
        return in_array($this->role, ['admin', 'accountant', 'cashier', 'viewer'], true);
    }

    /** null — доступны все объекты компании */
    public function allowedObjects(): ?array
    {
        if ($this->isAdmin() || empty($this->object_ids)) {
            return null;
        }

        return array_values(array_map('strval', $this->object_ids));
    }

    /** Разделы программы, доступные роли (null — все) */
    public function allowedPages(): ?array
    {
        return config('oftob.role_pages.'.$this->role);
    }

    public function roleName(): string
    {
        return __(config('oftob.roles.'.$this->role, ['superadmin' => 'Супер-админ', 'padmin' => 'Администратор платформы'][$this->role] ?? $this->role));
    }
}
