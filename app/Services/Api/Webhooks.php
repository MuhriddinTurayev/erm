<?php

namespace App\Services\Api;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Services\Apartments;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Вебхуки: сообщаем другой программе о событиях (новая заявка, продажа квартиры, оплата, склад…).
 * Каждая отправка подписана секретом вебхука (HMAC-SHA256), неудачные повторяются с паузой.
 * Отправлять можно только на внешние адреса — не во внутреннюю сеть сервера.
 */
class Webhooks
{
    /** событие => [описание, раздел] */
    public const EVENTS = [
        'lead.created' => ['Новая заявка клиента', 'leads'],
        'apartment.updated' => ['Квартира: бронь, продажа, цена или покупатель', 'sales'],
        'apartment.paid' => ['Принята оплата по квартире', 'sales'],
        'payment.updated' => ['Заявка на оплату: создана, согласована, оплачена, отклонена', 'payments'],
        'cash.created' => ['Операция по кассе', 'cash'],
        'stock.moved' => ['Движение по складу: приход, выдача, перемещение', 'stock'],
        'counterparty.created' => ['Новый контрагент', 'parties'],
        'task.created' => ['Новая задача', 'tasks'],
        'task.updated' => ['Задача изменена: статус, срок, исполнитель', 'tasks'],
    ];

    public const MAX_HOOKS = 10;

    /** Столько неудачных отправок подряд — и вебхук выключается (адрес, видимо, больше не работает) */
    public const MAX_FAILS = 50;

    private const CACHE = 'webhooks:';

    /** Включённые вебхуки компании: [[id, события], …] — из кэша на минуту */
    public static function active(int $wsId): array
    {
        return Cache::remember(self::CACHE.$wsId, 60, fn () => Webhook::of($wsId)->where('is_active', true)->get(['id', 'events'])
            ->map(fn ($h) => [$h->id, (array) $h->events])->all());
    }

    public static function forget(int $wsId): void
    {
        Cache::forget(self::CACHE.$wsId);
    }

