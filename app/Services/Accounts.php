<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Учётные записи сотрудников компании: руководитель создаёт логин и пароль любому сотруднику.
 * Прорабу email не обязателен — он входит по номеру телефона.
 */
class Accounts
{
    public static function digits(?string $phone): string
    {
        return substr(preg_replace('/\D/', '', (string) $phone), -9);
    }

    /** Свободный ли телефон для входа (среди всех пользователей платформы) */
    public static function phoneTaken(string $phone, ?int $exceptId = null): bool
    {
        $d = self::digits($phone);

        return strlen($d) >= 9 && User::whereNotNull('phone')->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->get(['id', 'phone'])
            ->contains(fn ($u) => self::digits($u->phone) === $d);
    }

    /** Простой, но надёжный пароль, который легко продиктовать: Abc-1234-xyz */
    public static function password(): string
    {
        return Str::ucfirst(Str::lower(Str::random(3))).'-'.random_int(1000, 9999).'-'.Str::lower(Str::random(3));
    }

    /**
     * Создать сотрудника. $d: name, email?, phone?, role, password?, objects?[]
     * Возвращает [User, пароль, логин для входа].
     */
    public static function create(Workspace $ws, User $by, array $d): array
    {
        $max = $ws->maxUsers();
        if ($max > 0 && $ws->users()->count() >= $max) {
            throw new \DomainException(__('По вашему тарифу можно не больше :n пользователей. Перейдите на тариф выше.', ['n' => $max]));
        }
        if (! empty($d['phone']) && self::phoneTaken($d['phone'])) {
            throw new \DomainException(__('Этот телефон уже используется для входа другим пользователем'));
        }
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if ($email === '') {
            // вход по телефону: служебный email, который никому не показывается
            $email = 'u'.Str::lower(Str::random(10)).'@ws'.$ws->id.'.oftob.local';
        }
        $pass = ($d['password'] ?? '') ?: self::password();
        $role = $d['role'] ?? 'foreman';
        $objects = $role === 'admin' ? null : (array_values(array_filter((array) ($d['objects'] ?? []))) ?: null);
        $u = $ws->users()->create(['name' => $d['name'], 'email' => $email, 'phone' => $d['phone'] ?? null, 'role' => $role, 'password' => $pass,
            'object_ids' => $objects, 'locale' => $ws->locale, 'is_active' => true]);
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'action' => 'user.add',
            'summary' => 'Новый пользователь: '.$u->name.' · '.config('oftob.roles.'.$role, $role).($objects ? ' · объектов: '.count($objects) : '')]);

        return [$u, $pass, self::login($u)];
    }

    /** Что пользователь вводит в поле «Логин» */
    public static function login(User $u): string
    {
        return str_ends_with($u->email, '.oftob.local') ? (string) $u->phone : $u->email;
    }

    /** Вход по email или по телефону */
    public static function find(string $login, string $password): ?User
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            $u = User::where('email', mb_strtolower($login))->first();

            return $u && Hash::check($password, $u->password) ? $u : null;
        }
        $d = self::digits($login);
        if (strlen($d) < 9) {
            return null;
        }
        foreach (User::whereNotNull('phone')->get() as $u) {
            if (self::digits($u->phone) === $d && Hash::check($password, $u->password)) {
                return $u;
            }
        }

        return null;
    }
}
