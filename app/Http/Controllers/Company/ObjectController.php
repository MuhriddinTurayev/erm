<?php

namespace App\Http\Controllers\Company;

use App\Models\ObjectMedia;
use App\Models\User;
use App\Services\Accounts;
use App\Services\Apartments;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Объекты компании — каждый отдельно: сводка, команда и учётные записи (прораб объекта),
 * 3D-модели фасада и интерьеров, панорамы 360°, планировки.
 */
class ObjectController extends ErpController
{
    /** Сводка по объекту из данных программы */
    public static function summary(array $p): array
    {
        $w = $s = 0;
        foreach ((array) ($p['stages'] ?? []) as $st) {
            $d = max(1, (int) ($st['dur'] ?? 1));
            $w += $d;
            $s += $d * (float) ($st['progress'] ?? 0);
        }
        $apts = Apartments::list($p);
        $res = array_filter($apts, fn ($a) => $a['rooms'] > 0);
        $sold = array_filter($apts, fn ($a) => $a['status'] === 'sold');
        $booked = array_filter($apts, fn ($a) => $a['status'] === 'booked');
        $deals = array_merge($sold, $booked);
        $bal = 0;
        foreach ((array) ($p['tx'] ?? []) as $t) {
            $bal += (($t['type'] ?? '') === 'in' ? 1 : -1) * (float) ($t['amount'] ?? 0);
        }

        return [
            'progress' => $w ? round($s / $w) : 0, 'apts' => count($apts), 'flats' => count($res),
            'sold' => count($sold), 'booked' => count($booked), 'free' => count($apts) - count($sold) - count($booked),
            'revenue' => array_sum(array_column($deals, 'price')), 'paid' => array_sum(array_column($deals, 'paid')),
            'debt' => array_sum(array_map(fn ($a) => max(0, $a['price'] - $a['paid']), $deals)),
            'overdue' => array_sum(array_map(fn ($a) => Apartments::overdue($a), $deals)),
            'stock_value' => array_sum(array_column(array_filter($apts, fn ($a) => $a['status'] === 'free'), 'price')),
            'balance' => $bal, 'staff' => count((array) ($p['staff'] ?? [])),
        ];
    }

    /** Кто из пользователей работает с объектом */
    private function team(string $pid)
    {
        return $this->ws()->users()->orderByRaw("role = 'admin' desc")->orderBy('name')->get()
            ->filter(fn (User $u) => $u->role === 'admin' || empty($u->object_ids) || in_array($pid, array_map('strval', (array) $u->object_ids), true));
    }

    private function project(string $pid): array
    {
        $this->checkProject($pid);

        return StateWriter::project($this->ws(), $pid) ?? abort(404);
    }

    public function index()
    {
        $ws = $this->ws();
        $list = [];
        $only = auth()->user()->allowedObjects();
        foreach ($ws->projects() as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($only !== null && ! in_array($id, $only, true)) {
                continue;
            }
            $list[] = ['p' => $p, 's' => self::summary($p), 'demo' => \App\Models\Workspace::isDemo($p), 'team' => $this->team($id)->count(),
                'media' => ObjectMedia::of($ws->id)->where('project_id', $id)->count(),
                'main' => ObjectMedia::of($ws->id)->where('project_id', $id)->whereIn('kind', ['exterior', 'photo'])->orderByDesc('is_main')->orderBy('sort')->first()];
        }

        return view('company.objects.index', ['ws' => $ws, 'list' => $list, 'cur' => \App\Services\CompanyStats::currency($ws)]);
    }

