<?php

namespace App\Services\Api;

/**
 * Описание API в одном месте: из него — страница документации в программе и OpenAPI (для Postman, Swagger, генераторов кода).
 * Параметр: [имя, где (query|body|path), тип, описание, обязателен].
 */
class Spec
{
    public const VERSION = '1.0';

    private const PAGE = [['page', 'query', 'integer', 'Страница (с 1)', false], ['per_page', 'query', 'integer', 'Записей на странице: 1–200, по умолчанию 50', false]];

    private const SINCE = ['updated_since', 'query', 'string', 'Только изменённые после (ISO 8601: 2026-10-01T00:00:00+05:00)', false];

    public static function endpoints(): array
    {
        $page = self::PAGE;

        return [
            ['GET', '/me', null, 'Компания, права и срок этого ключа', []],
            ['GET', '/users', 'tasks:read', 'Сотрудники компании: id, имя, роль — чтобы назначить исполнителя задачи', []],

            ['GET', '/objects', 'objects:read', 'Объекты: адрес, этап, готовность, сколько квартир свободно, в брони, продано', []],
            ['GET', '/objects/{object}', 'objects:read', 'Один объект', [['object', 'path', 'string', 'id объекта', true]]],
            ['GET', '/objects/{object}/apartments', 'objects:read', 'Квартиры и помещения объекта: подъезд, этаж, комнаты, площадь, статус, цена. С правом sales:read — ещё покупатель, условия и оплаты', [
                ['object', 'path', 'string', 'id объекта', true], ['status', 'query', 'string', 'free | booked | sold | reserve', false],
                ['rooms', 'query', 'integer', 'Комнат (0 — коммерческое помещение)', false], ['floor', 'query', 'integer', 'Этаж', false], ...$page]],
            ['GET', '/objects/{object}/apartments/{apartment}', 'objects:read', 'Одна квартира', [['object', 'path', 'string', 'id объекта', true], ['apartment', 'path', 'string', 'id квартиры', true]]],
            ['PATCH', '/objects/{object}/apartments/{apartment}', 'sales:write', 'Бронь, продажа, цена, снять бронь. Передайте только то, что меняется', [
                ['object', 'path', 'string', 'id объекта', true], ['apartment', 'path', 'string', 'id квартиры', true],
                ['status', 'body', 'string', 'free | booked | sold | reserve', false], ['buyer_name', 'body', 'string', 'Покупатель (обязателен для booked и sold)', false],
                ['buyer_phone', 'body', 'string', 'Телефон покупателя', false], ['price', 'body', 'number', 'Цена квартиры', false],
                ['plan', 'body', 'string', 'Условия оплаты — из справочника компании или свои; число в названии — месяцев рассрочки', false], ['note', 'body', 'string', 'Заметка', false]]],
            ['GET', '/sales/payments', 'sales:read', 'Оплаты покупателей по квартирам за период', [
                ['object', 'query', 'string', 'id объекта (без него — все объекты)', false], ['from', 'query', 'string', 'С даты (YYYY-MM-DD)', false], ['to', 'query', 'string', 'По дату (YYYY-MM-DD)', false]]],

            ['GET', '/leads', 'leads:read', 'Входящие заявки клиентов (сайт, Facebook, Instagram, WhatsApp, API) — новые сверху', [['since_id', 'query', 'integer', 'Только заявки с id больше этого (для синхронизации)', false], ...$page]],
            ['POST', '/leads', 'leads:write', 'Добавить заявку — появится в «Заявках» программы', [
                ['name', 'body', 'string', 'Имя клиента (имя или телефон обязательны)', false], ['phone', 'body', 'string', 'Телефон', false],
                ['source', 'body', 'string', 'Источник: сайт, OLX, Instagram, другая CRM…', false], ['rooms', 'body', 'integer', 'Сколько комнат ищет', false],
                ['budget', 'body', 'integer', 'Бюджет', false], ['message', 'body', 'string', 'Комментарий клиента', false]]],

            ['GET', '/stock/items', 'stock:read', 'Товары и материалы с остатками по складам', [['q', 'query', 'string', 'Поиск: название, артикул, группа', false], ['low', 'query', 'boolean', '1 — только ниже минимума', false], self::SINCE, ...$page]],
            ['GET', '/stock/warehouses', 'stock:read', 'Склады', []],
            ['GET', '/stock/moves', 'stock:read', 'Движения по складу: приход, выдача на объект, перемещение, инвентаризация', [
                ['from', 'query', 'string', 'С даты', false], ['to', 'query', 'string', 'По дату', false], ['type', 'query', 'string', 'in | out | transfer | adjust', false],
                ['item', 'query', 'integer', 'id товара', false], ['warehouse', 'query', 'integer', 'id склада', false], ['object', 'query', 'string', 'id объекта', false], ...$page]],

            ['GET', '/cash/desks', 'cash:read', 'Кассы и счета с остатками', []],
            ['GET', '/cash/operations', 'cash:read', 'Операции по кассам', [
                ['from', 'query', 'string', 'С даты', false], ['to', 'query', 'string', 'По дату', false], ['desk', 'query', 'integer', 'id кассы', false],
                ['type', 'query', 'string', 'in | out', false], ['object', 'query', 'string', 'id объекта', false], ...$page]],

            ['GET', '/payment-requests', 'payments:read', 'Заявки на оплату и их статусы', [['status', 'query', 'string', 'pending | approved | rejected | paid | cancelled', false], self::SINCE, ...$page]],

            ['GET', '/counterparties', 'parties:read', 'Контрагенты: поставщики, подрядчики, клиенты', [
                ['q', 'query', 'string', 'Поиск: название, ИНН, телефон', false], ['kind', 'query', 'string', 'supplier | contractor | client | other', false],
                ['inn', 'query', 'string', 'ИНН', false], self::SINCE, ...$page]],
            ['GET', '/counterparties/{id}', 'parties:read', 'Один контрагент', [['id', 'path', 'integer', 'id контрагента', true]]],
            ['POST', '/counterparties', 'parties:write', 'Добавить контрагента (попадёт «на проверку» к руководителю)', self::partyFields(true)],
            ['PATCH', '/counterparties/{id}', 'parties:write', 'Изменить контрагента', array_merge([['id', 'path', 'integer', 'id контрагента', true]], self::partyFields(false))],

            ['GET', '/tasks', 'tasks:read', 'Задачи', [
                ['status', 'query', 'string', 'todo | doing | review | done', false], ['object', 'query', 'string', 'id объекта', false],
                ['assignee', 'query', 'integer', 'id исполнителя', false], self::SINCE, ...$page]],
            ['GET', '/tasks/{id}', 'tasks:read', 'Одна задача', [['id', 'path', 'integer', 'id задачи', true]]],
            ['POST', '/tasks', 'tasks:write', 'Поставить задачу — исполнитель получит её в Telegram', self::taskFields(true)],
            ['PATCH', '/tasks/{id}', 'tasks:write', 'Изменить задачу: статус, прогресс, срок, исполнитель', array_merge([['id', 'path', 'integer', 'id задачи', true]], self::taskFields(false), [
                ['status', 'body', 'string', 'todo | doing | review | done', false], ['progress', 'body', 'integer', '0–100', false]])],
        ];
    }

