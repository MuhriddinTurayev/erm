<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\WorkerToken;
use App\Models\WorkSite;
use App\Services\WorkerGeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Приход сотрудников: личные ссылки, отметки «Пришёл / Ушёл» с GPS, расстояние до объекта */
class GeoController extends Controller
{
    /* ---------- телефон сотрудника (по личной ссылке, без входа) ---------- */
    public function page()
    {
        return view('worker');
    }

    private function worker(Request $request): WorkerToken
    {
        $t = (string) ($request->query('t') ?: $request->input('t'));
        $w = $t !== '' ? WorkerToken::where('token', $t)->first() : null;
        abort_unless($w, 403, 'Ссылка недействительна. Попросите новую у руководителя.');

        return $w;
    }

    public function workerState(Request $request)
    {
        $w = $this->worker($request);
        $site = WorkerGeo::site($w);
        $bot = \App\Services\Telegram::enabled() ? (string) \App\Models\PlatformSetting::get('telegram_bot', '') : '';

        // точка объекта — для карты на телефоне («где я и где объект»); бот — отметки через Telegram
        return response()->json(['name' => $w->name, 'role' => $w->role, 'site' => $site?->name ?? '', 'hasSite' => (bool) $site, 'r' => $site?->radius,
            'lat' => $site?->lat, 'lng' => $site?->lng, 'lang' => WorkerGeo::lang($w), 'bot' => $bot, 'tg' => (bool) $w->tg_chat_id] + WorkerGeo::state($w));
    }

    public function workerEvent(Request $request)
    {
        $w = $this->worker($request);
        $type = (string) $request->input('type');
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        if (! in_array($type, ['in', 'out', 'ping'], true)) {
            return response()->json(['error' => 'bad type'], 400);
        }
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return response()->json(['error' => 'Нет координат GPS'], 400);
        }
        $r = WorkerGeo::record($w, $type, (float) $lat, (float) $lng, (float) $request->input('acc', 0));
        unset($r['saved']);

        return response()->json($r);
    }

    /* ---------- для программы (после входа) ---------- */
    /** Программа сообщает, у кого какие ссылки и где находятся объекты */
    /**
     * Программа сообщает ссылки сотрудников и координаты объектов.
     * Права: «только просмотр» ничего не меняет; ссылки сотрудников — только по своим объектам;
     * GPS-зону объекта (где засчитывается приход) меняет только руководитель — иначе её можно сдвинуть и отмечаться из дома.
     */
    public function sync(Request $request)
    {
        $user = $request->user();
        $ws = $this->ws()->id;
        $only = $user->allowedObjects();
        $mine = fn ($pid) => $only === null || in_array((string) $pid, $only, true);
        $canEdit = $user->canEdit();
        $workers = collect($canEdit ? $request->input('workers', []) : [])
            ->filter(fn ($w) => is_array($w) && preg_match('/^[a-f0-9]{16,64}$/', (string) ($w['t'] ?? '')) && $mine($w['pid'] ?? ''))
            ->unique(fn ($w) => $w['t']);   // одна ссылка — один объект (первый): старая «копия объекта» дублировала ссылки, и ссылка прыгала между объектами
        DB::transaction(function () use ($ws, $workers, $request, $canEdit, $user, $mine) {
            foreach ($workers as $w) {
                $ex = WorkerToken::where('token', $w['t'])->first();
                if ($ex && ((int) $ex->workspace_id !== (int) $ws || ! $mine($ex->project_id))) {
                    continue; // чужой ключ (или ключ чужого объекта) не трогаем
                }
                if (! \App\Services\StateWriter::project($this->ws(), (string) $w['pid'])) {
                    continue;
                }
                WorkerToken::updateOrCreate(['token' => $w['t']], [
                    'workspace_id' => $ws, 'project_id' => mb_substr((string) $w['pid'], 0, 40), 'staff_id' => mb_substr((string) $w['sid'], 0, 40),
                    'name' => mb_substr((string) ($w['name'] ?? ''), 0, 80), 'role' => mb_substr((string) ($w['role'] ?? ''), 0, 60),
                ]);
            }
            $revoke = $canEdit ? array_filter((array) $request->input('revoke', []), 'is_string') : [];
            if ($revoke) {
                WorkerToken::where('workspace_id', $ws)->whereIn('token', $revoke)->get()->filter(fn ($t) => $mine($t->project_id))->each->delete();
            }
            foreach ($user->isAdmin() ? (array) $request->input('sites', []) : [] as $pid => $s) {
                if (! is_array($s) || ! is_numeric($s['lat'] ?? null) || ! is_numeric($s['lng'] ?? null)) {
                    continue;
                }
                WorkSite::updateOrCreate(['workspace_id' => $ws, 'project_id' => mb_substr((string) $pid, 0, 40)], [
                    'name' => mb_substr((string) ($s['name'] ?? ''), 0, 200), 'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'radius' => max(30, (int) ($s['r'] ?? 150)),
                ]);
            }
        });

        return response()->json(['ok' => true, 'publicUrl' => rtrim($this->ws()->integrationOrCreate()->public_url ?: url('/'), '/'), 'now' => now()->getTimestampMs()]);
    }

    public function events(Request $request)
    {
        $ws = $this->ws()->id;
        $only = $request->user()->allowedObjects();
        $since = (int) $request->query('since', 0);
        $limit = 2000;
        // граница берётся до выборки: отметка, пришедшая во время запроса, достанется следующему запросу, а не потеряется
        $top = (int) (Checkin::where('workspace_id', $ws)->max('id') ?? 0);
        $items = Checkin::where('workspace_id', $ws)->where('id', '>', $since)->where('id', '<=', $top)->when($only !== null, fn ($q) => $q->whereIn('project_id', $only))
            ->orderBy('id')->limit($limit)->get();
        // seq — последний отданный номер: если отметок больше лимита, остальные придут следующим запросом (раньше терялись)
        $more = $items->count() >= $limit;

        return response()->json([
            'seq' => $more ? (int) $items->last()->id : max($since, $top),
            'more' => $more,
            'items' => $items->map->toClient()->values(),
        ]);
    }
}
