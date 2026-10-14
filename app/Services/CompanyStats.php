<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Сводка по деньгам и объектам компании за день — из данных CRM (все объекты).
 * Используется в ежедневном отчёте руководителю, в панели компании и у супер-админа.
 */
class CompanyStats
{
    public static function build(Workspace $ws, ?string $date = null, ?array $onlyObjects = null): array
    {
        $date = $date ?: $ws->localNow()->toDateString();
        $d = Carbon::parse($date);
        $yest = $d->copy()->subDay()->toDateString();
        $month = substr($date, 0, 7);
        $weekFrom = $d->copy()->subDays(6)->toDateString();
        $days14 = $in14 = [];
        for ($i = 13; $i >= 0; $i--) {
            $days14[$d->copy()->subDays($i)->toDateString()] = 0.0;
            $in14[$d->copy()->subDays($i)->toDateString()] = 0.0;
        }

        $T = ['balance' => 0.0, 'in_all' => 0.0, 'out_all' => 0.0, 'out_day' => 0.0, 'in_day' => 0.0, 'out_yest' => 0.0, 'in_yest' => 0.0,
            'out_week' => 0.0, 'out_month' => 0.0, 'in_month' => 0.0, 'ops_day' => 0];
        $catsDay = $catsMonth = [];
        $top = $objects = [];

        foreach ($ws->projects() as $p) {
            if (! is_array($p) || ($onlyObjects !== null && ! in_array((string) ($p['id'] ?? ''), $onlyObjects, true))) {
                continue;
            }
            $o = ['id' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? '—'), 'status' => (string) ($p['status'] ?? ''), 'demo' => Workspace::isDemo($p),
                'balance' => 0.0, 'out_day' => 0.0, 'in_day' => 0.0, 'out_yest' => 0.0, 'out_month' => 0.0, 'in_month' => 0.0];
            foreach ((array) ($p['tx'] ?? []) as $t) {
                $amt = (float) ($t['amount'] ?? 0);
                $td = (string) ($t['date'] ?? '');
                $in = ($t['type'] ?? '') === 'in';
                $cat = (string) ($t['cat'] ?? '—');
                $o['balance'] += $in ? $amt : -$amt;
                $T[$in ? 'in_all' : 'out_all'] += $amt;
                if ($td === $date) {
                    $o[$in ? 'in_day' : 'out_day'] += $amt;
                    $T['ops_day']++;
                    if (! $in) {
                        $catsDay[$cat] = ($catsDay[$cat] ?? 0) + $amt;
                        $top[] = ['obj' => $o['name'], 'cat' => $cat, 'desc' => (string) ($t['desc'] ?? ''), 'who' => (string) ($t['who'] ?? ''), 'amount' => $amt];
                    }
                }
                if ($td === $yest) {
                    $in ? $T['in_yest'] += $amt : $o['out_yest'] += $amt;
                }
                if (str_starts_with($td, $month) && $td <= $date) {
                    $o[$in ? 'in_month' : 'out_month'] += $amt;
                    if (! $in) {
                        $catsMonth[$cat] = ($catsMonth[$cat] ?? 0) + $amt;
                    }
                }
                if (! $in && $td >= $weekFrom && $td <= $date) {
                    $T['out_week'] += $amt;
                }
                if (! $in && isset($days14[$td])) {
                    $days14[$td] += $amt;
                }
                if ($in && isset($in14[$td])) {
                    $in14[$td] += $amt;
                }
            }
            // готовность: средняя по этапам с весом длительности
            $w = $s = 0;
            $overdue = 0;
            foreach ((array) ($p['stages'] ?? []) as $st) {
                $dur = max(1, (int) ($st['dur'] ?? 1));
                $pr = (float) ($st['progress'] ?? 0);
                $w += $dur;
                $s += $dur * $pr;
                $end = isset($st['start']) ? Carbon::parse($st['start'])->addDays($dur)->toDateString() : null;
                if ($end && $end < $date && $pr < 100) {
                    $overdue++;
                }
            }
            $o['progress'] = $w ? round($s / $w, 1) : 0;
            $o['overdue'] = $overdue;
            $o['staff'] = count(array_filter((array) ($p['staff'] ?? []), fn ($x) => ($x['active'] ?? true) !== false));
            $T['balance'] += $o['balance'];
            $T['out_day'] += $o['out_day'];
            $T['in_day'] += $o['in_day'];
            $T['out_yest'] += $o['out_yest'];
            $T['out_month'] += $o['out_month'];
            $T['in_month'] += $o['in_month'];
            $objects[] = $o;
        }

        arsort($catsDay);
        arsort($catsMonth);
        usort($top, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        usort($objects, fn ($a, $b) => $b['out_month'] <=> $a['out_month']);

        return [
            'date' => $date,
            'yesterday' => $yest,
            'company' => $ws->name,
            'currency' => self::currency($ws),
            'totals' => $T,
            'diff_pct' => $T['out_yest'] > 0 ? round(($T['out_day'] - $T['out_yest']) / $T['out_yest'] * 100) : null,
            'cats_day' => $catsDay,
            'cats_month' => $catsMonth,
            'top' => array_slice($top, 0, 7),
            'objects' => $objects,
            'days14' => $days14,
            'in14' => $in14,
        ];
    }

    public static function currency(Workspace $ws): string
    {
        $raw = Workspace::whereKey($ws->id)->value('data');
        if (is_string($raw) && preg_match('/"currency"\s*:\s*"([^"]{1,8})"/u', $raw, $m)) {
            return $m[1];
        }

        return 'сум';
    }

    /** 12 345 678 */
    public static function money(float $n): string
    {
        return number_format(round($n), 0, '.', ' ');
    }

    /** 12,3 млн */
    public static function short(float $n): string
    {
        $a = abs($n);

        return match (true) {
            $a >= 1e12 => self::num($n / 1e12, 2).' '.__('трлн'),
            $a >= 1e9 => self::num($n / 1e9, 2).' '.__('млрд'),
            $a >= 1e6 => self::num($n / 1e6, 1).' '.__('млн'),
            $a >= 1e4 => self::num($n / 1e3, 0).' '.__('тыс'),
            default => self::num($n, 0),
        };
    }

    private static function num(float $n, int $d): string
    {
        $s = number_format($n, $d, ',', ' ');

        return $d > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
    }
}
