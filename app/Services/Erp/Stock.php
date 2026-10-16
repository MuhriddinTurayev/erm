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

    /**
     * Что лежит на складе по партиям, в порядке прихода (FIFO): [['batch', 'q', 'price', 'd'], …].
     * Выдачи и перемещения без партии (так было раньше) списаны с самых старых партий; остаток без партии — последним (batch null).
     */
    public static function fifo(Workspace $ws, int $itemId, int $warehouseId): array
    {
        $rows = StockMove::of($ws->id)->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->selectRaw('batch, sum(qty) q, max(price) price, min(date) d, min(id) i')->groupBy('batch')->get();
        $loose = 0.0;
        $list = [];
        foreach ($rows as $r) {
            if ($r->batch === null || $r->batch === '') {
                $loose += (float) $r->q;
            } else {
                $list[] = ['batch' => (string) $r->batch, 'q' => (float) $r->q, 'price' => (float) $r->price, 'd' => (string) $r->d, 'i' => (int) $r->i];
            }
        }
        usort($list, fn ($a, $b) => [$a['d'], $a['i']] <=> [$b['d'], $b['i']]);
        if ($loose < 0) {
            $need = -$loose;
            foreach ($list as &$b) {
                $take = min(max(0, $b['q']), $need);
                $b['q'] -= $take;
                $need -= $take;
            }
            unset($b);
            $loose = -$need;
        }
        $out = array_values(array_filter(array_map(fn ($b) => ['batch' => $b['batch'], 'q' => round($b['q'], 3), 'price' => $b['price'], 'd' => $b['d']], $list), fn ($b) => $b['q'] > 0.0005));
        if ($loose > 0.0005) {
            $out[] = ['batch' => null, 'q' => round($loose, 3), 'price' => 0.0, 'd' => null];
        }

        return $out;
    }

    /** Партии товара на складе с остатком (по FIFO) */
    public static function batches(Workspace $ws, int $itemId, int $warehouseId): array
    {
        return array_values(array_filter(self::fifo($ws, $itemId, $warehouseId), fn ($b) => $b['batch'] !== null));
    }

    /**
     * Подписи для таблицы движений: партия (у старых строк без партии — по FIFO) и откуда/куда у перемещений.
     * ['batch' => [id => 'P…'], 'route' => [id => '→ Склад' | '← Склад']]
     */
    public static function moveLabels(Workspace $ws, $moves, ?array $balances = null): array
    {
        $moves = collect($moves);
        $keys = $moves->pluck('transfer_key')->filter()->unique()->values();
        $pairs = $keys->count() ? StockMove::of($ws->id)->whereIn('transfer_key', $keys)->with('warehouse')->get()->groupBy('transfer_key') : collect();
        $batch = self::batchesOf($ws, $moves->concat($pairs->flatten(1))->unique('id'));
        $route = [];
        foreach ($moves as $m) {
            if (! $m->transfer_key || ! isset($pairs[$m->transfer_key])) {
                continue;
            }
            $other = $pairs[$m->transfer_key]->first(fn ($x) => ($x->qty > 0) !== ($m->qty > 0) && (! $m->batch || $x->batch === $m->batch));
            if ($other) {
                $route[$m->id] = ($m->qty < 0 ? '→ ' : '← ').__($other->warehouse?->name ?? '');
                if (! $m->batch && $m->qty > 0 && isset($batch[$other->id])) {
                    $batch[$m->id] = $batch[$other->id];   // пришло перемещением — те же партии, что ушли со склада-отправителя
                }
            }
        }

        return ['batch' => $batch, 'route' => $route, 'after' => self::afterEach($ws, $moves, $balances)];
    }

    /**
     * Сколько осталось на складе после каждой операции: [move_id => остаток].
     * Список — последние операции (от новых к старым), поэтому идём от нынешнего остатка назад.
     */
    private static function afterEach(Workspace $ws, $moves, ?array $balances = null): array
    {
        if ($moves->isEmpty()) {
            return [];
        }
        if ($balances !== null) {   // остатки уже посчитаны на странице — второй раз не считаем
            $cur = [];
            foreach ($balances as $item => $byWh) {
                foreach ($byWh as $wh => $q) {
                    $cur[$item.':'.$wh] = (float) $q;
                }
            }
        } else {
            $cur = null;
        }
        $cur ??= StockMove::of($ws->id)->whereIn('item_id', $moves->pluck('item_id')->unique()->values())->whereIn('warehouse_id', $moves->pluck('warehouse_id')->unique()->values())
            ->selectRaw('item_id, warehouse_id, sum(qty) q')->groupBy('item_id', 'warehouse_id')->get()
            ->mapWithKeys(fn ($r) => [$r->item_id.':'.$r->warehouse_id => (float) $r->q])->all();
        $list = $moves->all();
        usort($list, fn ($a, $b) => [$b->date->format('Y-m-d'), $b->id] <=> [$a->date->format('Y-m-d'), $a->id]);
        $out = [];
        foreach ($list as $m) {
            $k = $m->item_id.':'.$m->warehouse_id;
            $out[$m->id] = round($cur[$k] ?? 0, 3);
            $cur[$k] = ($cur[$k] ?? 0) - (float) $m->qty;
        }

        return $out;
    }

    /** Сколько взять из каких партий: самые старые — первыми; [[партия|null, количество], …] */
    private static function allocate(Workspace $ws, int $itemId, int $warehouseId, float $qty): array
    {
        $parts = [];
        foreach (self::fifo($ws, $itemId, $warehouseId) as $b) {
            if ($qty <= 0.0005) {
                break;
            }
            $take = min($b['q'], $qty);
            $parts[] = [$b['batch'], round($take, 3)];
            $qty -= $take;
        }
        if ($qty > 0.0005) {   // остаток по общему счёту есть, а по партиям не сошлось — без партии
            $parts[] = [null, round($qty, 3)];
        }

        return $parts;
    }

    /**
     * Для показа: из каких партий ушли старые выдачи и перемещения без партии (тот же FIFO, по хронологии склада).
     * [id движения => 'P…, P…']
     */
    public static function batchesOf(Workspace $ws, $moves): array
    {
        $need = collect($moves)->filter(fn ($m) => ! $m->batch && $m->qty < 0);
        $out = [];
        foreach ($need->groupBy(fn ($m) => $m->item_id.'|'.$m->warehouse_id) as $key => $list) {
            [$itemId, $whId] = array_map('intval', explode('|', $key));
            $want = $list->pluck('id')->flip();
            $queue = [];   // партия => остаток, в порядке прихода
            foreach (StockMove::of($ws->id)->where('item_id', $itemId)->where('warehouse_id', $whId)->orderBy('date')->orderBy('id')->get(['id', 'qty', 'batch']) as $m) {
                $q = (float) $m->qty;
                if ($m->batch) {
                    $queue[$m->batch] = ($queue[$m->batch] ?? 0) + $q;
                    continue;
                }
                if ($q >= 0) {
                    $queue[''] = ($queue[''] ?? 0) + $q;
                    continue;
                }
                $left = -$q;
                $used = [];
                foreach ($queue as $b => $have) {
                    if ($left <= 0.0005) {
                        break;
                    }
                    if ($have <= 0.0005) {
                        continue;
                    }
                    $take = min($have, $left);
                    $queue[$b] -= $take;
                    $left -= $take;
                    $used[] = $b === '' ? __('без партии (старый остаток)') : $b;   // пришло без партии: начальный остаток, инвентаризация
                }
                if (isset($want[$m->id]) && $used) {
                    $out[$m->id] = implode(', ', array_unique($used));
                }
            }
        }

        return $out;
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

            $parts = ! empty($d['batch']) ? [[$d['batch'], $qty]] : self::allocate($ws, $item->id, (int) $d['warehouse_id'], $qty);
            $first = null;
            foreach ($parts as [$batch, $q]) {
                $mv = StockMove::create(['workspace_id' => $ws->id, 'item_id' => $item->id, 'warehouse_id' => $d['warehouse_id'], 'type' => 'out', 'qty' => -$q,
                    'price' => self::avgPrice($ws, $item->id), 'batch' => $batch, 'project_id' => $d['project_id'] ?? null, 'note' => $d['note'] ?? null,
                    'date' => $d['date'] ?? now()->toDateString(), 'user_id' => $by->id]);
                $first ??= $mv;
            }

            return [$item, $first];
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
            $parts = ! empty($d['batch']) ? [[$d['batch'], $qty]] : self::allocate($ws, $item->id, (int) $d['warehouse_id'], $qty);
            foreach ($parts as [$batch, $q]) {
                $base = ['workspace_id' => $ws->id, 'item_id' => $item->id, 'type' => 'transfer', 'price' => self::avgPrice($ws, $item->id), 'batch' => $batch,
                    'transfer_key' => $key, 'note' => $d['note'] ?? null, 'date' => $d['date'] ?? now()->toDateString(), 'user_id' => $by->id];
                StockMove::create($base + ['warehouse_id' => $d['warehouse_id'], 'qty' => -$q]);
                StockMove::create($base + ['warehouse_id' => $to->id, 'qty' => $q]);
            }
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
