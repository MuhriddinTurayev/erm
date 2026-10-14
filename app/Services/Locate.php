<?php

namespace App\Services;

use App\Models\ObjectMedia;

/**
 * «Где квартира в доме» на настоящей 3D-модели (assets/locate3d.js).
 * В модели нет сведений о квартирах, поэтому администратор объектов один раз ставит рамку на здание (корпус):
 * её положение, размер и поворот, число этажей. Дальше квартира находится сама: подъезды делят рамку по длине,
 * этаж — по высоте, квартиры этажа — по фасадам (половина — на лицевой стороне, остальные — на задней).
 * Настройка хранится в meta.locate файла «Фасад и здание 3D».
 */
class Locate
{
    /**
     * Корпуса и помещения объекта для 3D: [blocks => [id => [name, entr => [имена подъездов], floors]], units => [...]]
     * Покупатель и цены не передаются — только номер, место и статус.
     */
    public static function data(array $p, bool $withStatus = true): array
    {
        $apts = Apartments::list($p);
        $blocks = $count = [];
        foreach ($apts as $a) {
            $bid = (string) ($a['blockId'] ?? '');
            $blocks[$bid] ??= ['name' => (string) ($a['block'] ?? ''), 'ents' => [], 'floors' => 1];
            $blocks[$bid]['ents'][(int) $a['section']] ??= (string) ($a['entr'] ?? $a['section']);
            $blocks[$bid]['floors'] = max($blocks[$bid]['floors'], (int) $a['floor']);
            $k = $a['section'].'|'.$a['floor'];
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
        $order = [];
        foreach ($blocks as $bid => &$b) {
            ksort($b['ents']);
            $order[$bid] = array_flip(array_keys($b['ents']));
            $b['entr'] = array_values($b['ents']);
            unset($b['ents']);
        }
        unset($b);
        $units = [];
        foreach ($apts as $a) {
            $bid = (string) ($a['blockId'] ?? '');
            $units[] = array_filter([
                'id' => (string) $a['id'], 'num' => (string) $a['num'], 'b' => $bid, 'e' => $order[$bid][(int) $a['section']] ?? 0,
                'f' => (int) $a['floor'], 'i' => (int) ($a['idx'] ?? 0), 'n' => $count[$a['section'].'|'.$a['floor']] ?? 1,
                'k' => (string) ($a['kind'] ?? 'flat'), 'r' => (int) ($a['rooms'] ?? 0), 'a' => (float) ($a['area'] ?? 0),
                's' => $withStatus ? (string) ($a['status'] ?? 'free') : null,
            ], fn ($v) => $v !== null);
        }

        return ['blocks' => $blocks, 'units' => $units];
    }

    /** Модель здания для показа: выбранная, иначе главная, иначе первая. $public — только открытые покупателям */
    public static function model(int $wsId, string $pid, ?int $id = null, bool $public = false): ?ObjectMedia
    {
        $q = ObjectMedia::of($wsId)->where('project_id', $pid)->where('kind', 'exterior')->when($public, fn ($q) => $q->where('is_public', true));
        $list = $q->orderByDesc('is_main')->orderBy('sort')->orderBy('id')->get();

        return ($id ? $list->firstWhere('id', $id) : null) ?? $list->first();
    }

    /**
     * Демо-дома «Навруз» (database/seeders/demo/make-tashkent.cjs): где стоит дом в сцене квартала.
     * x, z, поворот — из tsh-sites.json; первый этаж 4,5 м, остальные по 3 м; секции сдвинуты на ±1,6 м — рамка чуть глубже.
     */
    private const DEMO = [
        'nvz-park' => ['x' => -30, 'z' => 0, 'rot' => 1.429, 'floors' => 12, 'L' => 56, 'W' => 20.2],
        'nvz-tower' => ['x' => 70, 'z' => -30, 'rot' => 3.857, 'floors' => 16, 'L' => 42, 'W' => 22],
        'nvz-city' => ['x' => 140, 'z' => 50, 'rot' => 3.893, 'floors' => 9, 'L' => 72, 'W' => 19.2],
    ];

    /** Готовая привязка демо-дома (для сидера и миграции существующих демо-компаний) */
    public static function demo(string $pid): ?array
    {
        $d = self::DEMO[$pid] ?? null;
        if (! $d) {
            return null;
        }
        $top = 4.5 + ($d['floors'] - 1) * 3;

        return ['blocks' => ['' => self::clean(['c' => [$d['x'], $top / 2, $d['z']], 's' => [$d['L'], $top, $d['W']], 'r' => $d['rot'], 'floors' => $d['floors'], 'gf' => 4.5, 'rows' => 2, 'back' => 'rev'])]];
    }

    /** Проверить и привести настройку корпуса из формы к нужному виду */
    public static function clean(array $d): array
    {
        $v3 = fn ($a) => array_map(fn ($x) => round((float) $x, 4), array_slice(array_pad((array) $a, 3, 0), 0, 3));
        $s = array_map(fn ($x) => max(0.01, abs($x)), $v3($d['s'] ?? [1, 1, 1]));

        return [
            'c' => $v3($d['c'] ?? [0, 0, 0]), 's' => $s, 'r' => round((float) ($d['r'] ?? 0), 5),
            'floors' => max(1, min(200, (int) ($d['floors'] ?? 1))), 'gf' => round(max(0, min(30, (float) ($d['gf'] ?? 0))), 2),
            'rows' => ((int) ($d['rows'] ?? 2)) === 1 ? 1 : 2,
            'back' => ($d['back'] ?? 'rev') === 'fwd' ? 'fwd' : 'rev', 'flipX' => (bool) ($d['flipX'] ?? false), 'flipZ' => (bool) ($d['flipZ'] ?? false),
        ];
    }
}
