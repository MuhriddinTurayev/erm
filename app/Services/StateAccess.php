<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;

/**
 * Доступ к объектам: прораб или менеджер может видеть только свои объекты.
 * Данные CRM хранятся одной строкой JSON — здесь их фильтруем при чтении и аккуратно объединяем при сохранении.
 */
class StateAccess
{
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

    /** Объединить сохранённое с присланным пользователем с ограниченным доступом */
    public static function merge(?string $stored, array $incoming, User $user): array
    {
        $allowed = $user->allowedObjects();
        if ($allowed === null) {
            return $incoming;
        }
        $base = json_decode((string) $stored, true);
        if (! is_array($base) || ! is_array($base['projects'] ?? null)) {
            $base = ['settings' => $incoming['settings'] ?? [], 'projects' => [], 'currentId' => null];
        }
        $mine = [];
        foreach ($incoming['projects'] as $p) {
            $id = (string) ($p['id'] ?? '');
            if (in_array($id, $allowed, true)) {
                $mine[$id] = $p;
            }
        }
        // свои объекты — из присланного, чужие — как были; новые объекты и удаление — только у руководителя
        $base['projects'] = array_map(fn ($p) => $mine[(string) ($p['id'] ?? '')] ?? $p, $base['projects']);

        return $base;
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
