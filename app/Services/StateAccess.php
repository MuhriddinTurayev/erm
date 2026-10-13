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
            // свои объекты — из присланного (кроме полей руководителя), остальные — как были; новые объекты и удаление — только у руководителя
            $base['projects'] = array_map(function ($p) use ($mine) {
                $id = (string) ($p['id'] ?? '');
                if (! isset($mine[$id])) {
                    return $p;
                }
                $n = $mine[$id];
                foreach (self::ADMIN_ONLY as $k) {
                    if (array_key_exists($k, $p)) {
                        $n[$k] = $p[$k];
                    } else {
                        unset($n[$k]);
                    }
                }
                $n['id'] = $p['id'];

                return $n;
            }, $base['projects']);
            $next = $base;   // настройки компании и текущий объект — как были
        }
        self::demoFlags(is_array($base) ? $base : null, $next);

        return $next;
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
