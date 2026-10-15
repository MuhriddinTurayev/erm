<?php

namespace App\Services\Api;

/** Права ключа API: раздел + чтение/запись. Ключ видит только то, что ему разрешили. */
class Scopes
{
    /** раздел => [название, иконка, [доступ => что даёт]] */
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

    /** Готовые наборы прав — чтобы не выбирать галочки по одной */
    public const PRESETS = [
        'site' => ['Сайт: шахматка и заявки', ['objects:read', 'leads:write']],
        'crm' => ['Другая CRM (amoCRM, Bitrix24)', ['objects:read', 'sales:read', 'sales:write', 'leads:read', 'leads:write']],
        'accounting' => ['Бухгалтерия (1С и др.)', ['cash:read', 'payments:read', 'parties:read', 'parties:write', 'stock:read']],
        'read' => ['Только чтение — всё', null],
    ];

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

    public static function readOnly(): array
    {
        return array_values(array_filter(self::all(), fn ($s) => str_ends_with($s, ':read')));
    }

    /** Права набора (null — только чтение всего) */
    public static function preset(string $key): array
    {
        return self::PRESETS[$key][1] ?? self::readOnly();
    }

    /** Подпись права для людей: «Склад — чтение» */
    public static function label(string $scope): string
    {
        [$m, $a] = array_pad(explode(':', $scope, 2), 2, '');

        return isset(self::MODULES[$m]) ? __(self::MODULES[$m][0]).' — '.($a === 'write' ? __('запись') : __('чтение')) : $scope;
    }

    /**
     * Оставить только известные права, без повторов; запись включает чтение, продажи — список квартир.
     * Кроме заявок: ключ сайта добавляет заявки, но чужие заявки (телефоны клиентов) не читает.
     */
    public static function clean(array $scopes): array
    {
        $all = self::all();
        $out = array_values(array_intersect($all, $scopes));
        foreach ($out as $s) {
            if (str_ends_with($s, ':write') && $s !== 'leads:write') {
                $out[] = str_replace(':write', ':read', $s);
            }
            if (str_starts_with($s, 'sales:')) {
                $out[] = 'objects:read';
            }
        }

        return array_values(array_intersect($all, array_unique($out)));
    }
}