    private static function partyFields(bool $create): array
    {
        return [
            ['name', 'body', 'string', 'Название', $create], ['kind', 'body', 'string', 'supplier | contractor | client | other', $create],
            ['inn', 'body', 'string', 'ИНН (только цифры)', false], ['phone', 'body', 'string', 'Телефон', false], ['contact', 'body', 'string', 'Контактное лицо', false],
            ['address', 'body', 'string', 'Адрес', false], ['bank', 'body', 'string', 'Банковские реквизиты', false], ['folder', 'body', 'string', 'Папка', false], ['note', 'body', 'string', 'Заметка', false],
        ];
    }

    private static function taskFields(bool $create): array
    {
        return [
            ['title', 'body', 'string', 'Что сделать', $create], ['description', 'body', 'string', 'Подробности', false],
            ['object_id', 'body', 'string', 'id объекта', false], ['assignee_id', 'body', 'integer', 'id исполнителя (GET /users)', false],
            ['due_date', 'body', 'string', 'Срок (YYYY-MM-DD)', false], ['priority', 'body', 'string', 'low | normal | high', false],
        ];
    }

    /** Раздел для группировки на странице: по праву, а без права — «Общее» */
    public static function module(?string $scope): string
    {
        return $scope ? explode(':', $scope)[0] : 'common';
    }

    /** OpenAPI 3.0 — импорт в Postman, Insomnia, Swagger UI */
    public static function openapi(string $base): array
    {
        $paths = [];
        foreach (self::endpoints() as [$method, $path, $scope, $summary, $params]) {
            $op = ['summary' => $summary, 'tags' => [self::module($scope)], 'responses' => [
                '200' => ['description' => 'OK'], '401' => ['description' => 'No or invalid API key'], '403' => ['description' => 'Key has no access (scope)'],
                '404' => ['description' => 'Not found'], '422' => ['description' => 'Validation error'], '429' => ['description' => 'Rate limit'],
            ]];
            if ($scope) {
                $op['description'] = 'Scope: '.$scope;
            }
            $body = [];
            foreach ($params as [$name, $in, $type, $desc, $req]) {
                if ($in === 'body') {
                    $body['properties'][$name] = ['type' => $type, 'description' => $desc];
                    if ($req) {
                        $body['required'][] = $name;
                    }
                } else {
                    $op['parameters'][] = ['name' => $name, 'in' => $in, 'required' => $in === 'path' || $req, 'description' => $desc, 'schema' => ['type' => $type]];
                }
            }
            if ($body) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object'] + $body]]];
            }
            $paths[$path][strtolower($method)] = $op;
        }

        return [
            'openapi' => '3.0.3',
            'info' => ['title' => \App\Models\PlatformSetting::brand().' API', 'version' => self::VERSION,
                'description' => 'Bearer key from «Company → API and integrations». Responses: {"data": …, "meta": …}; errors: {"error": {"code", "message"}}.'],
            'servers' => [['url' => rtrim($base, '/').'/api/v1']],
            'components' => ['securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']]],
            'security' => [['bearer' => []]],
            'paths' => $paths,
        ];
    }
}
