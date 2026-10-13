<?php

namespace App\Services\Erp;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMove;
use App\Models\SupplyRequest;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Services\StateWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Склад: остатки по складам и партиям, приход, выдача на объект, перемещение, инвентаризация */
class Stock
{
    public static function ensureDefaults(Workspace $ws): void
    {
        if (! Warehouse::of($ws->id)->exists()) {
            Warehouse::create(['workspace_id' => $ws->id, 'name' => 'Центральный склад']);
        }
    }

    /** Остатки: [item_id => [warehouse_id => qty]] */
    public static function balances(Workspace $ws, ?int $warehouseId = null): array
    {
        $rows = StockMove::of($ws->id)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('item_id, warehouse_id, sum(qty) q')->groupBy('item_id', 'warehouse_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->item_id][$r->warehouse_id] = round((float) $r->q, 3);
        }

        return $out;
    }

    public static function qty(Workspace $ws, int $itemId, int $warehouseId, ?string $batch = null): float
    {
        return round((float) StockMove::of($ws->id)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->when($batch !== null, fn ($q) => $q->where('batch', $batch))->sum('qty'), 3);
    }

    /** Партии товара на складе с остатком */
    public static function batches(Workspace $ws, int $itemId, int $warehouseId): array
    {
        return StockMove::of($ws->id)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->whereNotNull('batch')
            ->selectRaw('batch, sum(qty) q, max(price) price, min(date) d')->groupBy('batch')->havingRaw('sum(qty) > 0')->orderBy('d')->get()->toArray();
    }

    /** Средняя цена закупки (для стоимости выдачи на объект) */
    public static function avgPrice(Workspace $ws, int $itemId): float
    {
        $r = StockMove::of($ws->id)->where('item_id', $itemId)->where('type', 'in')->where('qty', '>', 0)->selectRaw('sum(qty*price) s, sum(qty) q')->first();

        return $r && $r->q > 0 ? round($r->s / $r->q, 2) : (float) Item::of($ws->id)->find($itemId)?->price;
    }

    /** Склад и поставщик — только своей компании (защита на уровне сервиса, а не только формы) */
    private static function own(Workspace $ws, array $d): void
    {
        foreach (['warehouse_id', 'to_warehouse_id'] as $k) {
            if (! empty($d[$k]) && ! Warehouse::of($ws->id)->whereKey($d[$k])->exists()) {
                throw new \DomainException(__('Склад не найден'));
            }
        }
        if (! empty($d['counterparty_id']) && ! \App\Models\Counterparty::of($ws->id)->whereKey($d['counterparty_id'])->exists()) {
            throw new \DomainException(__('Контрагент не найден'));
        }
    }

    /** Блокировка товара до конца транзакции: две выдачи одновременно не уведут остаток в минус */
    private static function lockItem(Workspace $ws, int $itemId): Item
    {
        return Item::of($ws->id)->whereKey($itemId)->lockForUpdate()->firstOrFail();
    }

    public static function receive(Workspace $ws, User $by, array $d): StockMove
    {
        self::own($ws, $d);
        if ((float) $d['qty'] <= 0) {
            throw new \DomainException(__('Количество должно быть больше нуля'));
        }
        $item = Item::of($ws->id)->findOrFail($d['item_id']);
        $m = StockMove::create(['workspace_id' => $ws->id, 'item_id' => $item->id, 'warehouse_id' => $d['warehouse_id'], 'type' => 'in', 'qty' => abs((float) $d['qty']),
            'price' => (float) ($d['price'] ?? 0), 'batch' => $d['batch'] ?? ('P'.now()->format('ymd').'-'.Str::upper(Str::random(3))), 'counterparty_id' => $d['counterparty_id'] ?? null,
            'supply_request_id' => $d['supply_request_id'] ?? null, 'note' => $d['note'] ?? null, 'date' => $d['date'] ?? now()->toDateString(), 'user_id' => $by->id]);
        if ($m->price > 0) {
            $item->forceFill(['price' => $m->price])->save();
        }
        self::log($ws, $by, 'add', 'Приход: '.$item->name.' +'.self::n($m->qty).' '.$item->unit.' → '.$m->warehouse->name);

        return $m;
    }

    /** Выдача на объект; не больше остатка */
    public static function issue(Workspace $ws, User $by, array $d): StockMove
    {
        self::own($ws, $d);
        $qty = abs((float) $d['qty']);
        if ($qty <= 0) {
            throw new \DomainException(__('Количество должно быть больше нуля'));
        }
        [$item, $m] = DB::transaction(function () use ($ws, $by, $d, $qty) {
            $item = self::lockItem($ws, (int) $d['item_id']);
            $left = self::qty($ws, $item->id, (int) $d['warehouse_id'], $d['batch'] ?? null);
            if ($qty > $left + 0.0005) {
                throw new \DomainException(__('На складе только :q :u', ['q' => self::n($left), 'u' => $item->unit]));
            }

            return [$item, StockMove::create(['workspace_id' => $ws->id, 'item_id' => $item->id, 'warehouse_id' => $d['warehouse_id'], 'type' => 'out', 'qty' => -$qty,
                'price' => self::avgPrice($ws, $item->id), 'batch' => $d['batch'] ?? null, 'project_id' => $d['project_id'] ?? null, 'note' => $d['note'] ?? null,
                'date' => $d['date'] ?? now()->toDateString(), 'user_id' => $by->id])];
        });
        self::log($ws, $by, 'del', 'Выдача: '.$item->name.' −'.self::n($qty).' '.$item->unit.($m->project_id ? ' → '.(StateWriter::projects($ws)[$m->project_id] ?? '') : '').($m->note ? ' · '.$m->note : ''), $m->project_id);

        return $m;
    }

