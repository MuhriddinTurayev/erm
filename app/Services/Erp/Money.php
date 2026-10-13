<?php

namespace App\Services\Erp;

use App\Models\Workspace;
use App\Services\CompanyStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Валюта компании (сум или сомони) и доллар: курс ЦБ, пересчёт */
class Money
{
    /** Код валюты компании по её настройке в программе */
    public static function base(Workspace $ws): string
    {
        $c = mb_strtolower(CompanyStats::currency($ws));

        return match (true) {
            str_contains($c, 'смн') || str_contains($c, 'сомон') || str_contains($c, 'tjs') => 'TJS',
            str_contains($c, 'руб') || str_contains($c, '₽') || str_contains($c, 'rub') => 'RUB',
            default => 'UZS',
        };
    }

    public static function currencies(Workspace $ws): array
    {
        return [self::base($ws), 'USD'];
    }

    /** Курс 1 USD в валюте компании: заданный компанией, иначе курс Центробанка */
    public static function usdRate(Workspace $ws): float
    {
        return (float) $ws->usd_rate > 0 ? (float) $ws->usd_rate : self::cbRate(self::base($ws));
    }

    /** Официальный курс: ЦБ Узбекистана или Нацбанк Таджикистана (кэш на 6 часов) */
    public static function cbRate(string $base): float
    {
        return (float) Cache::remember('usd_rate_'.$base, now()->addHours(6), function () use ($base) {
            try {
                if ($base === 'RUB') {
                    $r = Http::timeout(6)->get('https://www.cbr-xml-daily.ru/daily_json.js')->json();

                    return (float) ($r['Valute']['USD']['Value'] ?? 0) ?: 82;
                }
                if ($base === 'UZS') {
                    $r = Http::timeout(6)->get('https://cbu.uz/uz/arkhiv-kursov-valyut/json/USD/')->json();

                    return (float) ($r[0]['Rate'] ?? 0) ?: 12800;
                }
                $xml = Http::timeout(6)->get('https://nbt.tj/ru/kurs/export_xml.php', ['date' => now()->format('Y-m-d'), 'export' => 'xmlout'])->body();
                if (preg_match('/<CharCode>USD<\/CharCode>.*?<Value>([\d.,]+)<\/Value>/s', $xml, $m)) {
                    return (float) str_replace(',', '.', $m[1]);
                }
            } catch (\Throwable) {
            }

            return ['UZS' => 12800, 'RUB' => 82, 'TJS' => 10.9][$base] ?? 1;
        });
    }

    /** Курс валюты к валюте компании */
    public static function rate(Workspace $ws, string $currency): float
    {
        return $currency === 'USD' ? self::usdRate($ws) : 1.0;
    }

    public static function toBase(Workspace $ws, float $amount, string $currency, ?float $rate = null): float
    {
        return round($amount * ($rate ?: self::rate($ws, $currency)), 2);
    }

    public static function fmt(float $v, string $currency): string
    {
        $sign = ['USD' => '$', 'UZS' => 'сум', 'TJS' => 'смн', 'RUB' => '₽'][$currency] ?? $currency;

        return $currency === 'USD' ? '$'.number_format($v, 2, '.', ' ') : number_format(round($v), 0, '.', ' ').' '.__($sign);
    }
}
