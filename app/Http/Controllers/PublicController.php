<?php

namespace App\Http\Controllers;

use App\Models\BuyerLink;
use App\Models\LeadInbox;
use App\Models\PlatformSetting;
use App\Models\SiteLog;
use App\Models\Workspace;
use App\Services\Apartments;
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
        $projects = array_values(array_filter($ws->projects(), fn ($p) => ! Workspace::isDemo($p))) ?: $ws->projects();
        $cur = collect($projects)->firstWhere('id', $request->query('o')) ?? ($projects[0] ?? null);
        abort_unless($cur, 404);
        $apts = array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'num', 'section', 'floor', 'rooms', 'area', 'price', 'status'])), Apartments::list($cur));

        return view('public.chess', [
            'ws' => $ws, 'projects' => $projects, 'p' => $cur, 'apts' => $apts, 'progress' => self::progress($cur),
            'currency' => \App\Services\CompanyStats::currency($ws),
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
        $p = StateWriter::project($ws, $bl->project_id);
        abort_unless($p, 404);
        $a = Apartments::find($p, $bl->apt_id);
        abort_unless($a && in_array($a['status'], ['sold', 'booked'], true), 404);

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

    private function publicMedia(int $wsId, string $pid, ?int $rooms = null)
    {
        return \App\Models\ObjectMedia::of($wsId)->where('project_id', $pid)->where('is_public', true)->whereIn('kind', ['exterior', 'interior', 'pano'])
            ->when($rooms !== null, fn ($q) => $q->where(fn ($x) => $x->where('kind', 'exterior')->orWhereNull('rooms')->orWhere('rooms', $rooms)))
            ->orderByDesc('is_main')->orderBy('kind')->orderBy('sort')->get();
    }

    public function chess3d(Request $request, string $key)
    {
        $ws = Workspace::where('public_key', $key)->where('public_enabled', true)->firstOrFail();
        $pid = (string) $request->query('o', '');
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        $media = $this->publicMedia($ws->id, $pid, $request->has('r') ? (int) $request->query('r') : null);

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $pid, 'media' => $media, 'cur' => $media->firstWhere('id', (int) $request->query('m')) ?? $media->first(),
            'src' => fn ($m, bool $poster = false) => url("/s/{$key}/m/{$m->id}".($poster ? '?poster=1' : '')), 'public' => true, 'canEdit' => false]);
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
        $media = $this->publicMedia($ws->id, $bl->project_id, (int) $a['rooms']);

        return view('company.objects.viewer', ['ws' => $ws, 'p' => $p, 'pid' => $bl->project_id, 'media' => $media, 'cur' => $media->firstWhere('id', (int) $request->query('m')) ?? $media->first(),
            'src' => fn ($m, bool $poster = false) => url("/b/{$token}/m/{$m->id}".($poster ? '?poster=1' : '')), 'public' => true, 'canEdit' => false]);
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