    public static function transfer(Workspace $ws, User $by, array $d): string
    {
        self::own($ws, $d);
        $item = Item::of($ws->id)->findOrFail($d['item_id']);
        $qty = abs((float) $d['qty']);
        if ($qty <= 0) {
            throw new \DomainException(__('Количество должно быть больше нуля'));
        }
        $to = Warehouse::of($ws->id)->findOrFail($d['to_warehouse_id']);
        if ((int) $to->id === (int) $d['warehouse_id']) {
            throw new \DomainException(__('Выберите другой склад'));
        }
        $key = Str::lower(Str::random(10));
        DB::transaction(function () use ($ws, $by, $d, $item, $qty, $to, $key) {
            self::lockItem($ws, $item->id);
            $left = self::qty($ws, $item->id, (int) $d['warehouse_id'], $d['batch'] ?? null);
            if ($qty > $left + 0.0005) {
                throw new \DomainException(__('На складе только :q :u', ['q' => self::n($left), 'u' => $item->unit]));
            }
            $base = ['workspace_id' => $ws->id, 'item_id' => $item->id, 'type' => 'transfer', 'price' => self::avgPrice($ws, $item->id), 'batch' => $d['batch'] ?? null,
                'transfer_key' => $key, 'note' => $d['note'] ?? null, 'date' => $d['date'] ?? now()->toDateString(), 'user_id' => $by->id];
            StockMove::create($base + ['warehouse_id' => $d['warehouse_id'], 'qty' => -$qty]);
            StockMove::create($base + ['warehouse_id' => $to->id, 'qty' => $qty]);
        });
        self::log($ws, $by, 'edit', 'Перемещение: '.$item->name.' '.self::n($qty).' '.$item->unit.' · '.Warehouse::find($d['warehouse_id'])->name.' → '.$to->name);

        return $key;
    }

    /** Инвентаризация: привести остаток к фактическому */
    public static function adjust(Workspace $ws, User $by, int $itemId, int $warehouseId, float $fact, ?string $note): void
    {
        self::own($ws, ['warehouse_id' => $warehouseId]);
        if ($fact < 0) {
            throw new \DomainException(__('Фактический остаток не может быть меньше нуля'));
        }
        $item = Item::of($ws->id)->findOrFail($itemId);
        $diff = DB::transaction(function () use ($ws, $by, $itemId, $warehouseId, $fact, $note) {
            self::lockItem($ws, $itemId);
            $diff = round($fact - self::qty($ws, $itemId, $warehouseId), 3);
            if (abs($diff) >= 0.0005) {
                StockMove::create(['workspace_id' => $ws->id, 'item_id' => $itemId, 'warehouse_id' => $warehouseId, 'type' => 'adjust', 'qty' => $diff,
                    'price' => self::avgPrice($ws, $itemId), 'note' => $note ?: 'Инвентаризация', 'date' => now()->toDateString(), 'user_id' => $by->id]);
            }

            return $diff;
        });
        if (abs($diff) < 0.0005) {
            return;
        }
        self::log($ws, $by, 'edit', 'Инвентаризация: '.$item->name.' '.($diff > 0 ? '+' : '').self::n($diff).' '.$item->unit);
    }

    /** Принять товар по заявке снабжения (можно частями) */
    public static function receiveSupply(Workspace $ws, User $by, SupplyRequest $sr, array $qtys, int $warehouseId): void
    {
        DB::transaction(function () use ($ws, $by, $sr, $qtys, $warehouseId) {
            foreach ($sr->lines as $l) {
                $q = (float) ($qtys[$l->id] ?? 0);
                if ($q <= 0) {
                    continue;
                }
                self::receive($ws, $by, ['item_id' => $l->item_id, 'warehouse_id' => $warehouseId, 'qty' => $q, 'price' => $l->price,
                    'counterparty_id' => $sr->counterparty_id, 'supply_request_id' => $sr->id, 'note' => 'Заявка №'.$sr->number]);
                $l->increment('received', $q);
            }
            $sr->load('lines');
            $done = $sr->lines->every(fn ($l) => $l->received + 0.0005 >= $l->qty);
            $any = $sr->lines->contains(fn ($l) => $l->received > 0);
            $sr->forceFill(['status' => $done ? 'received' : ($any ? 'partial' : $sr->status), 'warehouse_id' => $warehouseId])->save();
        });
    }

    /** Товары с остатком ниже минимального */
    public static function low(Workspace $ws): array
    {
        $bal = self::balances($ws);
        $out = [];
        foreach (Item::of($ws->id)->where('min_qty', '>', 0)->get() as $it) {
            $q = array_sum($bal[$it->id] ?? []);
            if ($q < $it->min_qty) {
                $out[] = ['item' => $it, 'qty' => $q];
            }
        }

        return $out;
    }

    /** Стоимость материалов, выданных на каждый объект */
    public static function costByProject(Workspace $ws): array
    {
        return StockMove::of($ws->id)->where('type', 'out')->whereNotNull('project_id')->selectRaw('project_id, sum(-qty*price) s')->groupBy('project_id')->pluck('s', 'project_id')->all();
    }

    public static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ' '), '0'), '.');
    }

    private static function log(Workspace $ws, User $by, string $kind, string $text, ?string $project = null): void
    {
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'project' => $project ? (StateWriter::projects($ws)[$project] ?? null) : null,
            'action' => 'stock.'.$kind, 'summary' => $text]);
    }
}
