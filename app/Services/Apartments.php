<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Квартиры объекта — тот же расчёт, что в программе (genApts в calc.js), плюс график оплат */
class Apartments
{
    private const MIX_BASE = [1 => 40, 2 => 62, 3 => 84, 4 => 105];

    public const STATUS = ['free' => 'Свободна', 'booked' => 'Бронь', 'sold' => 'Продана', 'reserve' => 'Не в продаже'];

    /**
     * Все помещения объекта со статусом, ценой и оплатами. Если перечень заведён вручную (корпуса, подъезды, квартиры) —
     * из него, иначе — рассчитанные по параметрам здания, как раньше.
     */
    public static function list(array $p): array
    {
        $apts = (array) ($p['apts'] ?? []);
        $rows = Inventory::has($p) ? Inventory::rows($p) : self::generated($p);

        return array_map(fn (array $a) => self::withDeal($a, (array) ($apts[$a['id']] ?? [])), $rows);
    }

    /** Статус, цена, покупатель и оплаты помещения (из p.apts) */
    private static function withDeal(array $a, array $o): array
    {
        $a['status'] = $o['status'] ?? 'free';
        $a['price'] = (float) ($o['price'] ?? 0) ?: $a['basePrice'];
        $a['buyer'] = (string) ($o['buyer'] ?? '');
        $a['phone'] = (string) ($o['phone'] ?? '');
        $a['plan'] = (string) ($o['plan'] ?? '');
        $a['payments'] = array_values((array) ($o['payments'] ?? []));
        $a['paid'] = array_sum(array_map(fn ($x) => (float) ($x['amount'] ?? 0), $a['payments']));

        return $a;
    }

    /** Параметры здания с умолчаниями программы */
    public static function buildParams(array $p): array
    {
        return array_merge(['length' => 56, 'width' => 17, 'floors' => 12, 'sections' => 2, 'aptsPerFloor' => 4, 'commercialFirst' => true, 'sellableK' => 0.72,
            'mix' => '1,2,2,3', 'pricePerM2' => 11000000, 'commercialPrice' => 14000000, 'floorStep' => 0.5], (array) ($p['b'] ?? []));
    }

    /** Квартиры, рассчитанные по параметрам здания (без статусов) — тот же расчёт, что genApts в программе */
    public static function generated(array $p): array
    {
        $b = self::buildParams($p);
        $mix = array_values(array_filter(array_map(fn ($x) => min(4, max(1, (int) $x)), preg_split('/[^0-9]+/', (string) $b['mix']))));
        if (! $mix) {
            $mix = [2];
        }
        $per = [];
        for ($i = 0; $i < (int) $b['aptsPerFloor']; $i++) {
            $per[] = $mix[$i % count($mix)];
        }
        $sumBase = array_sum(array_map(fn ($r) => self::MIX_BASE[$r], $per)) ?: 1;
        $fp = (float) $b['length'] * (float) $b['width'];
        $secArea = $fp * (float) $b['sellableK'] / max(1, (int) $b['sections']);
        $scale = $secArea / $sumBase;
        $out = [];
        $n = 0;
        $push = function (array $a) use (&$out) {
            $a['basePrice'] = round($a['basePrice'] / 1000) * 1000;
            $out[] = $a + ['kind' => $a['rooms'] ? 'flat' : 'commercial', 'type' => null, 'typeName' => '', 'block' => '', 'blockId' => '', 'entr' => (string) $a['section'], 'entrId' => ''];
        };
        $f0 = $b['commercialFirst'] ? 2 : 1;
        for ($s = 1; $s <= (int) $b['sections']; $s++) {
            if ($b['commercialFirst']) {
                $area = round($secArea, 1);
                $push(['id' => "K-$s", 'num' => 'К'.$s, 'section' => $s, 'floor' => 1, 'idx' => 0, 'rooms' => 0, 'area' => $area, 'basePrice' => $area * (float) $b['commercialPrice']]);
            }
            for ($f = $f0; $f <= (int) $b['floors']; $f++) {
                for ($i = 0; $i < (int) $b['aptsPerFloor']; $i++) {
                    $n++;
                    $rooms = $per[$i];
                    $area = round(self::MIX_BASE[$rooms] * $scale, 1);
                    $push(['id' => "$s-$f-$i", 'num' => (string) $n, 'section' => $s, 'floor' => $f, 'idx' => $i, 'rooms' => $rooms, 'area' => $area,
                        'basePrice' => $area * (float) $b['pricePerM2'] * (1 + ($f - $f0) * (float) $b['floorStep'] / 100)]);
                }
            }
        }

        return $out;
    }

    public static function find(array $p, string $id): ?array
    {
        foreach (self::list($p) as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }

        return null;
    }

    /**
     * График оплат по рассрочке: [['date' => Y-m-d, 'amount' => …, 'paid' => bool], …]
     * Первый платёж — взнос, остаток делится поровну на N месяцев (как в программе).
     */
    public static function schedule(array $a): array
    {
        $pays = $a['payments'];
        usort($pays, fn ($x, $y) => strcmp($x['date'] ?? '', $y['date'] ?? ''));
        $months = (int) preg_replace('/\D/', '', $a['plan']);
        if (! $pays || ! $months) {
            return [];
        }
        $first = $pays[0];
        $rest = max(0, $a['price'] - (float) $first['amount']);
        $part = $rest / $months;
        $start = Carbon::parse($first['date']);
        $out = [['date' => $start->toDateString(), 'amount' => (float) $first['amount']]];
        for ($k = 1; $k <= $months; $k++) {
            $out[] = ['date' => $start->copy()->addDays((int) round($k * 30.4))->toDateString(), 'amount' => $part];
        }
        // отмечаем оплаченные: накопленный итог платежей покрывает накопленный итог графика
        $cum = 0;
        $paid = $a['paid'];
        foreach ($out as &$row) {
            $cum += $row['amount'];
            $row['paid'] = $paid + 1000 >= $cum;
        }

        return $out;
    }

    /** Просрочка: сколько должно быть оплачено к сегодняшнему дню, минус оплачено */
    public static function overdue(array $a, ?string $today = null): float
    {
        $today = $today ?: now()->toDateString();
        $due = 0;
        foreach (self::schedule($a) as $row) {
            if ($row['date'] <= $today) {
                $due += $row['amount'];
            }
        }

        return max(0, round($due - $a['paid']));
    }

    /** Ближайший неоплаченный платёж */
    public static function nextPayment(array $a): ?array
    {
        foreach (self::schedule($a) as $row) {
            if (! $row['paid']) {
                return $row;
            }
        }

        return null;
    }
}
