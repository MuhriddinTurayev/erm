<?php

namespace App\Services;

use App\Models\AuditLog;

/** Сравнивает данные CRM до и после сохранения и записывает понятную историю изменений */
class AuditDiff
{
    public static function record(int $workspaceId, ?int $userId, ?string $oldJson, string $newJson): void
    {
        $old = json_decode((string) $oldJson, true);
        $new = json_decode($newJson, true);
        if (! is_array($old) || ! is_array($new)) {
            return;
        }
        $rows = self::diff((array) ($old['projects'] ?? []), (array) ($new['projects'] ?? []));
        if (! $rows) {
            return;
        }
        $now = now();
        AuditLog::insert(array_map(fn ($r) => $r + ['workspace_id' => $workspaceId, 'user_id' => $userId, 'created_at' => $now], array_slice($rows, 0, 200)));
    }

    /** @return array<int, array{project:string, action:string, summary:string}> */
    public static function diff(array $oldProjects, array $newProjects): array
    {
        $idx = fn (array $list) => array_column(array_filter($list, 'is_array'), null, 'id');
        $op = $idx($oldProjects);
        $np = $idx($newProjects);
        $out = [];
        $add = function (string $project, string $action, string $summary) use (&$out) {
            $out[] = ['project' => mb_substr($project, 0, 120), 'action' => $action, 'summary' => mb_substr($summary, 0, 500)];
        };
        $money = fn ($v) => number_format((float) $v, 0, '.', ' ');

        foreach ($np as $id => $p) {
            $name = (string) ($p['name'] ?? $id);
            if (! isset($op[$id])) {
                $add($name, 'project.add', 'Создан объект «'.$name.'»');

                continue;
            }
            $o = $op[$id];
            if (($o['name'] ?? '') !== ($p['name'] ?? '')) {
                $add($name, 'project.edit', 'Объект переименован: «'.($o['name'] ?? '').'» → «'.$name.'»');
            }
            // операции бухгалтерии
            $ot = $idx((array) ($o['tx'] ?? []));
            $nt = $idx((array) ($p['tx'] ?? []));
            $txLine = fn ($t) => (($t['type'] ?? '') === 'in' ? '+' : '−').$money($t['amount'] ?? 0).' · '.($t['cat'] ?? '').(! empty($t['desc']) ? ' · '.$t['desc'] : '').(! empty($t['who']) ? ' · '.$t['who'] : '');
            foreach ($nt as $tid => $t) {
                if (! isset($ot[$tid])) {
                    $add($name, 'tx.add', 'Операция '.($t['date'] ?? '').': '.$txLine($t));
                } elseif (($ot[$tid]['amount'] ?? null) != ($t['amount'] ?? null) || ($ot[$tid]['cat'] ?? null) !== ($t['cat'] ?? null) || ($ot[$tid]['date'] ?? null) !== ($t['date'] ?? null)) {
                    $add($name, 'tx.edit', 'Изменена операция: '.$txLine($ot[$tid]).' → '.$txLine($t));
                }
            }
            foreach ($ot as $tid => $t) {
                if (! isset($nt[$tid])) {
                    $add($name, 'tx.del', 'Удалена операция '.($t['date'] ?? '').': '.$txLine($t));
                }
            }
            // сотрудники
            $os = $idx((array) ($o['staff'] ?? []));
            $ns = $idx((array) ($p['staff'] ?? []));
            foreach ($ns as $sid => $s) {
                if (! isset($os[$sid])) {
                    $add($name, 'staff.add', 'Принят сотрудник: '.($s['name'] ?? '').' ('.($s['role'] ?? '').')');
                } elseif (($os[$sid]['rate'] ?? null) != ($s['rate'] ?? null)) {
                    $add($name, 'staff.rate', 'Ставка '.($s['name'] ?? '').': '.$money($os[$sid]['rate'] ?? 0).' → '.$money($s['rate'] ?? 0));
                }
            }
            foreach ($os as $sid => $s) {
                if (! isset($ns[$sid])) {
                    $add($name, 'staff.del', 'Удалён сотрудник: '.($s['name'] ?? ''));
                }
            }
            // квартиры: статус, покупатель, цена
            $oa = (array) ($o['apts'] ?? []);
            foreach ((array) ($p['apts'] ?? []) as $aid => $a) {
                $was = (array) ($oa[$aid] ?? []);
                if (($was['status'] ?? 'free') !== ($a['status'] ?? 'free')) {
                    $add($name, 'apt.status', 'Квартира '.$aid.': '.(Apartments::STATUS[$was['status'] ?? 'free'] ?? '').' → '.(Apartments::STATUS[$a['status'] ?? 'free'] ?? '').(! empty($a['buyer']) ? ' · '.$a['buyer'] : ''));
                } elseif (isset($was['price'], $a['price']) && $was['price'] != $a['price']) {
                    $add($name, 'apt.price', 'Квартира '.$aid.': цена '.$money($was['price']).' → '.$money($a['price']));
                }
            }
            // этапы: готовность
            $ost = $idx((array) ($o['stages'] ?? []));
            foreach ((array) ($p['stages'] ?? []) as $st) {
                $sid = $st['id'] ?? null;
                if ($sid && isset($ost[$sid]) && ($ost[$sid]['progress'] ?? 0) != ($st['progress'] ?? 0)) {
                    $add($name, 'stage', 'Этап «'.($st['name'] ?? '').'»: '.($ost[$sid]['progress'] ?? 0).'% → '.($st['progress'] ?? 0).'%');
                }
            }
        }
        foreach ($op as $id => $p) {
            if (! isset($np[$id])) {
                $add((string) ($p['name'] ?? $id), 'project.del', 'Удалён объект «'.($p['name'] ?? $id).'»');
            }
        }

        return $out;
    }
}
