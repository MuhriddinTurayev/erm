<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\WorkerToken;
use App\Models\WorkSite;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Смена сотрудника по личной ссылке: «Пришёл / Ушёл» и отметки местоположения.
 * Одни правила для телефона (страница сотрудника) и для Telegram (местоположение и трансляция геопозиции).
 */
class WorkerGeo
{
    /** Расстояние между точками, м */
    public static function dist(float $a, float $b, float $c, float $d): int
    {
        $r = fn ($x) => $x * M_PI / 180;
        $s = sin($r($c - $a) / 2) ** 2 + cos($r($a)) * cos($r($c)) * sin($r($d - $b) / 2) ** 2;

        return (int) round(6371000 * 2 * atan2(sqrt($s), sqrt(1 - $s)));
    }

    public static function site(WorkerToken $w): ?WorkSite
    {
        return WorkSite::where('workspace_id', $w->workspace_id)->where('project_id', $w->project_id)->first();
    }

    /** Язык компании — на нём говорим с сотрудником */
    public static function lang(WorkerToken $w): string
    {
        $l = json_decode((string) DB::table('workspaces')->where('id', $w->workspace_id)->value('data'), true)['settings']['lang'] ?? 'ru';

        return in_array($l, ['ru', 'uz', 'tg'], true) ? $l : 'ru';
    }

    /** Состояние смены: открыта ли, сколько отработано сегодня, последняя отметка */
    public static function state(WorkerToken $w): array
    {
        // по времени, а не по порядку записи: руководитель может закрыть забытую смену задним числом
        $ev = Checkin::where('token', $w->token)->where('created_at', '>=', now()->subHours(40))->orderBy('created_at')->orderBy('id')->get();
        $open = null;
        $ms = 0;
        $today = now()->startOfDay();
        foreach ($ev as $e) {
            if ($e->type === 'in') {
                $open = $e;
            } elseif ($e->type === 'out' && $open) {
                if ($e->created_at >= $today) {
                    $ms += ($e->created_at->getTimestamp() - max($open->created_at->getTimestamp(), $today->getTimestamp())) * 1000;
                }
                $open = null;
            }
        }
        if ($open && $open->created_at->lt(now()->subHours(18))) {
            $open = null; // забытая смена старше 18 ч не считается открытой
        }
        $last = $ev->last();

        return [
            'open' => $open?->created_at->toIso8601String(),
            'todayMs' => $ms,
            'last' => $last ? ['t' => $last->created_at->toIso8601String(), 'type' => $last->type] : null,
        ];
    }

    /**
     * Отметка: in — начало смены, out — конец, ping — «я здесь» во время смены (не чаще раза в минуту).
     * Лишнее (вторая «Пришёл», «Ушёл» без смены, частые ping) не записывается.
     *
     * @return array{ok: bool, saved: bool, dist: ?int, on: ?bool}+array
     */
    public static function record(WorkerToken $w, string $type, float $lat, float $lng, ?float $acc = null): array
    {
        $st = self::state($w);
        $lastT = $st['last'] ? strtotime($st['last']['t']) : 0;
        if (($type === 'in' && $st['open']) || ($type !== 'in' && ! $st['open']) || ($type === 'ping' && now()->getTimestamp() - $lastT < 60)) {
            return ['ok' => true, 'saved' => false, 'dist' => null, 'on' => null] + $st;
        }
        $site = self::site($w);
        $dist = $site ? self::dist($lat, $lng, $site->lat, $site->lng) : null;
        $on = $dist === null ? null : $dist <= ($site->radius ?: 150);
        Checkin::create([
            'workspace_id' => $w->workspace_id, 'token' => $w->token, 'project_id' => $w->project_id, 'staff_id' => $w->staff_id,
            'type' => $type, 'lat' => round($lat, 6), 'lng' => round($lng, 6), 'acc' => (int) round((float) $acc), 'dist' => $dist, 'on_site' => $on,
        ]);

        return ['ok' => true, 'saved' => true, 'dist' => $dist, 'on' => $on] + self::state($w);
    }

    /**
     * Руководитель закрывает смену за сотрудника (забыл нажать «Ушёл», сел телефон): отметка «Ушёл» на указанное время.
     * $inSeq — какая смена (номер отметки «Пришёл»); без него — последняя незакрытая. Время — не раньше прихода,
     * не позже «сейчас», следующего прихода и суток от начала смены. Точка — последняя известная: GPS не измерялся,
     * поэтому «на объекте или нет» не ставится; видно, кто закрыл и почему.
     *
     * @return array{ok: bool, error?: string, event?: array, ms?: int}
     */
    public static function close(WorkerToken $w, ?int $inSeq, ?Carbon $at, string $by, string $note = ''): array
    {
        $ev = Checkin::where('token', $w->token)->where('created_at', '>=', now()->subHours(72))->orderBy('created_at')->orderBy('id')->get()->values();
        $i = $inSeq !== null
            ? $ev->search(fn ($e) => $e->id === $inSeq && $e->type === 'in')
            : $ev->keys()->reverse()->first(fn ($k) => $ev[$k]->type === 'in');
        if ($i === false || $i === null) {
            return ['ok' => false, 'error' => __('Смена не найдена')];
        }
        $in = $ev[$i];
        $after = $ev->slice($i + 1);
        $next = $after->first(fn ($e) => $e->type === 'in');
        if ($after->takeUntil(fn ($e) => $e->type === 'in')->contains(fn ($e) => $e->type === 'out')) {
            return ['ok' => false, 'error' => __('Смена уже закрыта')];
        }
        $tz = Workspace::find($w->workspace_id)?->timezone ?: config('app.timezone');
        $hm = fn (Carbon $t) => $t->copy()->timezone($tz)->format('d.m H:i');
        $at = $at ?? now();
        $limit = now();
        if ($next && $next->created_at->lt($limit)) {
            $limit = $next->created_at->copy();
        }
        if ($in->created_at->copy()->addDay()->lt($limit)) {
            $limit = $in->created_at->copy()->addDay();
        }
        if ($at->lt($in->created_at)) {
            return ['ok' => false, 'error' => __('Время ухода раньше прихода (:t)', ['t' => $hm($in->created_at)])];
        }
        if ($at->gt($limit->copy()->addMinute())) {
            return ['ok' => false, 'error' => __('Время ухода — не позже :t', ['t' => $hm($limit)])];
        }
        $at = $at->gt($limit) ? $limit : $at;
        $pos = $after->takeUntil(fn ($e) => $e->type === 'in')->filter(fn ($e) => $e->created_at->lte($at))->last() ?? $in;
        $c = new Checkin([
            'workspace_id' => $w->workspace_id, 'token' => $w->token, 'project_id' => $w->project_id, 'staff_id' => $w->staff_id,
            'type' => 'out', 'lat' => $pos->lat, 'lng' => $pos->lng, 'acc' => 0, 'dist' => null, 'on_site' => null,
            'closed_by' => mb_substr($by, 0, 80), 'note' => mb_substr($note, 0, 200) ?: null,
        ]);
        $c->created_at = $at->copy();
        $c->save();

        return ['ok' => true, 'event' => $c->toClient(), 'ms' => ($at->getTimestamp() - $in->created_at->getTimestamp()) * 1000];
    }
}
