<?php

namespace App\Services;

/**
 * Перечень помещений объекта, заведённый вручную: корпуса (блоки) → подъезды → этажи → квартиры,
 * планировки (типы квартир), коммерция, паркинг, кладовые и правила цены.
 * Хранится в данных объекта (p.inv). Пока перечня нет — квартиры считаются по параметрам здания (Apartments::generated).
 *
 * Сделки, оплаты и статусы по-прежнему живут в p.apts[id] — перечень их не трогает, поэтому продажи,
 * кабинет покупателя, бухгалтерия и отчёты работают как раньше.
 *
 * Формат (короткие ключи у помещений — их бывают тысячи):
 *   blocks: [{id, name, floors, ppm2?, entr: [{id, name}]}]
 *   types:  [{id, name, kind, rooms, area, kitchen?, living?, ceiling?, note?}]
 *   units:  [{id, b, e, f, num, kind, type?, rooms, area, price?, note?}]   — price 0 → по правилу цены
 *   price:  {ppm2, step, from, commercial, storage, parking, round}
 *   ready:  дата, когда объект выставлен на продажу (false — ещё готовится)
 */
class Inventory
{
    public const KINDS = ['flat' => 'Квартира', 'commercial' => 'Коммерция', 'office' => 'Офис', 'parking' => 'Паркинг', 'storage' => 'Кладовая'];

    public const MAX_UNITS = 6000;

    public const MAX_TYPES = 300;

    public const MAX_BLOCKS = 60;

    public const MAX_ENTR = 40;

    private const ID = '/^[A-Za-z0-9_\-]{1,40}$/';

    /** Перечень заведён вручную (иначе квартиры считаются по параметрам здания) */
    public static function has(array $p): bool
    {
        return is_array($p['inv']['units'] ?? null) && $p['inv']['units'] !== [];
    }

    /** Выставлен ли объект на продажу. Объекты без ручного перечня — как раньше, всегда в продаже. */
    public static function ready(array $p): bool
    {
        return ! self::has($p) || ! empty($p['inv']['ready']);
    }

    public static function priceRule(array $inv): array
    {
        $r = (array) ($inv['price'] ?? []);

        return [
            'ppm2' => max(0, (float) ($r['ppm2'] ?? 0)), 'step' => (float) ($r['step'] ?? 0), 'from' => (int) ($r['from'] ?? 2),
            'commercial' => max(0, (float) ($r['commercial'] ?? 0)), 'storage' => max(0, (float) ($r['storage'] ?? 0)),
            'parking' => max(0, (float) ($r['parking'] ?? 0)), 'round' => max(1, (int) ($r['round'] ?? 1000)),
        ];
    }

    /** Цена помещения по правилу: квартира — м² × цена (корпуса или общая) с наценкой за этаж; паркинг — за место */
    public static function basePrice(array $u, array $rule, ?array $block = null): float
    {
        if ((float) ($u['price'] ?? 0) > 0) {
            return (float) $u['price'];
        }
        $area = (float) ($u['area'] ?? 0);
        $kind = (string) ($u['kind'] ?? 'flat');
        $v = match ($kind) {
            'commercial', 'office' => $area * $rule['commercial'],
            'storage' => $area * $rule['storage'],
            'parking' => $rule['parking'],
            default => $area * ((float) ($block['ppm2'] ?? 0) ?: $rule['ppm2']) * (1 + max(0, (int) ($u['f'] ?? 1) - $rule['from']) * $rule['step'] / 100),
        };

        return round($v / $rule['round']) * $rule['round'];
    }

