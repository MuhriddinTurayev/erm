<?php

namespace App\Services\Api;

use App\Models\CashDesk;
use App\Models\CashOp;
use App\Models\Counterparty;
use App\Models\Item;
use App\Models\LeadInbox;
use App\Models\PaymentRequest;
use App\Models\StockMove;
use App\Models\Task;
use App\Models\Warehouse;
use App\Services\Apartments;
use App\Services\Inventory;

/**
 * Записи программы в виде для API и вебхуков: понятные английские поля, даты ISO 8601, суммы — числа.
 * Один и тот же вид и в ответах API, и в событиях вебхуков.
 */
class Present
{
    private static function d($v): ?string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->toDateString() : null;
    }

    private static function t($v): ?string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->toIso8601String() : null;
    }

    private static function num($v): float|int
    {
        $f = round((float) $v, 3);

        return floor($f) == $f ? (int) $f : $f;
    }

    /** Объект (дом) с количеством квартир по статусам */
    public static function object(array $p): array
    {
        $apts = Apartments::list($p);
        $by = array_count_values(array_column($apts, 'status')) + ['free' => 0, 'booked' => 0, 'sold' => 0, 'reserve' => 0];
        $w = $s = 0;
        foreach ((array) ($p['stages'] ?? []) as $st) {
            $dur = max(1, (int) ($st['dur'] ?? 1));
            $w += $dur;
            $s += $dur * (float) ($st['progress'] ?? 0);
        }

        return [
            'id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'address' => (string) ($p['address'] ?? ''),
            'stage' => (string) ($p['status'] ?? ''), 'progress' => $w ? (int) round($s / $w) : 0,
            'on_sale' => Inventory::ready($p),
            'apartments' => ['total' => count($apts), 'free' => $by['free'], 'booked' => $by['booked'], 'sold' => $by['sold'], 'reserve' => $by['reserve']],
        ];
    }

    /** Квартира или помещение. Покупатель и оплаты — только с правом sales:read. */
    public static function apartment(array $a, string $objectId, bool $sales): array
    {
        $out = [
            'id' => (string) $a['id'], 'object_id' => $objectId, 'number' => (string) $a['num'], 'kind' => $a['kind'] ?? ($a['rooms'] ? 'flat' : 'commercial'),
            'block' => (string) ($a['block'] ?? ''), 'entrance' => (string) ($a['entr'] ?? ''), 'floor' => (int) $a['floor'],
            'rooms' => (int) $a['rooms'], 'area' => self::num($a['area']), 'layout' => (string) ($a['typeName'] ?? ''),
            'status' => $a['status'], 'price' => self::num($a['price']), 'base_price' => self::num($a['basePrice']),
            'price_per_m2' => $a['area'] > 0 ? (int) round($a['price'] / $a['area']) : null,
        ];
        if ($sales) {
            $out += [
                'buyer' => in_array($a['status'], ['sold', 'booked'], true) ? ['name' => $a['buyer'], 'phone' => $a['phone']] : null,
                'plan' => $a['plan'] ?: null, 'paid' => self::num($a['paid']),
                'debt' => in_array($a['status'], ['sold', 'booked'], true) ? self::num(max(0, $a['price'] - $a['paid'])) : 0,
                'payments' => array_map(fn ($x) => ['id' => (string) ($x['id'] ?? ''), 'date' => $x['date'] ?? null, 'amount' => self::num($x['amount'] ?? 0), 'method' => $x['way'] ?? null], $a['payments']),
            ];
        }

        return $out;
    }

    public static function lead(LeadInbox $l): array
    {
        return [
            'id' => $l->id, 'created_at' => self::t($l->created_at), 'channel' => $l->ch, 'kind' => $l->kind, 'name' => $l->name, 'phone' => $l->phone,
            'username' => $l->user, 'source' => $l->src, 'rooms' => $l->rooms !== null ? (int) $l->rooms : null, 'budget' => $l->budget, 'message' => $l->text,
        ];
    }

    public static function warehouse(Warehouse $w): array
    {
        return ['id' => $w->id, 'name' => __($w->name), 'object_id' => $w->project_id, 'is_active' => (bool) $w->is_active];
    }

    /** Товар с остатками: $bal — [warehouse_id => qty] */
    public static function item(Item $i, array $bal, array $whNames = []): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'sku' => $i->sku, 'group' => $i->group,
            'min_qty' => self::num($i->min_qty), 'price' => self::num($i->price), 'balance' => self::num(array_sum($bal)),
            'low' => $i->min_qty > 0 && array_sum($bal) < $i->min_qty,
            'warehouses' => array_values(array_map(fn ($wid, $q) => ['id' => (int) $wid, 'name' => $whNames[$wid] ?? null, 'qty' => self::num($q)], array_keys($bal), $bal)),
            'updated_at' => self::t($i->updated_at),
        ];
    }

    public static function move(StockMove $m): array
    {
        return [
            'id' => $m->id, 'date' => self::d($m->date), 'type' => $m->type, 'direction' => $m->qty >= 0 ? 'in' : 'out',
            'item' => $m->item ? ['id' => $m->item->id, 'name' => $m->item->name, 'unit' => $m->item->unit] : ['id' => $m->item_id],
            'warehouse' => $m->warehouse ? ['id' => $m->warehouse->id, 'name' => __($m->warehouse->name)] : ['id' => $m->warehouse_id],
            'qty' => self::num($m->qty), 'price' => self::num($m->price), 'batch' => $m->batch, 'object_id' => $m->project_id,
            'counterparty_id' => $m->counterparty_id, 'supply_request_id' => $m->supply_request_id, 'transfer_key' => $m->transfer_key,
            'note' => $m->note, 'created_at' => self::t($m->created_at),
        ];
    }

    public static function desk(CashDesk $d, float $balance): array
    {
        return ['id' => $d->id, 'name' => __($d->name), 'kind' => $d->kind, 'currency' => $d->currency, 'object_id' => $d->project_id, 'balance' => self::num($balance), 'is_active' => (bool) $d->is_active];
    }

    public static function cashOp(CashOp $o): array
    {
        return [
            'id' => $o->id, 'date' => self::d($o->date), 'type' => $o->type, 'desk_id' => $o->cash_desk_id, 'currency' => $o->desk?->currency,
            'amount' => self::num($o->amount), 'rate' => self::num($o->rate), 'amount_base' => self::num($o->amount_base),
            'category' => $o->category ? __($o->category->name) : null, 'object_id' => $o->project_id, 'counterparty_id' => $o->counterparty_id,
            'contract_id' => $o->contract_id, 'payment_request_id' => $o->payment_request_id, 'transfer_key' => $o->transfer_key,
            'employee' => $o->employee, 'note' => $o->note, 'created_at' => self::t($o->created_at),
        ];
    }

    public static function paymentRequest(PaymentRequest $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'amount' => self::num($r->amount), 'currency' => $r->currency,
            'purpose' => $r->purpose, 'due_date' => self::d($r->due_date), 'object_id' => $r->project_id,
            'counterparty' => $r->counterparty ? ['id' => $r->counterparty->id, 'name' => $r->counterparty->name, 'inn' => $r->counterparty->inn] : null,
            'contract_id' => $r->contract_id, 'category' => $r->category ? __($r->category->name) : null, 'cash_op_id' => $r->cash_op_id,
            'created_at' => self::t($r->created_at), 'updated_at' => self::t($r->updated_at),
        ];
    }

    public static function counterparty(Counterparty $c): array
    {
        return [
            'id' => $c->id, 'kind' => $c->kind, 'name' => $c->name, 'inn' => $c->inn, 'phone' => $c->phone, 'contact' => $c->contact,
            'address' => $c->address, 'bank' => $c->bank, 'folder' => $c->folder, 'status' => $c->status, 'note' => $c->note,
            'created_at' => self::t($c->created_at), 'updated_at' => self::t($c->updated_at),
        ];
    }

    public static function task(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title, 'description' => $t->description, 'object_id' => $t->project_id,
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'due_date' => self::d($t->due_date), 'priority' => $t->priority, 'status' => $t->status, 'progress' => (int) $t->progress,
            'done_at' => self::t($t->done_at), 'created_at' => self::t($t->created_at), 'updated_at' => self::t($t->updated_at),
        ];
    }
}
