<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Изменение данных CRM со стороны сервера (бот, согласование расходов) — с блокировкой и новой версией,
 * чтобы программа в браузере подтянула изменения, а не затёрла их.
 */
class StateWriter
{
    /** @param callable(array &$data): mixed $fn */
    public static function mutate(int $workspaceId, callable $fn): mixed
    {
        return DB::transaction(function () use ($workspaceId, $fn) {
            $ws = Workspace::whereKey($workspaceId)->lockForUpdate()->first();
            $data = json_decode((string) $ws->data, true);
            if (! is_array($data) || ! is_array($data['projects'] ?? null)) {
                throw new \RuntimeException('No CRM data');
            }
            $before = $data;
            $res = $fn($data);
            $events = Deals::normalize($data, $before);   // сделки по квартирам: номер сделки, закрытые — в историю
            if ($data !== $before) {   // ничего не изменилось — версию не поднимаем
                $ws->data = json_encode($data, JSON_UNESCAPED_UNICODE);
                $ws->version = (int) $ws->version + 1;
                $ws->save();
            }
            Deals::applyLinks($workspaceId, $events);
            Api\Webhooks::stateChanged($workspaceId, $before, $data);   // квартиры: бронь, продажа, оплаты — в вебхуки

            return $res;
        });
    }

    /** Провести операцию в бухгалтерии объекта. Возвращает id операции. */
    public static function addTx(int $workspaceId, string $projectId, array $tx): string
    {
        return self::mutate($workspaceId, function (array &$data) use ($projectId, $tx) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') !== $projectId) {
                    continue;
                }
                $id = Str::lower(Str::random(8));
                $p['tx'] = $p['tx'] ?? [];
                $p['tx'][] = array_merge(['id' => $id, 'date' => now()->toDateString(), 'type' => 'out', 'cat' => 'Офис и прочее', 'amount' => 0, 'who' => '', 'desc' => ''], $tx, ['id' => $id]);

                return $id;
            }
            throw new \RuntimeException('Object not found');
        });
    }

    /** Список объектов компании: [id => название] */
    public static function projects(Workspace $ws, ?array $only = null): array
    {
        $out = [];
        foreach ($ws->projects() as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id !== '' && ($only === null || in_array($id, $only, true))) {
                $out[$id] = (string) ($p['name'] ?? $id);
            }
        }

        return $out;
    }

    public static function project(Workspace $ws, string $id): ?array
    {
        foreach ($ws->projects() as $p) {
            if ((string) ($p['id'] ?? '') === $id) {
                return $p;
            }
        }

        return null;
    }
}
