<?php

namespace App\Services\Api;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;

/**
 * Что может ключ API. Ключ выдаёт только администратор платформы и привязывает его к компании и роли:
 * ключ делает ровно то, что сотрудник с этой ролью (права ролей компания настраивает сама), и видит только его объекты.
 */
class Scopes
{
    /** раздел API => [название, иконка, [доступ => что даёт]] */
    public const MODULES = [
        'objects' => ['Объекты и квартиры', 'building-2', ['read' => 'объекты, квартиры, статусы и цены']],
        'sales' => ['Продажи квартир', 'key-round', ['read' => 'покупатели, условия и оплаты', 'write' => 'бронь, продажа, цена, снять бронь']],
        'leads' => ['Заявки клиентов', 'inbox', ['read' => 'входящие заявки', 'write' => 'добавить заявку (сайт, реклама, другая CRM)']],
        'stock' => ['Склад', 'warehouse', ['read' => 'товары, остатки, движения']],
        'cash' => ['Кассы и счета', 'wallet-cards', ['read' => 'остатки и операции']],
        'payments' => ['Заявки на оплату', 'file-check', ['read' => 'заявки и их статусы']],
        'parties' => ['Контрагенты', 'contact', ['read' => 'список и карточки', 'write' => 'добавить и изменить']],
        'tasks' => ['Задачи', 'list-checks', ['read' => 'задачи и их статусы', 'write' => 'поставить задачу, сменить статус']],
    ];

    /** Роли, которые могут бронировать и продавать квартиры (как на странице «Продажи») */
    private const SELLERS = ['admin', 'manager', 'accountant', 'cashier'];

    /** API включён на платформе (администратор может выключить его целиком — ключи и вебхуки сохраняются) */
    public static function enabled(): bool
    {
        return PlatformSetting::get('api_enabled', '1') === '1';
    }

    /** «Сотрудник», от имени которого работает ключ: та же роль и те же объекты (в базе не хранится) */
    public static function actor(Workspace $ws, string $role, ?array $objects = null): User
    {
        $u = new User(['workspace_id' => $ws->id, 'name' => 'API', 'role' => $role, 'object_ids' => $objects ?: null, 'is_active' => true]);
        $u->setRelation('workspace', $ws);

        return $u;
    }

    /** Права роли в компании: ['objects:read', 'sales:read', …]. Только чтение — без записи. */
    public static function forRole(Workspace $ws, string $role, bool $readOnly = false): array
    {
        if (! array_key_exists($role, (array) config('oftob.roles'))) {
            return [];
        }
        $u = self::actor($ws, $role);
        $edit = ! $readOnly && $u->canEdit();
        $out = ['objects:read'];
        if ($u->canModule('sales')) {
            array_push($out, 'sales:read', 'leads:read');
            if ($edit && in_array($role, self::SELLERS, true)) {
                $out[] = 'sales:write';
            }
            if ($edit) {
                $out[] = 'leads:write';
            }
        }
        if ($u->canModule('warehouse')) {
            $out[] = 'stock:read';
        }
        if ($u->canModule('cash')) {
            $out[] = 'cash:read';
        }
        if ($u->canModule('payments')) {
            $out[] = 'payments:read';
        }
        if ($u->canModule('counterparties')) {
            $out[] = 'parties:read';
            if ($edit) {
                $out[] = 'parties:write';
            }
        }
        if ($u->canModule('tasks')) {
            $out[] = 'tasks:read';
            if ($edit) {
                $out[] = 'tasks:write';
            }
        }

        return $out;
    }

    /** Все права: ['objects:read', 'sales:read', 'sales:write', …] */
    public static function all(): array
    {
        $out = [];
        foreach (self::MODULES as $m => [, , $acc]) {
            foreach (array_keys($acc) as $a) {
                $out[] = "$m:$a";
            }
        }

        return $out;
    }

    /** Подпись права для людей: «Склад — чтение» */
    public static function label(string $scope): string
    {
        [$m, $a] = array_pad(explode(':', $scope, 2), 2, '');

        return isset(self::MODULES[$m]) ? __(self::MODULES[$m][0]).' — '.($a === 'write' ? __('запись') : __('чтение')) : $scope;
    }

    /** Права по разделам для показа: [раздел => 'write'|'read'] */
    public static function summary(array $scopes): array
    {
        $out = [];
        foreach (self::MODULES as $m => $x) {
            if (in_array("$m:write", $scopes, true)) {
                $out[$m] = 'write';
            } elseif (in_array("$m:read", $scopes, true)) {
                $out[$m] = 'read';
            }
        }

        return $out;
    }
}
