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
                't' => ($a['type'] ?? null) ?: null,   // планировка: по ней находится интерьер квартиры
                'tn' => ($a['type'] ?? null) ? ((string) ($a['typeName'] ?? '') ?: null) : null,
                's' => $withStatus ? (string) ($a['status'] ?? 'free') : null,
            ], fn ($v) => $v !== null);
        }

        return ['blocks' => $blocks, 'units' => $units];
    }

    /**
     * Интерьеры квартир для показа «Внутри»: 3D, панорамы, рендеры, планы — с планировкой (l) или числом комнат (r).
     * Какой файл к какой квартире — решает страница тем же правилом, что ObjectMedia::forUnit.
     */
    public static function media(int $wsId, string $pid, bool $public = false): array
    {
        return ObjectMedia::of($wsId)->where('project_id', $pid)->whereIn('kind', ['interior', 'pano', 'photo', 'plan'])
            ->when($public, fn ($q) => $q->where('is_public', true))->orderBy('kind')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (ObjectMedia $m) => array_filter(['id' => $m->id, 'k' => $m->kind, 'l' => $m->layout, 'r' => $m->rooms, 'n' => $m->title,
                'room' => $m->meta['room'] ?? null, 'ext' => $m->ext, 'u' => $m->unitIds() ?: null], fn ($v) => $v !== null))->values()->all();
    }

    /** Подписи для assets/inner-media.js (интерьеры по планировкам) — привязка к 3D и страница «Интерьеры» */
    public static function innerTexts(): array
    {
        return [
            'rooms' => __('-комн.'), 'm2' => __('м²'), 'flats' => __('кв.'), 'look' => __('Смотреть'), 'err' => __('Не удалось сохранить'),
            'kinds' => ['interior' => __('Интерьер квартиры 3D'), 'pano' => __('Панорама 360°'), 'photo' => __('Рендер / фото'), 'plan' => __('Планировка')],
            'kindsShort' => ['interior' => __('3D'), 'pano' => __('360°'), 'photo' => __('Фото'), 'plan' => __('План')],
            'upTitle' => ['interior' => __('3D-интерьер: GLB, FBX, OBJ, DAE, 3DS, ZIP…'), 'pano' => __('Панорамы 360° комнат: картинка вдвое шире высоты (2:1), можно несколько'), 'photo' => __('Рендеры и фото комнат, можно несколько'), 'plan' => __('План квартиры: PDF или картинка')],
            'noLayouts' => __('В объекте пока нет квартир.'), 'noInnerLay' => __('Для этой планировки ещё ничего нет — загрузите 3D, панорамы 360°, фото или план кнопками выше.'),
            'noInnerView' => __('Для этой планировки интерьера пока нет.'),
            'notPano' => __('«:f» — не панорама 360° (у неё ширина должна быть ровно вдвое больше высоты). Загрузить как обычное фото?'),
            'upBusy' => __('Загружаю…'), 'upDone' => __('Загружено'), 'upFail' => __('Ошибка загрузки'),
            'edit' => __('Изменить'), 'del' => __('Удалить'), 'editFile' => __('Файл интерьера'), 'fileName' => __('Название'), 'roomName' => __('Комната'),
            // комнаты квартиры: у каждой свои 360° и фото
            'whole3d' => __('3D-интерьер'), 'planH' => __('Чертёж'), 'roomsH' => __('Комнаты'), 'roomsHint' => __('у каждой комнаты — свои 360° и фото'),
            'noRoom' => __('Без комнаты'), 'renRoom' => __('Переименовать комнату'), 'renRoomQ' => __('Новое название комнаты'), 'addRoom' => __('Комната'), 'addRoomQ' => __('Название комнаты'),
            'roomNames' => ['room' => __('Комната'), 'living' => __('Гостиная'), 'bed' => __('Спальня'), 'kids' => __('Детская'), 'study' => __('Кабинет'), 'kitchen' => __('Кухня'), 'bath' => __('Ванная'), 'hall' => __('Прихожая')],
            // интерьер отдельной квартиры и «такие же квартиры»
            'kv' => __('Кв.'), 'fl' => __('этаж'), 'aptOwn' => __('Свой интерьер этой квартиры — другие квартиры он не меняет.'), 'sameAs' => __('Такой же у кв.'),
            'aptShared' => __('Сейчас здесь показан общий интерьер планировки (он у всех таких квартир). Загрузите сюда — изменится только эта квартира; потом отметьте такие же квартиры.'),
            'sameBtn' => __('Такие же квартиры…'), 'sharedTip' => __('«:n» — общий файл планировки: поправить или удалить его можно в карточке планировки ниже'), 'sharedMark' => __('план.'),
            'layoutsH' => __('Общий интерьер по планировкам'), 'layoutsHint' => __('файл планировки увидят все её квартиры, у которых нет своего'),
            'onlyApt' => __('только эта квартира'), 'sameTitle' => __('Такие же квартиры, как :a'), 'thisApt' => __('эта квартира'),
            'sameHint' => __('Отметьте квартиры с точно таким же интерьером — им покажется интерьер этой квартиры, загружать в каждую не нужно. Серые — другая планировка.'),
            'sameStack' => __('Над и под ней'), 'sameType' => __('Вся эта планировка'), 'sameNone' => __('Снять все'), 'sameN' => __('Отмечено: :n'),
            'forLayout' => __('Для какой планировки'), 'forAll' => __('Для всех планировок'), 'imgsNot3d' => __('Вы выбрали картинки, а не 3D-модель. Загрузить их как рендеры и фото интерьера (снимки 2:1 — как панорамы 360°)?'), 'cancel' => __('Отмена'), 'save' => __('Сохранить'), 'delFileQ' => __('Удалить «:f»? Файл пропадёт и у покупателей.'),
        ];
    }

    /** Корпус сменил id (квартиры перешли из расчёта в перечень: '' → b1) — привязка во всех моделях объекта переезжает с ним */
    public static function renameBlock(int $wsId, string $pid, string $from, string $to): void
    {
        foreach (ObjectMedia::of($wsId)->where('project_id', $pid)->where('kind', 'exterior')->get() as $m) {
            $meta = (array) ($m->meta ?? []);
            $blocks = (array) ($meta['locate']['blocks'] ?? []);
            if (! array_key_exists($from, $blocks) || array_key_exists($to, $blocks)) {
                continue;
            }
            $blocks[$to] = $blocks[$from];
            unset($blocks[$from]);
            $meta['locate']['blocks'] = $blocks;
            $m->meta = $meta;
            $m->save();
        }
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
            'rows' => in_array($rw = (int) ($d['rows'] ?? 2), [1, 2, 3], true) ? $rw : 2,   // 3 — сквозные квартиры (окна и спереди, и сзади)
            'back' => ($d['back'] ?? 'rev') === 'fwd' ? 'fwd' : 'rev', 'flipX' => (bool) ($d['flipX'] ?? false), 'flipZ' => (bool) ($d['flipZ'] ?? false),
        ];
        // границы подъездов вдоль рамки (доли длины): подъезды бывают разной ширины; нет — делим поровну
        $ex = array_values(array_filter(array_map(fn ($x) => round((float) $x, 4), (array) ($d['ex'] ?? [])), fn ($x) => $x > 0 && $x < 1));
        sort($ex);
        if ($ex && count($ex) <= 60) {
            $out['ex'] = $ex;
        }
        // окна на сторонах рамки: середины окон вдоль стороны (доли): f — лицевая (+Z), b — задняя (−Z), r и l — торцы (+X и −X)
        $frac = fn ($x) => round(max(0, min(1, (float) $x)), 4);
        foreach (['f', 'b', 'r', 'l'] as $side) {
            $w = array_map($frac, array_slice(array_values((array) ($d['win'][$side] ?? [])), 0, 200));
            sort($w);
            if ($w) {
                $out['win'][$side] = $w;
            }
        }
        // где квартира на фасаде: g — отрезки [сторона, a0, a1] (сторона 1/−1 — лицевая/задняя, 2/−2 — торцы; квартира бывает
        // сразу на двух сторонах — сквозная или угловая). slots — по месту на этаже («подъезд|квартир на этаже|номер»: на всех
        // таких этажах одинаково), ux — у одной квартиры; m=1 — поставил человек. Старый вид [s, x0, x1, m] переводим.
        $slot = function ($v, bool $withM) use ($frac) {
            $v = (array) $v;
            if (isset($v['g'])) {
                $list = (array) $v['g'];
                $m = ! empty($v['m']);
            } elseif (count($v) >= 3 && is_numeric($v[0] ?? null)) {
                $list = [[(float) $v[0] < 0 ? -1 : 1, $v[1] ?? 0, $v[2] ?? 0]];
                $m = ! empty($v[3]);
            } else {
                return null;
            }
            $g = [];
            foreach (array_slice($list, 0, 16) as $s) {
                $s = array_values((array) $s);
                if (count($s) < 3 || ! in_array((int) $s[0], [1, -1, 2, -2], true)) {
                    continue;
                }
                [$a0, $a1] = [$frac($s[1]), $frac($s[2])];
                if ($a1 - $a0 >= 0.002) {
                    $g[] = [(int) $s[0], $a0, $a1];
                }
            }
            if (! $g && ! ($withM && $m)) {
                return null;
            }

            return $withM && $m ? ['g' => $g, 'm' => 1] : ['g' => $g];
        };
        foreach (array_slice((array) ($d['slots'] ?? []), 0, 3000, true) as $k => $v) {
            if (preg_match('/^\d{1,3}\|\d{1,3}\|\d{1,3}$/', (string) $k) && ($s = $slot($v, true))) {
                $out['slots'][(string) $k] = $s;
            }
        }
        foreach (array_slice((array) ($d['ux'] ?? []), 0, 6000, true) as $k => $v) {
            if (preg_match('/^[A-Za-z0-9_\-]{1,40}$/', (string) $k) && ($s = $slot($v, false))) {
                $out['ux'][(string) $k] = $s;
            }
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