    /** Есть ли кто-то, кто ждёт эти события */
    public static function wanted(int $wsId, array $events): bool
    {
        foreach (self::active($wsId) as [, $ev]) {
            if (array_intersect($events, $ev) || in_array('*', $ev, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сообщить о событии всем вебхукам, которые его ждут. Данные — массив или функция (считается, только если вебхуки есть).
     * Отправка — в очереди после сохранения в базе; ошибка здесь никогда не мешает основной работе.
     */
    public static function emit(int $wsId, string $event, array|Closure $data): void
    {
        try {
            $hooks = array_filter(self::active($wsId), fn ($h) => in_array($event, $h[1], true) || in_array('*', $h[1], true));
            if (! $hooks) {
                return;
            }
            $body = self::body($wsId, $event, $data instanceof Closure ? $data() : $data);
            foreach ($hooks as [$id]) {
                $d = WebhookDelivery::create(['webhook_id' => $id, 'uuid' => $body['id'], 'event' => $event, 'payload' => self::json($body)]);
                DeliverWebhook::dispatch($d->id)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook emit '.$event.': '.$e->getMessage());
        }
    }

    private static function body(int $wsId, string $event, array $data): array
    {
        return ['id' => (string) Str::uuid(), 'event' => $event, 'created_at' => now()->toIso8601String(),
            'company' => ['id' => $wsId, 'name' => Workspace::whereKey($wsId)->value('name')], 'data' => $data];
    }

    private static function json(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Проверочное событие — отправляется сразу, ответ виден на странице */
    public static function ping(Webhook $h): WebhookDelivery
    {
        $body = self::body($h->workspace_id, 'ping', ['message' => 'Oftob_Sohtumon: test', 'webhook_id' => $h->id]);
        $d = WebhookDelivery::create(['webhook_id' => $h->id, 'uuid' => $body['id'], 'event' => 'ping', 'payload' => self::json($body)]);
        self::deliver($d, true);

        return $d->refresh();
    }

    /** Подпись: HMAC-SHA256 от «время.тело» секретом вебхука */
    public static function sign(string $secret, int $ts, string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $ts.'.'.$payload, $secret);
    }

    /** Одна попытка отправки. true — получатель ответил 2xx. */
    public static function deliver(WebhookDelivery $d, bool $evenIfOff = false): bool
    {
        $h = $d->webhook;
        if (! $h || (! $h->is_active && ! $evenIfOff)) {
            return true;   // вебхук удалён или выключен — больше не пытаемся
        }
        $ts = time();
        $t0 = microtime(true);
        $d->attempts = min(255, (int) $d->attempts + 1);
        try {
            [$host, $port, $ip] = self::target($h->url);
            $res = Http::withHeaders([
                'User-Agent' => 'Oftob-Webhooks/1.0',
                'X-Oftob-Event' => $d->event,
                'X-Oftob-Event-Id' => $d->uuid,
                'X-Oftob-Timestamp' => (string) $ts,
                'X-Oftob-Signature' => self::sign((string) $h->secret, $ts, $d->payload),
            ])->withOptions([
                'allow_redirects' => false,
                // соединяемся именно с проверенным адресом (DNS не подменит его между проверкой и отправкой)
                'curl' => defined('CURLOPT_RESOLVE') ? [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? "[$ip]" : $ip)]] : [],
            ])->connectTimeout(5)->timeout(10)->withBody($d->payload, 'application/json')->post($h->url);
            $ok = $res->successful();
            $d->fill(['status' => $res->status(), 'ok' => $ok, 'error' => $ok ? null : mb_substr('HTTP '.$res->status().' '.trim(strip_tags((string) $res->body())), 0, 300)]);
        } catch (\Throwable $e) {
            $ok = false;
            $d->fill(['status' => null, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $d->ms = (int) round((microtime(true) - $t0) * 1000);
        $d->save();
        $h->forceFill(['last_status' => $d->status, 'last_at' => now(), 'last_error' => $ok ? null : $d->error, 'fails' => $ok ? 0 : $h->fails + 1]);
        if (! $ok && $h->fails >= self::MAX_FAILS && $h->is_active) {
            $h->is_active = false;
        }
        $h->save();
        self::forget($h->workspace_id);

        return $ok;
    }

    /**
     * Куда отправлять: [хост, порт, IP]. Только http(s) и только адреса в интернете —
     * localhost, внутренние сети и служебные адреса запрещены (иначе через вебхук можно достучаться до сервера изнутри).
     */
    public static function target(string $url): array
    {
        $u = parse_url(trim($url));
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        $host = strtolower(trim((string) ($u['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['https', 'http'], true) || $host === '' || isset($u['user']) || isset($u['pass'])) {
            throw new \InvalidArgumentException(__('Адрес должен начинаться с https://'));
        }
        if ($scheme === 'http' && ! app()->environment('local', 'testing')) {
            throw new \InvalidArgumentException(__('Адрес должен начинаться с https://'));
        }
        $port = (int) ($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new \InvalidArgumentException(__('Не удалось найти адрес :h', ['h' => $host]));
        }
        $flags = defined('FILTER_FLAG_GLOBAL_RANGE') ? FILTER_FLAG_GLOBAL_RANGE : (FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
                throw new \InvalidArgumentException(__('Внутренние адреса (localhost, локальная сеть) запрещены'));
            }
        }

        return [$host, $port, $ips[0]];
    }

    /**
     * Данные CRM изменились (программа в браузере, бот, страница продаж): квартиры с новым статусом, ценой или покупателем
     * и новые оплаты по квартирам — в вебхуки.
     */
    public static function stateChanged(int $wsId, ?array $before, ?array $after): void
    {
        try {
            if (! $after || ! self::wanted($wsId, ['apartment.updated', 'apartment.paid'])) {
                return;
            }
            $old = [];
            foreach ((array) ($before['projects'] ?? []) as $p) {
                if (is_array($p) && isset($p['id'])) {
                    $old[(string) $p['id']] = (array) ($p['apts'] ?? []);
                }
            }
            foreach ((array) ($after['projects'] ?? []) as $p) {
                if (! is_array($p) || ! isset($p['id']) || empty($p['apts']) || ! is_array($p['apts'])) {
                    continue;
                }
                $pid = (string) $p['id'];
                $list = null;
                foreach ($p['apts'] as $aid => $o) {
                    $o = (array) $o;
                    $was = (array) ($old[$pid][$aid] ?? []);
                    $key = fn (array $x) => [$x['status'] ?? 'free', (float) ($x['price'] ?? 0), (string) ($x['buyer'] ?? ''), (string) ($x['phone'] ?? ''), (string) ($x['plan'] ?? '')];
                    $paidNow = array_column(array_values((array) ($o['payments'] ?? [])), null, 'id');
                    $paidWas = array_column(array_values((array) ($was['payments'] ?? [])), null, 'id');
                    $newPays = array_diff_key($paidNow, $paidWas);
                    if ($key($o) === $key($was) && ! $newPays) {
                        continue;
                    }
                    $list ??= array_column(Apartments::list($p), null, 'id');
                    if (! isset($list[$aid])) {
                        continue;
                    }
                    $a = Present::apartment($list[$aid], $pid, true);
                    if ($key($o) !== $key($was)) {
                        self::emit($wsId, 'apartment.updated', $a + ['previous_status' => $was['status'] ?? 'free']);
                    }
                    foreach ($newPays as $x) {
                        self::emit($wsId, 'apartment.paid', ['payment' => ['id' => (string) ($x['id'] ?? ''), 'date' => $x['date'] ?? null, 'amount' => (float) ($x['amount'] ?? 0), 'method' => $x['way'] ?? null], 'apartment' => $a]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook state: '.$e->getMessage());
        }
    }
}
