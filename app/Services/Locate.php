<?php

namespace App\Services;

use App\Models\ObjectMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Cache;

/**
 * «Где квартира в доме» на настоящей 3D-модели (assets/locate3d.js).
 * В модели нет сведений о квартирах, поэтому администратор объектов один раз ставит рамку на здание (корпус):
 * её положение, размер и поворот, число этажей. Дальше квартира находится сама: подъезды делят рамку по длине,
 * этаж — по высоте, квартиры этажа — по фасадам (половина — на лицевой стороне, остальные — на задней).
 * Рамку сначала ставит автопривязка (assets/locate-auto.js: геометрия модели + ИИ смотрит на фасады), человек только поправляет.
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
        $out = [
            'c' => $v3($d['c'] ?? [0, 0, 0]), 's' => $s, 'r' => round((float) ($d['r'] ?? 0), 5),
            'floors' => max(1, min(200, (int) ($d['floors'] ?? 1))), 'gf' => round(max(0, min(30, (float) ($d['gf'] ?? 0))), 2),
            'rows' => ((int) ($d['rows'] ?? 2)) === 1 ? 1 : 2,
            'back' => ($d['back'] ?? 'rev') === 'fwd' ? 'fwd' : 'rev', 'flipX' => (bool) ($d['flipX'] ?? false), 'flipZ' => (bool) ($d['flipZ'] ?? false),
        ];
        // границы подъездов вдоль рамки (доли длины): подъезды бывают разной ширины; нет — делим поровну
        $ex = array_values(array_filter(array_map(fn ($x) => round((float) $x, 4), (array) ($d['ex'] ?? [])), fn ($x) => $x > 0 && $x < 1));
        sort($ex);
        if ($ex && count($ex) <= 60) {
            $out['ex'] = $ex;
        }
        // рамку поставил ИИ (или расчёт по геометрии) и человек её ещё не проверял
        if (! empty($d['auto']) && is_array($d['auto'])) {
            $a = $d['auto'];
            $out['auto'] = array_filter([
                'by' => ($a['by'] ?? '') === 'ai' ? 'ai' : 'geo',
                'mf' => isset($a['mf']) ? max(0, min(300, (int) $a['mf'])) : null,
                'doors' => isset($a['doors']) ? max(0, min(100, (int) $a['doors'])) : null,
                'warn' => array_values(array_intersect((array) ($a['warn'] ?? []), self::WARN)) ?: null,
                'at' => now()->toDateString(),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Что автопривязка сообщает человеку: этажей в модели не столько, входов не столько, это не один дом, корпусов меньше */
    public const WARN = ['floors', 'doors', 'notBuilding', 'blocks'];

    /**
     * ИИ смотрит на два фасада дома (картинка из assets/locate-auto.js) и отвечает: сколько этажей, где входы, какая сторона лицевая.
     * Ответ кэшируется по картинке: одна и та же модель второй раз лимит не тратит.
     *
     * @return array{ok: bool, a?: array, note?: string}
     */
    public static function askAi(Workspace $ws, ?User $user, string $jpeg, int $entrances): array
    {
        $ai = app(AiService::class);
        if (! $ws->aiAllowed() || ! $ai->providerFor($ws)) {
            return ['ok' => false, 'note' => 'off'];
        }
        $limit = $ws->aiLimit();
        if ($limit > 0 && $ws->aiUsedThisMonth() >= $limit) {
            return ['ok' => false, 'note' => 'limit'];
        }
        $key = 'locai:'.md5($jpeg).':'.$entrances;
        if ($hit = Cache::get($key)) {
            return ['ok' => true, 'a' => $hit];
        }
        $prompt = 'You analyze a 3D model of an apartment building for a real-estate app. The image has two orthographic elevation renders of the SAME building, '
            .'panel "A" (left) and panel "B" (right) show opposite facades. Each panel is the gray rectangle under its letter. '
            .'Coordinates for each panel: x from 0 (panel left edge) to 1000 (panel right edge), y from 0 (panel top edge) to 1000 (panel bottom edge).'
            ."\nThe building should have {$entrances} entrance(s) (stairwell doors, often with a canopy or porch) — use it only as a hint where to look; report what you actually see."
            ."\nAnswer ONLY with JSON, no comments:"
            .'{"is_building":true|false (false if it is not one building: a city district, terrain, interior, furniture, a car…),'
            .'"floors":N (above-ground storeys with regular rows of windows, the ground floor included, roof/attic not included),'
            .'"attic":true|false (a mansard/attic storey with dormer windows above the eaves),'
            .'"front":"A"|"B" (the facade with the entrance doors),'
            .'"doors":[x,…] (centers of the entrance doors on the front facade, left to right, x in that panel; shop doors not included),'
            .'"ground_y":y (where the walls meet the ground on the front panel),'
            .'"eave_y":y (top of the walls of the highest full storey on the front panel — where the roof or parapet starts),'
            .'"first_floor_top_y":y|null (top of the ground storey on the front panel if it is clearly taller than the others — shops, lobby; else null)}';
        $text = $ai->see($ws, $user, 'locate', $prompt, $jpeg, 'image/jpeg', 6000);
        if ($text === null || ! preg_match('/\{.*\}/s', $text, $m) || ! is_array($j = json_decode($m[0], true))) {
            return ['ok' => false, 'note' => 'fail'];
        }
        $n = fn ($v, $max = 1000) => is_numeric($v) ? max(0, min($max, (float) $v)) : null;
        $doors = array_values(array_filter(array_map($n, array_slice((array) ($j['doors'] ?? []), 0, 40)), fn ($v) => $v !== null));
        sort($doors);
        $a = [
            'is_building' => ($j['is_building'] ?? true) !== false,
            'floors' => is_numeric($j['floors'] ?? null) ? max(0, min(300, (int) $j['floors'])) : null,
            'attic' => (bool) ($j['attic'] ?? false),
            'front' => ($j['front'] ?? 'A') === 'B' ? 'B' : 'A',
            'doors' => $doors,
            'ground_y' => $n($j['ground_y'] ?? null), 'eave_y' => $n($j['eave_y'] ?? null), 'first_floor_top_y' => $n($j['first_floor_top_y'] ?? null),
        ];
        Cache::put($key, $a, now()->addDays(30));

        return ['ok' => true, 'a' => $a];
    }
}
