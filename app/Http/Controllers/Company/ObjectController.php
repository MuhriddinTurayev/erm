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

        return view('company.objects.show', [
            'byRooms' => $byRooms, 'lastPays' => array_slice($lastPays, 0, 10), 'debtors' => array_slice($debtors, 0, 10),
            'ws' => $ws, 'p' => $p, 'pid' => $pid, 's' => self::summary($p), 'team' => $this->team($pid), 'cur' => \App\Services\CompanyStats::currency($ws),
            'media' => ObjectMedia::of($ws->id)->where('project_id', $pid)->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->orderBy('id')->get(),
            'roles' => config('oftob.roles'), 'tab' => request('tab', 'overview'),
        ]);
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
        $u = auth()->user();
        abort_unless($u->canEdit() && in_array($u->role, ['admin', 'manager', 'foreman', 'accountant'], true), 403, __('Нет доступа к этому разделу'));
    }

    /**
     * Загрузка частями (по 5 МБ): большие 3D-модели проходят даже при маленьком лимите хостинга.
     * Поля: upload (id загрузки), index, total, name, chunk; на последней части — kind, rooms, title.
     */
    public function mediaUpload(Request $request, string $pid)
    {
        $this->canMedia();
        $this->project($pid);
        $ws = $this->ws();
        $d = $request->validate([
            'upload' => ['required', 'string', 'regex:/^[a-z0-9]{16,40}$/'], 'index' => ['required', 'integer', 'min:0'], 'total' => ['required', 'integer', 'min:1', 'max:200'],
            'name' => ['required', 'string', 'max:200'], 'chunk' => ['required', 'file', 'max:6144'],
            'kind' => ['required', Rule::in(array_keys(ObjectMedia::KINDS))], 'rooms' => ['nullable', 'integer', 'min:0', 'max:6'], 'title' => ['nullable', 'string', 'max:160'],
            'attach' => ['nullable', 'integer'],   // id модели фасада: файл станет её состоянием «Готовый дом»
        ]);
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
        $m = ObjectMedia::create([
            'workspace_id' => $ws->id, 'project_id' => $pid, 'kind' => $d['kind'], 'rooms' => $d['rooms'] ?? null,
            'title' => ($d['title'] ?? '') ?: Str::limit(pathinfo($d['name'], PATHINFO_FILENAME), 150, ''), 'path' => $path, 'ext' => $ext,
            'size' => $disk->size($path), 'user_id' => $request->user()->id, 'is_public' => true,
            'is_main' => $d['kind'] === 'exterior' && ! ObjectMedia::of($ws->id)->where('project_id', $pid)->where('kind', 'exterior')->exists(),
            'meta' => in_array($ext, ['glb', 'gltf'], true) ? (self::glbFlags($disk->path($path)) ?: null) : null,
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
        $d = $request->validate(['title' => ['required', 'string', 'max:160'], 'rooms' => ['nullable', 'integer', 'min:0', 'max:6'], 'sort' => ['nullable', 'integer'],
            'camera' => ['nullable', 'string', 'max:120'], 'exposure' => ['nullable', 'numeric', 'min:0.2', 'max:3'], 'poster' => ['nullable', 'string', 'max:2000000']]);
        $media->fill(['title' => $d['title'], 'rooms' => $d['rooms'] ?? null, 'sort' => (int) ($d['sort'] ?? 0), 'is_public' => $request->boolean('is_public')]);
        $meta = (array) $media->meta;
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

    public function mediaDelete(ObjectMedia $media)
    {
        $this->mine($media);
        $this->canMedia();
        $this->checkProject($media->project_id);
        Storage::disk('local')->delete(array_filter([$media->path, $media->poster, $media->meta['finished'] ?? null]));
        $media->delete();

        return back()->with('ok', __('Файл удалён'));
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
        $media = ObjectMedia::of($ws->id)->where('project_id', $pid)->whereIn('kind', ['exterior', 'interior', 'pano'])->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->get();
        $cur = $media->firstWhere('id', (int) request('m')) ?? $media->first();

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $pid, 'media' => $media, 'cur' => $cur,
            'src' => fn (ObjectMedia $m, bool $poster = false) => url('/company/media/'.$m->id.'/file'.($poster ? '?poster=1' : '')), 'public' => false,
            'canEdit' => auth()->user()->canEdit() && in_array(auth()->user()->role, ['admin', 'manager', 'foreman', 'accountant'], true)]);
    }
}