    /**
     * Строки для Apartments::list — в том же виде, что у рассчитанных квартир, плюс корпус, подъезд, вид и планировка.
     * section — сквозной номер подъезда по всем корпусам (чтобы подъезды разных корпусов не смешивались).
     */
    public static function rows(array $p): array
    {
        $inv = (array) $p['inv'];
        $rule = self::priceRule($inv);
        $types = [];
        foreach ((array) ($inv['types'] ?? []) as $t) {
            $types[(string) ($t['id'] ?? '')] = $t;
        }
        $blocks = [];
        $sec = 0;
        foreach ((array) ($inv['blocks'] ?? []) as $bi => $b) {
            $ents = [];
            foreach ((array) ($b['entr'] ?? []) as $ei => $e) {
                $ents[(string) $e['id']] = ['n' => ++$sec, 'ord' => $ei, 'name' => (string) ($e['name'] ?? ($ei + 1))];
            }
            $blocks[(string) $b['id']] = ['ord' => $bi, 'b' => $b, 'name' => (string) ($b['name'] ?? ''), 'ents' => $ents];
        }
        $out = [];
        foreach ((array) $inv['units'] as $pos => $u) {
            $b = $blocks[(string) ($u['b'] ?? '')] ?? null;
            $e = $b['ents'][(string) ($u['e'] ?? '')] ?? null;
            if (! $b || ! $e) {
                continue;
            }
            $kind = (string) ($u['kind'] ?? 'flat');
            $t = $types[(string) ($u['type'] ?? '')] ?? null;
            $out[] = [
                'id' => (string) $u['id'], 'num' => (string) ($u['num'] ?? ''), 'section' => $e['n'], 'floor' => (int) ($u['f'] ?? 1),
                'rooms' => $kind === 'flat' ? max(1, (int) ($u['rooms'] ?? 1)) : 0, 'area' => round((float) ($u['area'] ?? 0), 2),
                'basePrice' => self::basePrice($u, $rule, $b['b']), 'kind' => $kind, 'type' => $t ? (string) $t['id'] : null, 'typeName' => $t ? (string) ($t['name'] ?? '') : '',
                'block' => $b['name'], 'blockId' => (string) $u['b'], 'entr' => $e['name'], 'entrId' => (string) $u['e'],
                '_o' => [$b['ord'], $e['ord'], (int) ($u['f'] ?? 1), $pos],
            ];
        }
        usort($out, fn ($x, $y) => $x['_o'] <=> $y['_o']);
        $idx = [];
        foreach ($out as &$a) {
            $k = $a['section'].'|'.$a['floor'];
            $a['idx'] = $idx[$k] = isset($idx[$k]) ? $idx[$k] + 1 : 0;
            unset($a['_o']);
        }
        unset($a);

        return $out;
    }

    /** Подпись подъезда для шахматки: «Блок A · подъезд 2», «Navruz-1 · подъезд 2» или просто «Подъезд 2» */
    public static function secLabel(array $a): string
    {
        $block = trim((string) ($a['block'] ?? ''));
        $entr = trim((string) ($a['entr'] ?? $a['section'] ?? ''));
        if ($block === '') {
            return __('Подъезд').' '.$entr;
        }

        return (mb_strlen($block) <= 3 ? __('Блок').' ' : '').$block.' · '.__('подъезд').' '.$entr;
    }

    /**
     * Перечень из рассчитанных квартир — с теми же номерами помещений (id), поэтому сделки, оплаты и ссылки покупателей
     * остаются на своих квартирах. Цены по тем же правилам, что и раньше.
     */
    public static function fromGenerator(array $p, ?string $locale = null): array
    {
        // названия планировок — на языке компании (их видят все сотрудники и покупатели), а не того, кто нажал кнопку
        $b = Apartments::buildParams($p);
        $apts = Apartments::generated($p);
        $f0 = $b['commercialFirst'] ? 2 : 1;
        $entr = [];
        for ($s = 1; $s <= max(1, (int) $b['sections']); $s++) {
            $entr[] = ['id' => 'e'.$s, 'name' => (string) $s];
        }
        $types = [];
        $byKey = [];
        $units = [];
        foreach ($apts as $a) {
            $type = null;
            if ($a['rooms'] > 0) {
                $k = $a['rooms'].'|'.$a['area'];
                if (! isset($byKey[$k])) {
                    $id = 't'.(count($types) + 1);
                    $byKey[$k] = $id;
                    $types[] = ['id' => $id, 'name' => $a['rooms'].'-'.__('комн.', [], $locale).' '.self::num($a['area']).' '.__('м²', [], $locale), 'kind' => 'flat', 'rooms' => $a['rooms'], 'area' => $a['area']];
                }
                $type = $byKey[$k];
            }
            $units[] = array_filter(['id' => $a['id'], 'b' => 'b1', 'e' => 'e'.$a['section'], 'f' => $a['floor'], 'num' => (string) $a['num'],
                'kind' => $a['rooms'] > 0 ? 'flat' : 'commercial', 'type' => $type, 'rooms' => $a['rooms'], 'area' => $a['area']], fn ($v) => $v !== null);
        }

        return [
            'v' => 1,
            'blocks' => [['id' => 'b1', 'name' => '', 'floors' => (int) $b['floors'], 'entr' => $entr]],
            'types' => $types,
            'units' => $units,
            'price' => ['ppm2' => (float) $b['pricePerM2'], 'step' => (float) $b['floorStep'], 'from' => $f0, 'commercial' => (float) $b['commercialPrice'],
                'storage' => 0, 'parking' => 0, 'round' => 1000],
            'ready' => now()->toDateString(),   // объект и так был в продаже
        ];
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }

