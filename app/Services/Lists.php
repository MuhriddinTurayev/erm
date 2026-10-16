<?php

namespace App\Services;

use App\Models\Workspace;
use App\Services\Erp\Money;

/**
 * Справочники компании, которые раньше были зашиты в программу: способы оплаты, условия оплаты квартир,
 * источники заявок, единицы измерения. Компания меняет их в «Настройках»; в формах можно выбрать из списка
 * или вписать своё. Пока компания не меняла список — берётся стандартный (способы оплаты — по стране, по валюте компании).
 */
class Lists
{
    /** ключ => [название, подсказка] */
    public const KEYS = [
        'pay_methods' => ['Способы оплаты', 'Как покупатели и контрагенты платят: наличные, карта, перевод, платёжные системы вашей страны.'],
        'pay_plans' => ['Условия оплаты квартир', 'Число в названии — сколько месяцев рассрочки: «Рассрочка 18 мес». Без числа — оплата сразу, «Ипотека» — через банк.'],
        'lead_sources' => ['Источники заявок', 'Откуда приходят покупатели — для отчёта «какая реклама работает».'],
        'units' => ['Единицы измерения', 'Для материалов на складе и в Telegram-боте.'],
    ];

    public const MAX = 40;

    /** Стандартный список (Russian-ключи переводятся при показе, свои названия показываются как есть) */
    public static function defaults(?Workspace $ws, string $key): array
    {
        return match ($key) {
            'pay_methods' => array_merge(['Наличные', 'Банковская карта', 'Перевод на счёт'], match ($ws ? Money::base($ws) : 'UZS') {
                'UZS' => ['Click', 'Payme', 'Uzum'],
                'TJS' => ['Алиф', 'Душанбе Сити', 'Корти Милли'],
                'RUB' => ['СБП'],
                'KZT' => ['Kaspi'],
                default => [],
            }),
            'pay_plans' => ['Полная оплата', 'Рассрочка 6 мес', 'Рассрочка 12 мес', 'Рассрочка 24 мес', 'Рассрочка 36 мес', 'Ипотека'],
            'lead_sources' => ['Instagram', 'Facebook', 'WhatsApp', 'Telegram', 'OLX', 'Сайт', 'Звонок', 'Офис продаж', 'Рекомендация', 'Баннер / реклама'],
            'units' => ['шт', 'кг', 'т', 'л', 'м', 'м²', 'м³', 'мешок', 'пачка', 'рулон', 'лист', 'комплект'],
            default => [],
        };
    }

    public static function get(?Workspace $ws, string $key): array
    {
        $own = $ws ? $ws->setting('lists.'.$key) : null;

        return is_array($own) && $own ? array_values($own) : self::defaults($ws, $key);
    }

    /** Свой ли список у компании (иначе стандартный) */
    public static function own(Workspace $ws, string $key): bool
    {
        return is_array($ws->setting('lists.'.$key)) && $ws->setting('lists.'.$key);
    }

    public static function all(?Workspace $ws): array
    {
        return collect(array_keys(self::KEYS))->mapWithKeys(fn ($k) => [$k => self::get($ws, $k)])->all();
    }

    /** Сохранить список: без пустых и повторов, не длиннее MAX; пустой — вернуть стандартный */
    public static function save(Workspace $ws, string $key, array $items): void
    {
        $seen = [];
        $clean = [];
        foreach ($items as $x) {
            $x = trim(preg_replace('/\s+/u', ' ', (string) $x));
            if ($x === '' || isset($seen[mb_strtolower($x)])) {
                continue;
            }
            $seen[mb_strtolower($x)] = 1;
            $clean[] = mb_substr($x, 0, 60);
        }
        $ws->putSetting('lists.'.$key, $clean ? array_slice($clean, 0, self::MAX) : null);
    }

    /** Сколько месяцев рассрочки в условии оплаты: число в названии (0 — сразу или ипотека) */
    public static function planMonths(?string $plan): int
    {
        return (int) preg_replace('/\D/', '', (string) $plan);
    }
}
