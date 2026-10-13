<?php

namespace App\Services;

use App\Models\BuyerLink;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Сделки по квартирам. У каждой продажи/брони — свой номер сделки (deal).
 * Квартира освободилась (бронь снята, сделка расторгнута) — оплаты и покупатель уходят в историю квартиры,
 * следующий покупатель начинает с нуля, а ссылка на кабинет прежнего покупателя перестаёт открываться.
 */
class Deals
{
    private const ACTIVE = ['sold', 'booked'];

    private static function digits(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }

    /**
     * Привести данные CRM к правилам сделок. $before — данные до изменения (для сравнения).
     * Возвращает события для ссылок покупателей: ['bind' => [[pid, apt, deal]], 'end' => [[pid, apt]]].
     */
    public static function normalize(array &$data, ?array $before = null): array
    {
        $events = ['bind' => [], 'end' => [], 'changed' => false];
        $old = [];
        foreach ((array) ($before['projects'] ?? []) as $p) {
            if (is_array($p) && isset($p['id'])) {
                $old[(string) $p['id']] = (array) ($p['apts'] ?? []);
            }
        }
        foreach ($data['projects'] as &$p) {
            if (! is_array($p) || ! isset($p['id']) || ! is_array($p['apts'] ?? null)) {
                continue;
            }
            $pid = (string) $p['id'];
            foreach ($p['apts'] as $aid => &$o) {
                if (! is_array($o)) {
                    continue;
                }
                $was = (array) ($old[$pid][$aid] ?? []);
                $active = in_array($o['status'] ?? 'free', self::ACTIVE, true);
                if ($active) {
                    // сделка продолжается: номер берём из сохранённого, если программа его не прислала
                    if (empty($o['deal']) && ! empty($was['deal']) && in_array($was['status'] ?? '', self::ACTIVE, true)) {
                        $o['deal'] = $was['deal'];
                        $events['changed'] = true;
                    }
                    // тот же статус, но другой покупатель с другим телефоном — это новая сделка: прежние оплаты — в историю
                    if (! empty($o['deal']) && ! empty($was['deal']) && $o['deal'] === $was['deal'] && self::otherBuyer($was, $o)) {
                        self::archive($o, $was);
                        $events['end'][] = [$pid, (string) $aid];
                        $events['changed'] = true;
                    }
                    if (empty($o['deal'])) {
                        $o['deal'] = Str::lower(Str::random(10));
                        $events['bind'][] = [$pid, (string) $aid, $o['deal'], empty($was['deal'])];
                        $events['changed'] = true;
                    }
                } elseif (! empty($o['payments']) || ! empty($o['deal']) || ! empty($o['buyer']) || ! empty($o['phone'])) {
                    // квартира свободна или снята с продажи — сделка закрыта
                    self::archive($o, $o);
                    $events['end'][] = [$pid, (string) $aid];
                    $events['changed'] = true;
                }
            }
            unset($o);
        }
        unset($p);

        return $events;
    }

    /** Другой ли это покупатель: оба телефона указаны и не совпадают */
    private static function otherBuyer(array $was, array $now): bool
    {
        $a = self::digits($was['phone'] ?? '');
        $b = self::digits($now['phone'] ?? '');

        return strlen($a) >= 7 && strlen($b) >= 7 && substr($a, -9) !== substr($b, -9);
    }

    /** Закрытую сделку — в историю квартиры; текущие поля сделки очистить */
    private static function archive(array &$o, array $from): void
    {
        $pays = array_values((array) ($from['payments'] ?? []));
        if ($pays || ! empty($from['buyer']) || ! empty($from['phone'])) {
            $o['history'] = array_values((array) ($o['history'] ?? []));
            $o['history'][] = array_filter([
                'deal' => $from['deal'] ?? null, 'buyer' => $from['buyer'] ?? '', 'phone' => $from['phone'] ?? '', 'plan' => $from['plan'] ?? '',
                'status' => $from['status'] ?? '', 'payments' => $pays, 'paid' => array_sum(array_map(fn ($x) => (float) ($x['amount'] ?? 0), $pays)),
                'closed' => now()->toDateString(),
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
            $o['history'] = array_slice($o['history'], -20);
        }
        $o['payments'] = [];
        unset($o['deal']);
        if (($o['status'] ?? 'free') === 'free' || ($o['status'] ?? '') === 'reserve') {
            $o['buyer'] = '';
            $o['phone'] = '';
            $o['plan'] = '';
        }
    }

    /** Ссылки покупателей после изменения: новая сделка — привязать старые ссылки, закрытая сделка — удалить ссылки */
    public static function applyLinks(int $wsId, array $events): void
    {
        foreach ($events['end'] ?? [] as [$pid, $aid]) {
            BuyerLink::where('workspace_id', $wsId)->where('project_id', $pid)->where('apt_id', $aid)->delete();
        }
        foreach ($events['bind'] ?? [] as $b) {
            [$pid, $aid, $deal, $first] = array_pad($b, 4, false);
            // ссылки, выданные до появления номеров сделок, относятся к текущему покупателю
            if ($first) {
                BuyerLink::where('workspace_id', $wsId)->where('project_id', $pid)->where('apt_id', $aid)->whereNull('deal')->update(['deal' => $deal]);
            }
        }
    }

    /** Номер текущей сделки квартиры (null — квартира свободна) */
    public static function current(array $p, string $aptId): ?string
    {
        $o = (array) ($p['apts'][$aptId] ?? []);

        return in_array($o['status'] ?? 'free', self::ACTIVE, true) && ! empty($o['deal']) ? (string) $o['deal'] : null;
    }

    /** Ссылка на кабинет текущего покупателя квартиры */
    public static function link(Workspace $ws, string $pid, string $aptId): BuyerLink
    {
        $p = StateWriter::project($ws, $pid);
        $deal = $p ? self::current($p, $aptId) : null;
        if (! $deal && $p && in_array(($p['apts'][$aptId]['status'] ?? 'free'), self::ACTIVE, true)) {
            StateWriter::mutate($ws->id, fn () => null);   // присвоить номер сделки (нормализация внутри)
            $deal = self::current(StateWriter::project($ws->fresh(), $pid) ?? [], $aptId);
        }
        if (! $deal) {
            throw new \DomainException(__('Кабинет покупателя — только для проданной или забронированной квартиры'));
        }
        $bl = BuyerLink::where('workspace_id', $ws->id)->where('project_id', $pid)->where('apt_id', $aptId)->first();
        if ($bl && $bl->deal === $deal) {
            return $bl;
        }
        if ($bl) {   // ссылка от прежней сделки — новая ссылка, старая больше не откроется
            $bl->forceFill(['token' => Str::lower(Str::random(24)), 'deal' => $deal, 'tg_chat_id' => null, 'last_reminded' => null])->save();

            return $bl;
        }

        return BuyerLink::create(['workspace_id' => $ws->id, 'project_id' => $pid, 'apt_id' => $aptId, 'deal' => $deal, 'token' => Str::lower(Str::random(24))]);
    }

    /** Нормализовать все компании (однократно при обновлении) */
    public static function normalizeAll(): void
    {
        foreach (Workspace::query()->pluck('id') as $id) {
            try {
                StateWriter::mutate((int) $id, fn () => null);
            } catch (\Throwable) {
                // у компании ещё нет данных CRM
            }
        }
    }
}
