<?php

namespace App\Http\Controllers;

use App\Models\BuyerLink;
use App\Models\LeadInbox;
use App\Models\PlatformSetting;
use App\Models\SiteLog;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\Inventory;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Страницы без входа: шахматка для сайта застройщика и личный кабинет покупателя */
class PublicController extends Controller
{
    private static function progress(array $p): float
    {
        $w = $s = 0;
        foreach ((array) ($p['stages'] ?? []) as $st) {
            $d = max(1, (int) ($st['dur'] ?? 1));
            $w += $d;
            $s += $d * (float) ($st['progress'] ?? 0);
        }

        return $w ? round($s / $w, 1) : 0;
    }

    /* ---------- публичная шахматка ---------- */

    public function chess(Request $request, string $key)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        abort_unless($ws->hasAccess(), 404);
        // на сайте — только объекты, выставленные на продажу (пока администратор объектов готовит перечень, объекта не видно)
        $onSale = array_values(array_filter($ws->projects(), fn ($p) => Inventory::ready($p)));
        $projects = array_values(array_filter($onSale, fn ($p) => ! Workspace::isDemo($p))) ?: $onSale;
        $cur = collect($projects)->firstWhere('id', $request->query('o')) ?? ($projects[0] ?? null);
        abort_unless($cur, 404);
        $apts = array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'num', 'section', 'floor', 'rooms', 'area', 'price', 'status', 'kind', 'type', 'typeName', 'block', 'entr'])), Apartments::list($cur));
        // чертёж, 3D, 360° и фото комнат каждой планировки — покупатель видит их, выбрав квартиру
        $media = \App\Models\ObjectMedia::of($ws->id)->where('project_id', (string) $cur['id'])->where('is_public', true)->whereIn('kind', ['plan', 'interior', 'pano', 'photo'])->orderBy('sort')->orderBy('id')->get();
        $plans = [];
        $tours = [];
        foreach ($media->whereNotNull('layout') as $m) {
            if ($m->kind === 'plan' && $m->isImage()) {
                $plans[$m->layout] ??= url("/s/{$key}/m/{$m->id}");
            } elseif ($m->kind !== 'plan') {
                $tours[$m->layout] = true;
            }
        }

        return view('public.chess', [
            'ws' => $ws, 'projects' => $projects, 'p' => $cur, 'apts' => $apts, 'progress' => self::progress($cur),
            'currency' => \App\Services\CompanyStats::currency($ws), 'plans' => $plans, 'tours' => $tours,
        ]);
    }

    public function chessLead(Request $request, string $key)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        $d = $request->validate(['name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:40'], 'apt' => ['nullable', 'string', 'max:60'], 'rooms' => ['nullable', 'string', 'max:2']]);
        LeadInbox::create(['workspace_id' => $ws->id, 'ch' => 'site', 'kind' => 'form', 'key' => preg_replace('/\D/', '', $d['phone']) ?: $d['phone'],
            'name' => $d['name'], 'phone' => $d['phone'], 'src' => 'Шахматка', 'rooms' => $d['rooms'] ?? null, 'text' => $d['apt'] ? 'Интересует: '.$d['apt'] : null]);

        return back()->with('ok', __('Спасибо! Менеджер свяжется с вами в ближайшее время.'));
    }

    /* ---------- кабинет покупателя ---------- */

    private function buyer(string $token): array
    {
        $bl = BuyerLink::where('token', $token)->firstOrFail();
        $ws = $bl->workspace;
        abort_unless($ws && $ws->hasAccess(), 404);
        $p = StateWriter::project($ws, $bl->project_id);
        abort_unless($p, 404);
        $a = Apartments::find($p, $bl->apt_id);
        abort_unless($a && in_array($a['status'], ['sold', 'booked'], true), 404);
        // ссылка открывает только свою сделку: после продажи квартиры другому покупателю прежняя ссылка не работает
        abort_unless($bl->deal && $bl->deal === \App\Services\Deals::current($p, $bl->apt_id), 404);

        return [$bl, $ws, $p, $a];
    }

    public function cabinet(string $token)
    {
        [$bl, $ws, $p, $a] = $this->buyer($token);
        $photos = SiteLog::where('workspace_id', $ws->id)->where('project_id', $bl->project_id)->whereNotNull('photos')->latest('date')->latest('id')->limit(8)->get();

        return view('public.cabinet', [
            'bl' => $bl, 'ws' => $ws, 'p' => $p, 'a' => $a, 'schedule' => Apartments::schedule($a), 'overdue' => Apartments::overdue($a),
            'next' => Apartments::nextPayment($a), 'progress' => self::progress($p), 'photos' => $photos,
            'currency' => \App\Services\CompanyStats::currency($ws), 'bot' => PlatformSetting::get('telegram_bot'),
            'stages' => array_slice((array) ($p['stages'] ?? []), 0, 16),
        ]);
    }

    /* ---------- 3D для покупателей: только открытые файлы своего объекта ---------- */

    /** Открытые файлы объекта: здание целиком и — для квартиры — файлы её планировки (у старых файлов — по числу комнат) */
    private function publicMedia(int $wsId, string $pid, ?int $rooms = null, ?string $layout = null, ?string $apt = null)
    {
        $all = \App\Models\ObjectMedia::of($wsId)->where('project_id', $pid)->where('is_public', true)->whereIn('kind', ['exterior', 'interior', 'pano', 'photo', 'plan'])
            ->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->orderBy('id')->get();
        if ($rooms === null && $layout === null) {
            return $all->whereIn('kind', ['exterior', 'interior', 'pano'])->values();
        }

        // квартира: дом целиком и всё о ней изнутри — 3D, комнаты (360° и рендеры), чертёж
        $inner = $all->where('kind', '!=', 'exterior');

        return $all->where('kind', 'exterior')->concat($apt !== null ? \App\Models\ObjectMedia::forApt($inner, $apt, $layout, (int) $rooms) : \App\Models\ObjectMedia::forUnitAll($inner, $layout, (int) $rooms))->values();
    }

    public function chess3d(Request $request, string $key)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        $pid = (string) $request->query('o', '');
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        abort_unless(Inventory::ready($p), 404);
        $layout = $request->query('t') && isset(\App\Http\Controllers\Company\ObjectController::layouts($p)[$request->query('t')]) ? (string) $request->query('t') : null;
        // u — квартира из 3D-дома на сайте: её собственный интерьер (и такой же у отмеченных одинаковых), иначе — её планировки
        $apt = $request->query('u') ? Apartments::find($p, (string) $request->query('u')) : null;
        if ($apt) {
            $media = $this->publicMedia($ws->id, $pid, (int) $apt['rooms'], ($apt['type'] ?? null) ?: null, (string) $apt['id']);
            $unit = trim(__('Кв.').' '.$apt['num'].($apt['rooms'] ? ' · '.$apt['rooms'].__('-комн.') : ''));
        } else {
            $media = $this->publicMedia($ws->id, $pid, $request->has('r') ? (int) $request->query('r') : ($layout ? (int) (\App\Http\Controllers\Company\ObjectController::layouts($p)[$layout]['rooms'] ?? 0) : null), $layout);
            $unit = $layout ? (string) (\App\Http\Controllers\Company\ObjectController::layouts($p)[$layout]['name'] ?? '') : ($request->has('r') ? (int) $request->query('r').__('-комн.') : null);
        }

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $pid, 'media' => $media, 'cur' => $media->firstWhere('id', (int) $request->query('m')) ?? $media->first(), 'unit' => $unit,
            'src' => fn ($m, bool $poster = false) => url("/s/{$key}/m/{$m->id}".($poster ? '?poster=1' : '')), 'public' => true, 'canEdit' => false, 'embed' => $request->boolean('embed')]);
    }

    public function chessMedia(Request $request, string $key, int $id)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        $m = \App\Models\ObjectMedia::of($ws->id)->where('is_public', true)->findOrFail($id);

        return \App\Http\Controllers\Company\ObjectController::stream($m, $request->boolean('poster'), $request->query('v') === 'finished');
    }

    public function cabinet3d(Request $request, string $token)
    {
        [$bl, $ws, $p, $a] = $this->buyer($token);
        $media = $this->publicMedia($ws->id, $bl->project_id, (int) $a['rooms'], $a['type'] ?? null, (string) $a['id']);

        $unit = trim(__('Кв.').' '.$a['num'].($a['rooms'] ? ' · '.$a['rooms'].__('-комн.') : '').(! empty($a['area']) ? ' · '.$a['area'].' '.__('м²') : ''));

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $bl->project_id, 'media' => $media, 'cur' => $media->firstWhere('id', (int) $request->query('m')) ?? $media->first(), 'unit' => $unit,
            'src' => fn ($m, bool $poster = false) => url("/b/{$token}/m/{$m->id}".($poster ? '?poster=1' : '')), 'public' => true, 'canEdit' => false, 'embed' => $request->boolean('embed')]);
    }

    /** Квартира на 3D-модели дома — для сайта застройщика (шахматка) */
    public function chessLocate(Request $request, string $key)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        $pid = (string) $request->query('o', '');
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        abort_unless(Inventory::ready($p), 404);
        $model = \App\Services\Locate::model($ws->id, $pid, null, true);

        return $this->locateView($p, $pid, $model, (string) $request->query('u', ''), fn ($m) => url("/s/{$key}/m/{$m->id}"), url("/s/{$key}?o={$pid}"), $request->boolean('embed'),
            $ws->id, ['view' => url("/s/{$key}/3d?o={$pid}&t={t}&r={r}&u={u}&embed=1"), 'file' => url("/s/{$key}/m/{id}")]);
    }

    /** Квартира покупателя на 3D-модели дома — из его кабинета */
    public function cabinetLocate(Request $request, string $token)
    {
        [$bl, $ws, $p, $a] = $this->buyer($token);
        $model = \App\Services\Locate::model($ws->id, $bl->project_id, null, true);

        return $this->locateView($p, $bl->project_id, $model, (string) $a['id'], fn ($m) => url("/b/{$token}/m/{$m->id}"), url("/b/{$token}"), $request->boolean('embed'),
            $ws->id, ['view' => url("/b/{$token}/3d?embed=1"), 'file' => url("/b/{$token}/m/{id}")]);
    }

    /** $urls: view — страница 3D «Внутри квартиры» ({t} — планировка, {r} — комнат), file — файл интерьера ({id}) */
    private function locateView(array $p, string $pid, $model, string $unit, \Closure $src, string $back, bool $embed, int $wsId, array $urls)
    {
        $data = \App\Services\Locate::data($p);
        // покупателям — только привязанные корпуса: без настройки рамка по всей модели может указать не туда
        $cal = (array) ($model?->meta['locate']['blocks'] ?? []);

        return view('company.objects.locate', [
            'p' => $p, 'pid' => $pid, 'model' => $model && $cal ? $model : null, 'data' => $data, 'unit' => $unit, 'embed' => $embed, 'calibrate' => false, 'canEdit' => false,
            'src' => $model && $cal ? $src($model).(! empty($model->meta['finished']) ? '?v=finished' : '') : null, 'saveUrl' => null, 'back' => $back, 'public' => true,
            'inner' => \App\Services\Locate::media($wsId, $pid, true), 'viewUrl' => $urls['view'], 'fileUrl' => $urls['file'],
        ]);
    }

    public function cabinetMedia(Request $request, string $token, int $id)
    {
        [$bl] = $this->buyer($token);
        $m = \App\Models\ObjectMedia::of($bl->workspace_id)->where('project_id', $bl->project_id)->where('is_public', true)->findOrFail($id);

        return \App\Http\Controllers\Company\ObjectController::stream($m, $request->boolean('poster'), $request->query('v') === 'finished');
    }

    public function cabinetPhoto(string $token, SiteLog $log, int $i)
    {
        [$bl] = $this->buyer($token);
        $path = $log->photos[$i] ?? null;
        abort_unless($log->workspace_id === $bl->workspace_id && $log->project_id === $bl->project_id && $path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }
}
