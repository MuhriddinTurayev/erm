<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;

/**
 * Доступ к данным CRM. Данные компании хранятся одной строкой JSON — здесь их фильтруем при чтении
 * и аккуратно объединяем при сохранении:
 *  — прораб или менеджер объекта видит и меняет только свои объекты;
 *  — любой сотрудник, кроме руководителя, не может добавить или удалить объект, поменять настройки компании,
 *    GPS-зону объекта, его название и пометку «демо» (раньше бухгалтер мог прислать пустой список и стереть всё);
 *  — пометку «демо» (объект вне лимита тарифа) нельзя поставить самому: она остаётся только у того, у кого была.
 */
class StateAccess
{
    /** Поля объекта, которые меняет только руководитель */
    private const ADMIN_ONLY = ['name', 'gps', 'demo'];

    /** Перечень помещений пишет только раздел «Объекты» (с проверкой и защитой сделок) — программа присылает его как есть */
    private const SERVER_ONLY = ['inv', 'invPrev'];

    /** Администратор объектов не меняет деньги, сделки, кадры и стройку — даже если программа их пришлёт */
    private const CATALOG_KEEP = ['tx', 'pays', 'staff', 'ts', 'fuel', 'fleet', 'labour', 'itr', 'mach', 'overhead', 'prices', 'extraMat', 'stages',
        'leads', 'legal', 'cands', 'safety', 'geoEv', 'gps', 'demo'];

