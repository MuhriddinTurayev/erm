<?php

namespace App\Services;

use App\Models\Checkin;
use App\Models\WorkerToken;
use App\Models\WorkSite;
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
        $ev = Checkin::where('token', $w->token)->where('created_at', '>=', now()->subHours(40))->orderBy('id')->get();
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
}