    public function show(string $pid)
    {
        $ws = $this->ws();
        $p = $this->project($pid);

        // продажи по планировкам: сколько всего, продано, бронь, свободно, средняя цена м²
        $apts = Apartments::list($p);
        $byRooms = [];
        foreach ($apts as $a) {
            $r = &$byRooms[$a['rooms']];
            $r ??= ['n' => 0, 'sold' => 0, 'booked' => 0, 'free' => 0, 'area' => 0, 'sum' => 0, 'min' => null, 'revenue' => 0];
            $r['n']++;
            $r[in_array($a['status'], ['sold', 'booked'], true) ? $a['status'] : 'free']++;
            $r['area'] += $a['area'];
            $r['sum'] += $a['price'];
            if ($a['status'] === 'free') {
                $r['min'] = $r['min'] === null ? $a['price'] : min($r['min'], $a['price']);
            }
            if (in_array($a['status'], ['sold', 'booked'], true)) {
                $r['revenue'] += $a['price'];
            }
            unset($r);
        }
        ksort($byRooms);
        $deals = array_filter($apts, fn ($a) => in_array($a['status'], ['sold', 'booked'], true));
        $today = $ws->localNow()->toDateString();
        $lastPays = [];
        foreach ($deals as $a) {
            foreach ($a['payments'] as $x) {
                $lastPays[] = $x + ['apt' => $a['num'], 'buyer' => $a['buyer'], 'aid' => $a['id']];
            }
        }
        usort($lastPays, fn ($x, $y) => strcmp($y['date'] ?? '', $x['date'] ?? ''));
        $debtors = array_values(array_filter(array_map(fn ($a) => $a + ['od' => Apartments::overdue($a, $today)], $deals), fn ($a) => $a['od'] > 0));
        usort($debtors, fn ($x, $y) => $y['od'] <=> $x['od']);

        $media = ObjectMedia::of($ws->id)->where('project_id', $pid)->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->orderBy('id')->get();
        $u = auth()->user();
        $tab = request('tab', $u->isCatalog() ? 'units' : 'overview');

        return view('company.objects.show', [
            'byRooms' => $byRooms, 'lastPays' => array_slice($lastPays, 0, 10), 'debtors' => array_slice($debtors, 0, 10),
            'ws' => $ws, 'p' => $p, 'pid' => $pid, 's' => self::summary($p), 'team' => $this->team($pid), 'cur' => \App\Services\CompanyStats::currency($ws),
            'media' => $media, 'roles' => config('oftob.roles'), 'tab' => $tab, 'types' => self::layouts($p),
            'inv' => $tab === 'units' ? $this->unitsData($ws, $p, $apts, $media) : null,
        ]);
    }

    /** Данные для редактора перечня помещений */
    private function unitsData($ws, array $p, array $apts, $media): array
    {
        $deals = [];
        $status = [];
        $over = [];
        foreach ($apts as $a) {
            if ($a['status'] !== 'free') {
                $status[$a['id']] = $a['status'];
            }
            if (in_array($a['status'], ['sold', 'booked'], true)) {
                $deals[$a['id']] = ['st' => $a['status'], 'buyer' => $a['buyer'], 'price' => $a['price']];
            } elseif (! empty($p['apts'][$a['id']]['price'])) {
                $over[$a['id']] = (float) $p['apts'][$a['id']]['price'];   // цену поставил менеджер продаж
            }
        }
        // что загружено для каждой планировки: чертёж, 3D-интерьер, панорамы комнат
        $byType = [];
        foreach ($media->whereNotNull('layout') as $m) {
            $byType[$m->layout][$m->kind][] = ['id' => $m->id, 'title' => $m->title, 'room' => $m->meta['room'] ?? null];
        }

        return [
            'has' => \App\Services\Inventory::has($p), 'data' => $p['inv'] ?? null, 'rev' => InventoryController::rev($p), 'undo' => ! empty($p['invPrev']),
            'ready' => $p['inv']['ready'] ?? false, 'status' => (object) $status, 'deals' => (object) $deals, 'over' => (object) $over, 'media' => (object) $byType,
            'generated' => count(Apartments::generated($p)), 'genDeals' => count($deals),
            'exterior' => $media->where('kind', 'exterior')->count(), 'public' => (bool) $ws->public_enabled,
            'build' => Apartments::buildParams($p), 'kinds' => array_map(fn ($k) => __($k), \App\Services\Inventory::KINDS),
        ];
    }