    private static function str($v, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $v));

        return mb_substr($s, 0, $max);
    }

    private static function money($v): float
    {
        return max(0, min(1e15, round((float) $v, 2)));
    }

    /**
     * Проверить и привести к порядку перечень, присланный редактором.
     * Бросает \DomainException с понятным текстом. $deals — квартиры с действующей сделкой [id => №] (их нельзя удалить).
     */
    public static function clean(array $in, array $deals = [], ?array $old = null): array
    {
        $fail = fn (string $m) => throw new \DomainException($m);

        // корпуса и подъезды
        $blocks = [];
        $entrOf = [];
        foreach (array_values((array) ($in['blocks'] ?? [])) as $i => $b) {
            $id = (string) ($b['id'] ?? '');
            if (! preg_match(self::ID, $id) || isset($entrOf[$id])) {
                $fail(__('Ошибка в корпусе №:n', ['n' => $i + 1]));
            }
            $ents = [];
            $seen = [];
            foreach (array_values((array) ($b['entr'] ?? [])) as $j => $e) {
                $eid = (string) ($e['id'] ?? '');
                if (! preg_match(self::ID, $eid) || isset($seen[$eid])) {
                    $fail(__('Ошибка в подъезде №:n', ['n' => $j + 1]));
                }
                $seen[$eid] = true;
                $ents[] = ['id' => $eid, 'name' => self::str($e['name'] ?? ($j + 1), 30) ?: (string) ($j + 1)];
            }
            if (! $ents) {
                $fail(__('В корпусе «:b» нет ни одного подъезда', ['b' => self::str($b['name'] ?? '', 40) ?: $i + 1]));
            }
            if (count($ents) > self::MAX_ENTR) {
                $fail(__('Слишком много подъездов в одном корпусе (больше :n)', ['n' => self::MAX_ENTR]));
            }
            $entrOf[$id] = $seen;
            $blocks[] = array_filter(['id' => $id, 'name' => self::str($b['name'] ?? '', 40), 'floors' => max(1, min(150, (int) ($b['floors'] ?? 1))),
                'ppm2' => isset($b['ppm2']) && (float) $b['ppm2'] > 0 ? self::money($b['ppm2']) : null, 'entr' => $ents], fn ($v) => $v !== null);
        }
        if (! $blocks) {
            $fail(__('Добавьте хотя бы один корпус'));
        }
        if (count($blocks) > self::MAX_BLOCKS) {
            $fail(__('Слишком много корпусов (больше :n)', ['n' => self::MAX_BLOCKS]));
        }

        // планировки
        $types = [];
        foreach (array_values((array) ($in['types'] ?? [])) as $i => $t) {
            $id = (string) ($t['id'] ?? '');
            if (! preg_match(self::ID, $id) || isset($types[$id])) {
                $fail(__('Ошибка в планировке №:n', ['n' => $i + 1]));
            }
            $kind = array_key_exists((string) ($t['kind'] ?? 'flat'), self::KINDS) ? (string) ($t['kind'] ?? 'flat') : 'flat';
            $area = round((float) ($t['area'] ?? 0), 2);
            if ($area <= 0 || $area > 5000) {
                $fail(__('Укажите площадь планировки «:t»', ['t' => self::str($t['name'] ?? '', 60) ?: $i + 1]));
            }
            $types[$id] = array_filter([
                'id' => $id, 'name' => self::str($t['name'] ?? '', 60) ?: ($kind === 'flat' ? max(1, (int) ($t['rooms'] ?? 1)).'-'.__('комн.') : __(self::KINDS[$kind])),
                'kind' => $kind, 'rooms' => $kind === 'flat' ? max(1, min(9, (int) ($t['rooms'] ?? 1))) : 0, 'area' => $area,
                'kitchen' => (float) ($t['kitchen'] ?? 0) > 0 ? round(min($area, (float) $t['kitchen']), 2) : null,
                'living' => (float) ($t['living'] ?? 0) > 0 ? round(min($area, (float) $t['living']), 2) : null,
                'ceiling' => (float) ($t['ceiling'] ?? 0) > 0 ? round(max(2, min(12, (float) $t['ceiling'])), 2) : null,
                'note' => self::str($t['note'] ?? '', 300) ?: null,
            ], fn ($v) => $v !== null);
        }
        if (count($types) > self::MAX_TYPES) {
            $fail(__('Слишком много планировок (больше :n)', ['n' => self::MAX_TYPES]));
        }

        // помещения
        $units = [];
        $ids = [];
        foreach (array_values((array) ($in['units'] ?? [])) as $i => $u) {
            $id = (string) ($u['id'] ?? '');
            if (! preg_match(self::ID, $id) || isset($ids[$id])) {
                $fail(__('Ошибка в помещении №:n (повторяется или пустой номер)', ['n' => $i + 1]));
            }
            $ids[$id] = true;
            $bid = (string) ($u['b'] ?? '');
            $eid = (string) ($u['e'] ?? '');
            if (! isset($entrOf[$bid][$eid])) {
                $fail(__('Помещение №:n привязано к удалённому корпусу или подъезду', ['n' => self::str($u['num'] ?? $id, 12)]));
            }
            $kind = array_key_exists((string) ($u['kind'] ?? 'flat'), self::KINDS) ? (string) ($u['kind'] ?? 'flat') : 'flat';
            $type = isset($types[(string) ($u['type'] ?? '')]) ? (string) $u['type'] : null;
            $area = round((float) ($u['area'] ?? 0), 2);
            if ($area <= 0 && $type) {
                $area = $types[$type]['area'];
            }
            if ($area <= 0 || $area > 5000) {
                $fail(__('Укажите площадь помещения №:n', ['n' => self::str($u['num'] ?? $id, 12)]));
            }
            $rooms = $kind === 'flat' ? max(1, min(9, (int) ($u['rooms'] ?? ($type ? $types[$type]['rooms'] : 1)))) : 0;
            $units[] = array_filter([
                'id' => $id, 'b' => $bid, 'e' => $eid, 'f' => max(-10, min(150, (int) ($u['f'] ?? 1))), 'num' => self::str($u['num'] ?? '', 12) ?: (string) ($i + 1),
                'kind' => $kind, 'type' => $type, 'rooms' => $rooms, 'area' => $area,
                'price' => (float) ($u['price'] ?? 0) > 0 ? self::money($u['price']) : null, 'note' => self::str($u['note'] ?? '', 120) ?: null,
            ], fn ($v) => $v !== null);
        }
        if (count($units) > self::MAX_UNITS) {
            $fail(__('Слишком много помещений (больше :n)', ['n' => self::MAX_UNITS]));
        }
        // квартиры с действующей сделкой (бронь, продажа) удалить нельзя
        foreach ($deals as $aid => $num) {
            if (! isset($ids[(string) $aid])) {
                $fail(__('Нельзя удалить кв. №:n — по ней есть бронь или продажа. Сначала освободите её в продажах.', ['n' => $num]));
            }
        }

        $r = (array) ($in['price'] ?? []);

        return [
            'v' => 1,
            'blocks' => $blocks,
            'types' => array_values($types),
            'units' => $units,
            'price' => [
                'ppm2' => self::money($r['ppm2'] ?? 0), 'step' => round(max(-20, min(20, (float) ($r['step'] ?? 0))), 2), 'from' => max(-10, min(150, (int) ($r['from'] ?? 2))),
                'commercial' => self::money($r['commercial'] ?? 0), 'storage' => self::money($r['storage'] ?? 0), 'parking' => self::money($r['parking'] ?? 0),
                'round' => in_array((int) ($r['round'] ?? 1000), [1, 10, 100, 1000, 10000, 100000, 1000000], true) ? (int) $r['round'] : 1000,
            ],
            'ready' => $old['ready'] ?? false,
        ];
    }

    /** Повторяющиеся номера квартир внутри корпуса — подсказка в редакторе, сохранить не мешает */
    public static function duplicates(array $inv): array
    {
        $seen = [];
        $dups = [];
        foreach ((array) ($inv['units'] ?? []) as $u) {
            $k = ($u['b'] ?? '').'|'.mb_strtolower((string) ($u['num'] ?? ''));
            if (isset($seen[$k])) {
                $dups[] = (string) ($u['num'] ?? '');
            }
            $seen[$k] = true;
        }

        return array_values(array_unique($dups));
    }
}
