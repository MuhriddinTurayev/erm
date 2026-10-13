<?php

namespace App\Services\Erp;

use App\Models\Workspace;
use App\Services\CompanyStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Валюта компании (сум, сомони, рубль, тенге, сом, доллар, евро) и доллар: курс ЦБ, пересчёт */
class Money
{
    public const CODES = ['UZS', 'TJS', 'RUB', 'KZT', 'KGS', 'USD', 'EUR'];

    /** Запасные курсы 1 USD (если банк не ответил) */
    private const FALLBACK = ['UZS' => 12800, 'RUB' => 82, 'TJS' => 10.9, 'KZT' => 480, 'KGS' => 87, 'EUR' => 0.92, 'USD' => 1];

    /** Код валюты по её названию в программе: «сум», «смн», «₽», «$», «₸», «сом», «€», «USD»… */
    public static function code(string $name): string
    {
        $c = mb_strtolower(trim($name));
        $c = preg_replace('/\s+/u', '', $c);

        return match (true) {
            $c === '' => 'UZS',
            str_contains($c, 'смн') || str_contains($c, 'сомон') || str_contains($c, 'somon') || str_contains($c, 'tjs') => 'TJS',
            str_contains($c, 'руб') || str_contains($c, '₽') || str_contains($c, 'rub') || $c === 'р' || $c === 'р.' => 'RUB',
            str_contains($c, '₸') || str_contains($c, 'тенге') || str_contains($c, 'тг') || str_contains($c, 'kzt') || str_contains($c, 'tenge') => 'KZT',
            str_contains($c, 'kgs') || $c === 'сом' || str_contains($c, 'сомкг') || $c === 'som' => 'KGS',
            str_contains($c, '$') || str_contains($c, 'usd') || str_contains($c, 'долл') || str_contains($c, 'dollar') => 'USD',
            str_contains($c, '€') || str_contains($c, 'eur') || str_contains($c, 'евро') => 'EUR',
            default => 'UZS',   // «сум», «so'm», «uzs» и всё остальное — узбекский сум
        };
    }

    /** Код валюты компании по её настройке в программе */
    public static function base(Workspace $ws): string
    {
        return self::code(CompanyStats::currency($ws));
    }

    /** Валюты, в которых компания ведёт кассы и договоры: своя и доллар (без повторов) */
    public static function currencies(Workspace $ws): array
    {
        return array_values(array_unique([self::base($ws), 'USD']));
    }

    /** Курс 1 USD в валюте компании: заданный компанией, иначе курс Центробанка */
    public static function usdRate(Workspace $ws): float
    {
        $base = self::base($ws);
        if ($base === 'USD') {
            return 1.0;
        }

        return (float) $ws->usd_rate > 0 ? (float) $ws->usd_rate : self::cbRate($base);
    }

    /** Официальный курс 1 USD в валюте $base (кэш на 6 часов) */
    public static function cbRate(string $base): float
    {
        if ($base === 'USD') {
            return 1.0;
        }

        return (float) Cache::remember('usd_rate_'.$base, now()->addHours(6), function () use ($base) {
            try {
                if ($base === 'UZS') {
                    $r = Http::timeout(6)->get('https://cbu.uz/uz/arkhiv-kursov-valyut/json/USD/')->json();
                    if ((float) ($r[0]['Rate'] ?? 0) > 0) {
                        return (float) $r[0]['Rate'];
                    }
                }
                if ($base === 'TJS') {
                    $xml = Http::timeout(6)->get('https://nbt.tj/ru/kurs/export_xml.php', ['date' => now()->format('Y-m-d'), 'export' => 'xmlout'])->body();
                    if (preg_match('/<CharCode>USD<\/CharCode>.*?<Value>([\d.,]+)<\/Value>/s', $xml, $m)) {
                        return (float) str_replace(',', '.', $m[1]);
                    }
                }
                // ЦБ России: курс доллара в рублях и кросс-курс к тенге, сому, евро и др.
                $r = Http::timeout(6)->get('https://www.cbr-xml-daily.ru/daily_json.js')->json();
                $usdRub = (float) ($r['Valute']['USD']['Value'] ?? 0);
                if ($usdRub > 0) {
                    if ($base === 'RUB') {
                        return $usdRub;
                    }
                    $v = $r['Valute'][$base] ?? null;
                    if ($v && (float) $v['Value'] > 0) {
                        return round($usdRub / ((float) $v['Value'] / max(1, (float) ($v['Nominal'] ?? 1))), 4);
                    }
                }
            } catch (\Throwable) {
            }

            return self::FALLBACK[$base] ?? 1;
        });
    }

    /** Курс валюты к валюте компании (сколько единиц валюты компании стоит 1 единица $currency) */
    public static function rate(Workspace $ws, string $currency): float
    {
        $base = self::base($ws);
        if ($currency === $base) {
            return 1.0;
        }
        if ($currency === 'USD') {
            return self::usdRate($ws);
        }
        // любая другая валюта — через доллар
        $perUsd = self::cbRate($currency);

        return $perUsd > 0 ? round(self::usdRate($ws) / $perUsd, 6) : 1.0;
    }

    public static function toBase(Workspace $ws, float $amount, string $currency, ?float $rate = null): float
    {
        return round($amount * ($rate ?: self::rate($ws, $currency)), 2);
    }

    /** Из валюты компании в другую валюту по текущему курсу */
    public static function fromBase(Workspace $ws, float $amountBase, string $currency): float
    {
        $r = self::rate($ws, $currency);

        return round($r > 0 ? $amountBase / $r : $amountBase, 2);
    }

    /**
     * Проверка курса, который ввёл сотрудник: не дальше чем на $pct% от официального.
     * Ошибка в курсе «теряет» деньги при обмене, поэтому произвольный курс не принимаем.
     */
    public static function rateOk(Workspace $ws, string $currency, float $rate, float $pct = 20): bool
    {
        $ref = self::rate($ws, $currency);
        if ($ref <= 0 || $rate <= 0) {
            return false;
        }

        return abs($rate - $ref) / $ref * 100 <= $pct;
    }

    public static function sign(string $currency): string
    {
        return ['USD' => '$', 'UZS' => 'сум', 'TJS' => 'смн', 'RUB' => '₽', 'KZT' => '₸', 'KGS' => 'сом', 'EUR' => '€'][$currency] ?? $currency;
    }

    public static function fmt(float $v, string $currency): string
    {
        if (in_array($currency, ['USD', 'EUR'], true)) {
            return self::sign($currency).number_format($v, 2, '.', ' ');
        }

        return number_format(round($v), 0, '.', ' ').' '.__(self::sign($currency));
    }
}