    /** Учётная запись для объекта: по умолчанию прораб, доступ только к этому объекту */
    public function account(Request $request, string $pid)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->project($pid);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'phone' => ['nullable', 'required_without:email', 'string', 'max:40'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:120', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_diff(array_keys(config('oftob.roles')), ['admin']))],
        ]);
        try {
            [$u, $pass, $login] = Accounts::create($this->ws(), $request->user(), $d + ['objects' => [$pid]]);
        } catch (\DomainException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        return redirect('/company/objects/'.$pid.'?tab=team')->with('creds', ['name' => $u->name, 'login' => $login, 'password' => $pass, 'role' => $u->roleName()]);
    }

    /** Новый пароль сотруднику объекта */
    public function resetPassword(Request $request, string $pid, User $user)
    {
        abort_unless($request->user()->isAdmin() && (int) $user->workspace_id === (int) $this->ws()->id && $user->id !== $request->user()->id, 403);
        $pass = Accounts::password();
        $user->forceFill(['password' => $pass, 'remember_token' => Str::random(60)])->save();
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();

        return back()->with('creds', ['name' => $user->name, 'login' => Accounts::login($user), 'password' => $pass, 'role' => $user->roleName()]);
    }

    /* ---------------- 3D и медиа ---------------- */

    private function canMedia(): void
    {
        abort_unless(auth()->user()->canMedia(), 403, __('Нет доступа к этому разделу'));
    }

    /** Квартиры объекта из списка «id,id,…» (или массива): только существующие, без повторов */
    public static function unitList(array $p, $raw): array
    {
        $want = array_filter(array_map(fn ($v) => trim((string) $v), is_array($raw) ? $raw : explode(',', (string) $raw)), 'strlen');
        if (! $want) {
            return [];
        }
        $ids = array_map(fn ($a) => (string) $a['id'], \App\Services\Apartments::list($p));

        return array_values(array_unique(array_intersect($want, $ids)));
    }

    /**
     * «Одинаковые квартиры»: интерьер, загруженный к квартире apt, показывается и у отмеченных same — вместе с ней, не трогая остальные.
     * Убранные из списка квартиры снова показывают интерьер своей планировки.
     */
    public function interiorsSame(Request $request, string $pid)
    {
        $this->canMedia();
        $p = $this->project($pid);
        $d = $request->validate(['apt' => ['required', 'string', 'max:60'], 'same' => ['nullable', 'array', 'max:2000'], 'same.*' => ['string', 'max:60']]);
        abort_unless(self::unitList($p, [$d['apt']]), 422, __('Квартира не найдена — обновите страницу'));
        $group = array_values(array_unique(array_merge([(string) $d['apt']], self::unitList($p, $d['same'] ?? []))));
        $files = ObjectMedia::of($this->ws()->id)->where('project_id', $pid)->whereIn('kind', ['interior', 'pano', 'photo', 'plan'])->get()
            ->filter(fn (ObjectMedia $m) => in_array((string) $d['apt'], $m->unitIds(), true));
        if (! $files->count()) {
            return response()->json(['error' => __('Сначала загрузите интерьер этой квартиры — потом отметьте такие же.')], 422);
        }
        foreach ($files as $m) {
            $meta = (array) $m->meta;
            $meta['units'] = $group;
            $m->meta = $meta;
            $m->save();
        }
        \App\Models\AuditLog::create(['workspace_id' => $this->ws()->id, 'user_id' => $request->user()->id, 'project' => $p['name'] ?? $pid, 'action' => 'media.edit',
            'summary' => __('Интерьер квартиры: такой же у :n кв.', ['n' => count($group) - 1])]);

        return response()->json(['ok' => true, 'units' => $group, 'files' => $files->pluck('id')->values()]);
    }

    /** Подписи перевода 3D-моделей в GLB (assets/model-convert.js): загрузка в «3D, панорамы» и интерьер прямо из привязки к 3D */
    public static function convertTexts(): array
    {
        return [
            'p_zip' => __('Распаковываю архив…'), 'p_read' => __('Читаю модель…'), 'p_lib' => __('Загружаю модуль чтения формата…'), 'p_ifc' => __('Разбираю IFC (это может занять минуту)…'),
            'p_opt' => __('Объединяю детали и привожу к метрам…'), 'p_glb' => __('Сохраняю в GLB…'), 'p_up' => __('Загружаю на сервер…'),
            'unknown' => __('Этот формат не удалось прочитать. Сохраните модель в GLB, FBX, OBJ, DAE или IFC.'), 'noModel' => __('Среди выбранных файлов нет 3D-модели. Выберите файл модели (и, если есть, папку с текстурами или ZIP).'),
            'emptyModel' => __('В модели нет видимых объектов.'), 'ifcBad' => __('Файл IFC повреждён или не поддерживается.'), 'ifcEmpty' => __('В файле IFC нет геометрии (только данные). Экспортируйте IFC с 3D-видом.'),
            'x_any' => __(':p (:e) — это файл программы, браузер его не прочитает. Экспортируйте модель в FBX, OBJ, DAE, IFC или GLB и загрузите полученный файл.'),
            'x_skp' => __('SketchUp (.skp): откройте модель в SketchUp → Файл → Экспорт → 3D-модель → COLLADA (.dae) или glTF (.glb) и загрузите полученный файл (вместе с папкой текстур или ZIP).'),
            'x_rvt' => __('Revit (.rvt): Файл → Экспорт → IFC (или FBX) и загрузите полученный файл.'), 'x_rfa' => __('Revit (.rfa): Файл → Экспорт → IFC (или FBX) и загрузите полученный файл.'),
            'x_max' => __('3ds Max (.max) — закрытый формат: его открывает только сам 3ds Max. Обычно вместе с моделью есть .fbx, .obj или .3ds (часто в том же архиве) — выберите его вместе с текстурами. Если нет — в 3ds Max: Файл → Экспорт → FBX (или OBJ) с текстурами и загрузите полученный файл или ZIP.'),
            'fbxOld' => __('Старый FBX (версия :v) прочитать не удалось. Откройте его в 3ds Max, Maya или Blender и сохраните заново в FBX 2013 или новее (или в OBJ / GLB).'),
            'x_blend' => __('Blender (.blend): Файл → Экспорт → glTF 2.0 (.glb) и загрузите полученный файл.'),
            'x_pln' => __('ArchiCAD (.pln): Файл → Сохранить как → IFC (или 3DS / DAE) и загрузите полученный файл.'), 'x_pla' => __('ArchiCAD (.pla): Файл → Сохранить как → IFC и загрузите полученный файл.'),
            'x_dwg' => __('AutoCAD (.dwg): экспортируйте 3D-модель в FBX (команда FBXEXPORT) или откройте в 3ds Max/Revit и сохраните в FBX.'),
            'x_c4d' => __('Cinema 4D (.c4d): Файл → Экспорт → glTF или FBX и загрузите полученный файл.'),
            'fail' => __('Не удалось перевести модель'), 'tris' => __('треугольников'), 'mats' => __('материалов'), 'texs' => __('текстур'), 'size' => __('Размер'), 'unit' => __('единицы'),
            'heavy' => __('Модель тяжёлая — на телефоне может открываться долго. Выберите текстуры 1024 px.'), 'tiny' => __('Модель получилась крошечной — проверьте единицы.'), 'huge' => __('Модель получилась огромной — проверьте единицы.'),
            'missTex' => __('Не найдено текстур: :n — модель загрузится без них, с цветом материалов. Чтобы на фасаде были картинки, выберите вместе с моделью и файлы текстур (вся папка: Ctrl+A).'),
            'noMtl' => __('Цвета и текстуры этой OBJ-модели лежат в отдельном файле «:f», а его не выбрали. Модель загрузится с примерными цветами. Чтобы было как у автора — выберите вместе с .obj файл .mtl и папку с картинками (или загрузите всю папку модели ZIP-архивом).'),
            'stripTex' => __('Текстуры этой модели сохранить не удалось — модель загрузится без них, с цветом материалов.'),
            'w' => __('Ш'), 'd' => __('Г'), 'h' => __('В'), 'm' => __('м'), 'MB' => __('МБ'), 'from' => __('из'), 'ok' => __('Модель готова. Покрутите её мышью и загрузите.'),
        ];
    }

    /** Планировки объекта из перечня помещений: [id => планировка] */
    public static function layouts(array $p): array
    {
        $out = [];
        foreach ((array) ($p['inv']['types'] ?? []) as $t) {
            $out[(string) ($t['id'] ?? '')] = $t;
        }

        return $out;
    }

    /**
     * Загрузка частями (по 5 МБ): большие 3D-модели проходят даже при маленьком лимите хостинга.
     * Поля: upload (id загрузки), index, total, name, chunk; на последней части — kind, rooms, title.
     */
    public function mediaUpload(Request $request, string $pid)
    {
        $this->canMedia();
        $p = $this->project($pid);
        $ws = $this->ws();
        $d = $request->validate([
            'upload' => ['required', 'string', 'regex:/^[a-z0-9]{16,40}$/'], 'index' => ['required', 'integer', 'min:0'], 'total' => ['required', 'integer', 'min:1', 'max:200'],
            'name' => ['required', 'string', 'max:200'], 'chunk' => ['required', 'file', 'max:6144'],
            'kind' => ['required', Rule::in(array_keys(ObjectMedia::KINDS))], 'rooms' => ['nullable', 'integer', 'min:0', 'max:9'], 'title' => ['nullable', 'string', 'max:160'],
            'attach' => ['nullable', 'integer'],   // id модели фасада: файл станет её состоянием «Готовый дом»
            'layout' => ['nullable', 'string', 'max:40'],   // планировка из перечня помещений: её чертёж, 3D-интерьер, панорамы комнат
            'room' => ['nullable', 'string', 'max:60'],     // комната на панораме: «Кухня», «Спальня»…
            'src' => ['nullable', 'string', 'max:200'],     // исходный файл, если модель переведена в GLB в браузере
            'units' => ['nullable', 'string', 'max:4000'],  // интерьер одной квартиры (и одинаковых с ней): id через запятую
        ]);
        $units = self::unitList($p, $d['units'] ?? '');
        $layout = null;
        if (! empty($d['layout'])) {
            $types = self::layouts($p);
            abort_unless(isset($types[$d['layout']]), 422, __('Планировка не найдена — обновите страницу'));
            $layout = $types[$d['layout']];
        }
        $target = null;
        if (! empty($d['attach'])) {
            $target = ObjectMedia::of($ws->id)->where('project_id', $pid)->where('kind', 'exterior')->whereIn('ext', ['glb', 'gltf'])->findOrFail($d['attach']);
        }
        $ext = Str::lower(pathinfo($d['name'], PATHINFO_EXTENSION));
        if (! in_array($ext, ObjectMedia::KINDS[$d['kind']][2], true)) {
            return response()->json(['error' => __('Для этого вида нужен файл: :e', ['e' => implode(', ', ObjectMedia::KINDS[$d['kind']][2])])], 422);
        }
        $disk = Storage::disk('local');
        $tmp = 'tmp/up/'.$ws->id.'-'.$d['upload'].'.part';
        if ((int) $d['index'] === 0) {
            $disk->put($tmp, '');
        }
        abort_unless($disk->exists($tmp), 422);
        file_put_contents($disk->path($tmp), file_get_contents($request->file('chunk')->getRealPath()), FILE_APPEND);
        if ($disk->size($tmp) > 400 * 1024 * 1024) {
            $disk->delete($tmp);

            return response()->json(['error' => __('Файл больше 400 МБ. Сожмите модель (см. подсказку).')], 422);
        }
        if ((int) $d['index'] < (int) $d['total'] - 1) {
            return response()->json(['ok' => true, 'next' => (int) $d['index'] + 1]);
        }
        // последняя часть: проверяем содержимое и сохраняем
        $head = file_get_contents($disk->path($tmp), false, null, 0, 16);
        $okHead = match ($ext) {
            'glb' => str_starts_with($head, 'glTF'),
            'gltf' => str_contains($head, '{'),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8"),
            'png' => str_starts_with($head, "\x89PNG"),
            'webp' => str_starts_with($head, 'RIFF'),
            'pdf' => str_starts_with($head, '%PDF'),
            default => true,
        };
        if (! $okHead) {
            $disk->delete($tmp);

            return response()->json(['error' => __('Файл повреждён или не соответствует расширению .:e', ['e' => $ext])], 422);
        }
        foreach (['title', 'name'] as $k) {   // неверная кодировка из старых программ — не роняем загрузку
            if (isset($d[$k]) && ! mb_check_encoding($d[$k], 'UTF-8')) {
                $d[$k] = mb_convert_encoding($d[$k], 'UTF-8', 'Windows-1251');
            }
        }
        $path = 'media/'.$ws->id.'/'.$pid.'/'.Str::lower(Str::random(20)).'.'.$ext;
        $disk->move($tmp, $path);
        if ($target) {   // второе состояние той же модели: «Сейчас» ↔ «Готовый дом»
            $meta = (array) $target->meta;
            if (! empty($meta['finished'])) {
                $disk->delete($meta['finished']);
            }
            $meta['finished'] = $path;
            $target->meta = $meta + self::glbFlags($disk->path($path));
            $target->save();
            \App\Models\AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $request->user()->id, 'project' => StateWriter::projects($ws)[$pid] ?? null, 'action' => 'media.add',
                'summary' => __('Готовый дом').': '.$target->title.' ('.round($disk->size($path) / 1048576, 1).' МБ)']);

            return response()->json(['ok' => true, 'id' => $target->id]);
        }
        $meta = in_array($ext, ['glb', 'gltf'], true) ? self::glbFlags($disk->path($path)) : [];
        if (! empty($d['room'])) {
            $meta['room'] = trim($d['room']);
        }
        if (! empty($d['src'])) {
            $meta['src'] = trim($d['src']);
        }
        if ($units && in_array($d['kind'], ['interior', 'pano', 'photo', 'plan'], true)) {
            $meta['units'] = $units;
        }
        $title = ($d['title'] ?? '') ?: (! empty($d['room']) && $layout ? $layout['name'].' — '.trim($d['room']) : Str::limit(pathinfo($d['src'] ?? $d['name'], PATHINFO_FILENAME), 150, ''));
        $m = ObjectMedia::create([
            'workspace_id' => $ws->id, 'project_id' => $pid, 'kind' => $d['kind'],
            // у планировки число комнат берём из неё: так файл найдут и старые экраны, которые ищут по комнатам
            'rooms' => $layout ? (int) ($layout['rooms'] ?? 0) : ($d['rooms'] ?? null), 'layout' => $layout ? (string) $layout['id'] : null,
            'title' => Str::limit($title, 160, ''), 'path' => $path, 'ext' => $ext,
            'size' => $disk->size($path), 'user_id' => $request->user()->id, 'is_public' => true,
            'is_main' => $d['kind'] === 'exterior' && ! ObjectMedia::of($ws->id)->where('project_id', $pid)->where('kind', 'exterior')->exists(),
            'meta' => $meta ?: null,
        ]);
        \App\Models\AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $request->user()->id, 'project' => StateWriter::projects($ws)[$pid] ?? null, 'action' => 'media.add',
            'summary' => ObjectMedia::KINDS[$m->kind][0].': '.$m->title.' ('.round($m->size / 1048576, 1).' МБ)']);

        return response()->json(['ok' => true, 'id' => $m->id]);
    }

    /**
     * Что умеет модель: варианты материалов «день/ночь» (KHR_materials_variants) → кнопки День/Ночь и небо.
     * Имена вариантов берём как есть: day/night, kunduz/tun, день/ночь, рӯз/шаб.
     */
    public static function glbFlags(string $abs): array
    {
        $json = null;
        $f = @fopen($abs, 'rb');
        if (! $f) {
            return [];
        }
        $head = fread($f, 20);
        if (strlen($head) === 20 && str_starts_with($head, 'glTF')) {
            $len = unpack('V', substr($head, 12, 4))[1];
            $json = $len > 0 && $len < 64 * 1048576 ? json_decode(fread($f, $len), true) : null;
        } elseif (filesize($abs) < 64 * 1048576) {
            $json = json_decode(file_get_contents($abs), true);
        }
        fclose($f);
        $names = array_column((array) ($json['extensions']['KHR_materials_variants']['variants'] ?? []), 'name');
        $pick = fn (string $re) => collect($names)->first(fn ($n) => preg_match($re.'u', mb_strtolower((string) $n)));
        $day = $pick('/day|kun|день|дневн|рӯз/');
        $night = $pick('/night|tun|ноч|шаб/');

        return $day && $night ? ['daynight' => true, 'sky' => true, 'vday' => $day, 'vnight' => $night] : [];
    }

    public function mediaUpdate(Request $request, ObjectMedia $media)
    {
        $this->mine($media);
        $this->canMedia();
        $this->checkProject($media->project_id);
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'rooms' => ['nullable', 'integer', 'min:0', 'max:9'], 'sort' => ['nullable', 'integer'],
            'camera' => ['nullable', 'string', 'max:120'], 'exposure' => ['nullable', 'numeric', 'min:0.2', 'max:3'], 'poster' => ['nullable', 'string', 'max:2000000'],
            'layout' => ['nullable', 'string', 'max:40'], 'room' => ['nullable', 'string', 'max:60'],
            // комнаты внутри 3D-интерьера: [{name, orbit, target, fov}] — кнопки «Кухня», «Спальня»… в просмотре
            'views' => ['nullable', 'string', 'max:20000']]);
        $media->fill(['title' => $d['title'], 'rooms' => $d['rooms'] ?? null, 'sort' => (int) ($d['sort'] ?? 0), 'is_public' => $request->boolean('is_public')]);
        if ($request->has('layout')) {   // поле есть только в форме «Настроить»; «Сохранить вид» его не присылает
            $types = self::layouts(StateWriter::project($this->ws(), (string) $media->project_id) ?? []);
            $t = $types[(string) ($d['layout'] ?? '')] ?? null;
            $media->layout = $t ? (string) $t['id'] : null;
            if ($t) {
                $media->rooms = (int) ($t['rooms'] ?? 0);
            }
        }
        $meta = (array) $media->meta;
        if ($request->has('room')) {
            $room = trim((string) ($d['room'] ?? ''));
            if ($room !== '') {
                $meta['room'] = $room;
            } else {
                unset($meta['room']);
            }
        }
        if (isset($d['views'])) {
            $views = [];
            foreach ((array) json_decode($d['views'], true) as $v) {
                $name = trim((string) ($v['name'] ?? ''));
                $orbit = (string) ($v['orbit'] ?? '');
                $target = (string) ($v['target'] ?? '');
                if ($name === '' || ! preg_match('/^[-0-9.e]+(rad|deg) [-0-9.e]+(rad|deg) [-0-9.e]+m$/', $orbit) || ! preg_match('/^[-0-9.e]+m [-0-9.e]+m [-0-9.e]+m$/', $target)) {
                    continue;
                }
                $views[] = ['name' => mb_substr($name, 0, 40), 'orbit' => $orbit, 'target' => $target, 'fov' => preg_match('/^[0-9.]+deg$/', (string) ($v['fov'] ?? '')) ? $v['fov'] : null];
            }
            $views = array_slice($views, 0, 24);
            if ($views) {
                $meta['views'] = $views;
            } else {
                unset($meta['views']);
            }
        }
        if (isset($d['camera'])) {
            $meta['camera'] = $d['camera'];
        }
        if (isset($d['exposure'])) {
            $meta['exposure'] = (float) $d['exposure'];
        }
        if ($request->has('opts')) {   // переключатели из формы «Настроить» (у «Сохранить вид» их нет — не трогаем)
            $meta['sky'] = $request->boolean('sky');
            if ($request->boolean('drop_finished') && ! empty($meta['finished'])) {
                Storage::disk('local')->delete($meta['finished']);
                unset($meta['finished']);
            }
        }
        $media->meta = $meta;
        // превью из окна просмотра (снимок экрана модели)
        if (! empty($d['poster']) && preg_match('#^data:image/(png|jpeg|webp);base64,#', $d['poster'], $mm)) {
            $bin = base64_decode(substr($d['poster'], strpos($d['poster'], ',') + 1));
            if ($bin && strlen($bin) < 1500000) {
                $pp = 'media/'.$media->workspace_id.'/'.$media->project_id.'/poster-'.$media->id.'.'.($mm[1] === 'jpeg' ? 'jpg' : $mm[1]);
                Storage::disk('local')->put($pp, $bin);
                $media->poster = $pp;
            }
        }
        if ($request->boolean('is_main')) {
            ObjectMedia::of($media->workspace_id)->where('project_id', $media->project_id)->update(['is_main' => false]);
            $media->is_main = true;
        }
        $media->save();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('ok', __('Сохранено'));
    }

    /**
     * «Интерьеры»: 3D, панорамы 360°, рендеры и план каждой планировки — посмотреть, загрузить, поправить, удалить.
     * Открывается и в окне из «Дизайна квартиры» программы (embed); u — квартира, r — число комнат: её планировка выделена.
     */
    public function interiors(Request $request, string $pid)
    {
        $ws = $this->ws();
        $p = $this->project($pid);
        $data = \App\Services\Locate::data($p, false);
        $focus = null;
        $apt = null;
        if ($request->query('u') && ($a = collect($data['units'])->firstWhere('id', (string) $request->query('u')))) {
            $apt = $a;   // квартира: её карточка первой — свой интерьер и «такие же квартиры»
            $focus = 'apt:'.$a['id'];
        } elseif ($request->has('r')) {
            // по числу комнат: если у таких квартир своя планировка — выделяем её
            $r = (int) $request->query('r');
            $a = collect($data['units'])->first(fn ($x) => (int) ($x['r'] ?? 0) === $r && ($x['k'] ?? 'flat') === 'flat');
            $focus = $a && ! empty($a['t']) ? 't:'.$a['t'] : 'r:'.$r;
        }

        return view('company.objects.interiors', ['p' => $p, 'pid' => $pid, 'units' => $data['units'], 'inner' => \App\Services\Locate::media($ws->id, $pid),
            'canEdit' => auth()->user()->canMedia(), 'embed' => $request->boolean('embed'), 'focus' => $focus, 'apt' => $apt]);
    }

    public function mediaDelete(ObjectMedia $media)
    {
        $this->mine($media);
        $this->canMedia();
        $this->checkProject($media->project_id);
        Storage::disk('local')->delete(array_filter([$media->path, $media->poster, $media->meta['finished'] ?? null]));
        $media->delete();

        return request()->expectsJson() ? response()->json(['ok' => true]) : back()->with('ok', __('Файл удалён'));
    }

    /**
     * Поправить файл интерьера прямо из привязки к 3D или «Дизайна квартиры»: название, комната панорамы,
     * к какой планировке (или числу комнат) он относится. Остальное (вид, превью, «в продаже») не трогаем.
     */
    public function mediaPatch(Request $request, ObjectMedia $media)
    {
        $this->mine($media);
        $this->canMedia();
        $this->checkProject($media->project_id);
        abort_unless(in_array($media->kind, ['interior', 'pano', 'photo', 'plan'], true), 422);   // модель здания правится на странице объекта
        $d = $request->validate(['title' => ['nullable', 'string', 'max:160'], 'room' => ['nullable', 'string', 'max:60'],
            'layout' => ['nullable', 'string', 'max:40'], 'rooms' => ['nullable', 'integer', 'min:0', 'max:9'], 'units' => ['nullable', 'array', 'max:2000'], 'units.*' => ['string', 'max:60']]);
        $proj = StateWriter::project($this->ws(), (string) $media->project_id) ?? [];
        $lays = self::layouts($proj);
        if (filled($d['layout'] ?? null) && ! isset($lays[(string) $d['layout']])) {
            return response()->json(['error' => __('Планировка не найдена — обновите страницу')], 422);
        }
        if (filled($d['title'] ?? null)) {
            $media->title = trim($d['title']);
        }
        if ($request->has('room')) {
            $meta = (array) $media->meta;
            $room = trim((string) ($d['room'] ?? ''));
            if ($room !== '') {
                $meta['room'] = $room;
            } else {
                unset($meta['room']);
            }
            $media->meta = $meta ?: null;
        }
        if ($request->has('layout') || $request->has('rooms')) {
            $t = $lays[(string) ($d['layout'] ?? '')] ?? null;
            $media->layout = $t ? (string) $t['id'] : null;
            $media->rooms = $t ? (int) ($t['rooms'] ?? 0) : ($request->has('rooms') ? ($d['rooms'] ?? null) : $media->rooms);
        }
        if ($request->has('units')) {   // пусто — файл снова у всей планировки
            $meta = (array) $media->meta;
            $units = self::unitList($proj, $d['units'] ?? []);
            if ($units) {
                $meta['units'] = $units;
            } else {
                unset($meta['units']);
            }
            $media->meta = $meta ?: null;
        }
        $media->save();

        return response()->json(['ok' => true, 'm' => ['id' => $media->id, 'k' => $media->kind, 'l' => $media->layout, 'r' => $media->rooms, 'n' => $media->title, 'room' => $media->meta['room'] ?? null, 'ext' => $media->ext, 'u' => $media->unitIds() ?: null]]);
    }

    public function mediaFile(Request $request, ObjectMedia $media)
    {
        $this->mine($media);
        $this->checkProject($media->project_id);

        return self::stream($media, $request->boolean('poster'), $request->query('v') === 'finished');
    }

    /** Отдать файл (с поддержкой докачки — видео и большие модели) */
    public static function stream(ObjectMedia $m, bool $poster = false, bool $finished = false)
    {
        $path = $poster ? $m->poster : ($finished && ! empty($m->meta['finished']) ? $m->meta['finished'] : $m->path);
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $ext = $poster ? pathinfo($path, PATHINFO_EXTENSION) : $m->ext;

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => ObjectMedia::mimeOf($ext), 'Cache-Control' => 'private, max-age=604800',
            'Content-Disposition' => 'inline; filename="'.Str::slug($m->title ?: 'model').'.'.$ext.'"',
        ]);
    }

    /** Просмотр 3D / панорамы на весь экран */
    public function viewer(string $pid)
    {
        $ws = $this->ws();
        $p = $this->project($pid);
        $media = ObjectMedia::of($ws->id)->where('project_id', $pid)->whereIn('kind', ['exterior', 'interior', 'pano', 'photo', 'plan'])->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->orderBy('id')->get();
        // u — квартира, t/r — планировка: всё о квартире изнутри — 3D-интерьер, комнаты (360° и рендеры каждой), чертёж
        $unit = null;
        if (request('u') && ($a = Apartments::find($p, (string) request('u')))) {
            $media = ObjectMedia::forApt($media, (string) $a['id'], $a['type'] ?? null, (int) $a['rooms']);
            $unit = trim(($a['rooms'] ? __('Кв.').' '.$a['num'].' · '.$a['rooms'].__('-комн.') : (string) $a['num']).(! empty($a['area']) ? ' · '.$a['area'].' '.__('м²') : ''));
        } elseif (request()->has('t') || request()->has('r')) {
            $t = self::layouts($p)[(string) request('t')] ?? null;
            $media = ObjectMedia::forUnitAll($media, $t ? (string) $t['id'] : null, $t ? (int) ($t['rooms'] ?? 0) : (int) request('r'));
            $unit = $t ? (string) ($t['name'] ?? '') : (int) request('r').__('-комн.');
        } else {
            $media = $media->whereIn('kind', ['exterior', 'interior', 'pano'])->values();   // весь объект: модели и панорамы, как раньше
        }
        $cur = $media->firstWhere('id', (int) request('m')) ?? $media->first();

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $pid, 'media' => $media, 'cur' => $cur, 'unit' => $unit,
            'src' => fn (ObjectMedia $m, bool $poster = false) => url('/company/media/'.$m->id.'/file'.($poster ? '?poster=1' : '')), 'public' => false,
            'canEdit' => auth()->user()->canMedia(), 'embed' => request()->boolean('embed')]);
    }
}