    /** Данные, которые видит пользователь */
    public static function forUser(?string $json, User $user): ?string
    {
        $allowed = $user->allowedObjects();
        if ($allowed === null || $json === null || $json === '') {
            return $json;
        }
        $d = json_decode($json, true);
        if (! is_array($d) || ! is_array($d['projects'] ?? null)) {
            return $json;
        }
        $d['projects'] = array_values(array_filter($d['projects'], fn ($p) => in_array((string) ($p['id'] ?? ''), $allowed, true)));
        if (! in_array((string) ($d['currentId'] ?? ''), $allowed, true)) {
            $d['currentId'] = $d['projects'][0]['id'] ?? null;
        }

        return json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    /** Объединить сохранённое с присланным: руководитель меняет всё, остальные — только содержимое своих объектов */
    public static function merge(?string $stored, array $incoming, User $user): array
    {
        $base = json_decode((string) $stored, true);
        $hasBase = is_array($base) && is_array($base['projects'] ?? null) && $base['projects'];
        if ($user->isAdmin() || (! $hasBase && $user->allowedObjects() === null)) {
            $next = $incoming;   // руководитель — или самое первое сохранение компании
        } else {
            if (! $hasBase) {
                $base = ['settings' => $incoming['settings'] ?? [], 'projects' => [], 'currentId' => null];
            }
            $allowed = $user->allowedObjects();
            $mine = [];
            foreach ($incoming['projects'] as $p) {
                $id = (string) ($p['id'] ?? '');
                if (is_array($p) && $id !== '' && ($allowed === null || in_array($id, $allowed, true))) {
                    $mine[$id] = $p;
                }
            }
            $catalog = $user->isCatalog();
            // свои объекты — из присланного (кроме полей руководителя), остальные — как были; удаление — только у руководителя
            $base['projects'] = array_map(function ($p) use ($mine, $catalog) {
                $id = (string) ($p['id'] ?? '');
                if (! isset($mine[$id])) {
                    return $p;
                }
                $n = $mine[$id];
                foreach ($catalog ? array_diff(self::ADMIN_ONLY, ['name']) : self::ADMIN_ONLY as $k) {
                    self::keep($n, $p, $k);
                }
                if ($catalog) {
                    foreach (self::CATALOG_KEEP as $k) {
                        self::keep($n, $p, $k);
                    }
                    $n['apts'] = self::catalogApts((array) ($p['apts'] ?? []), (array) ($n['apts'] ?? []));
                }
                $n['id'] = $p['id'];

                return $n;
            }, $base['projects']);
            // новые объекты — у руководителя и у администратора объектов со всеми объектами (лимит тарифа проверяется отдельно)
            if ($catalog && $allowed === null) {
                $known = array_flip(array_map(fn ($p) => (string) ($p['id'] ?? ''), $base['projects']));
                foreach ($incoming['projects'] as $p) {
                    $id = (string) ($p['id'] ?? '');
                    if (is_array($p) && $id !== '' && ! isset($known[$id])) {
                        $p['tx'] = [];      // у нового объекта ещё нет денег и сделок
                        $p['apts'] = [];
                        $base['projects'][] = $p;
                    }
                }
            }
            $next = $base;   // настройки компании и текущий объект — как были
        }
        self::keepInventory(json_decode((string) $stored, true), $next);
        self::demoFlags(is_array($base) ? $base : null, $next);

        return $next;
    }

    /** Поле объекта — как было сохранено (или без него, если его не было) */
    private static function keep(array &$n, array $p, string $k): void
    {
        if (array_key_exists($k, $p)) {
            $n[$k] = $p[$k];
        } else {
            unset($n[$k]);
        }
    }

    /** Перечень помещений существующих объектов — только сохранённый (его меняет раздел «Объекты») */
    private static function keepInventory($old, array &$next): void
    {
        $was = [];
        foreach ((array) ($old['projects'] ?? []) as $p) {
            if (is_array($p) && isset($p['id'])) {
                $was[(string) $p['id']] = $p;
            }
        }
        foreach ($next['projects'] as &$p) {
            if (is_array($p) && isset($was[(string) ($p['id'] ?? '')])) {
                foreach (self::SERVER_ONLY as $k) {
                    self::keep($p, $was[(string) $p['id']], $k);
                }
            }
        }
        unset($p);
    }

    /** Администратор объектов: «в продаже» / «не в продаже» у свободных квартир; сделки и оплаты — без изменений */
    private static function catalogApts(array $stored, array $incoming): array
    {
        $out = $stored;
        foreach ($incoming as $id => $o) {
            $was = (array) ($stored[$id] ?? []);
            $st = is_array($o) ? (string) ($o['status'] ?? 'free') : 'free';
            if (in_array($was['status'] ?? 'free', ['sold', 'booked'], true) || ! empty($was['payments']) || ! in_array($st, ['free', 'reserve'], true)) {
                continue;
            }
            if ($st !== ($was['status'] ?? 'free')) {
                $was['status'] = $st;
                $out[$id] = $was;
            }
        }

        return $out;
    }

    /**
     * Пометка «демо» — только у объектов, которые были демо и раньше.
     * Исключение — самое первое сохранение: один демо-объект (пример) допускается.
     */
    public static function demoFlags(?array $old, array &$next): void
    {
        $was = [];
        foreach ((array) ($old['projects'] ?? []) as $p) {
            if (is_array($p) && ! empty($p['demo'])) {
                $was[(string) ($p['id'] ?? '')] = true;
            }
        }
        $free = ! $was && ! (is_array($old['projects'] ?? null) && $old['projects']);   // данных ещё не было
        foreach ($next['projects'] as &$p) {
            if (! is_array($p) || empty($p['demo'])) {
                continue;
            }
            $id = (string) ($p['id'] ?? '');
            if (isset($was[$id])) {
                continue;
            }
            if ($free) {
                $free = false;   // один пример при первом сохранении

                continue;
            }
            unset($p['demo']);
        }
        unset($p);
    }

    /** Не превышен ли лимит объектов тарифа (новые объекты сверх лимита — запрещены) */
    public static function exceedsLimit(Workspace $ws, ?string $stored, array $next): bool
    {
        $max = $ws->maxObjects();
        if ($max === 0) {
            return false;
        }
        $old = json_decode((string) $stored, true);
        $before = Workspace::countObjects(is_array($old['projects'] ?? null) ? $old['projects'] : []);
        $after = Workspace::countObjects($next['projects']);

        return $after > $max && $after > $before;
    }
}
