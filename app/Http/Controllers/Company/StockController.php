<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMove;
use App\Models\SupplyLine;
use App\Models\SupplyRequest;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\Warehouse;
use App\Services\Erp\Stock;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Склад, снабжение и тендеры */
class StockController extends ErpController
{
    /* ---------------- Склад ---------------- */

    public function index(Request $request)
    {
        $ws = $this->ws();
        Stock::ensureDefaults($ws);
        // сотрудник объекта видит склады своих объектов и общий склад
        $whs = Warehouse::of($ws->id)->where('is_active', true)->tap(fn ($q) => $this->scopeProjects($q))->orderBy('id')->get();
        $wid = (int) $request->query('wh') ?: null;
        $wid = $wid && $whs->contains('id', $wid) ? $wid : null;
        $q = trim((string) $request->query('q'));
        $items = Item::of($ws->id)->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('name', 'like', "%$q%")->orWhere('sku', 'like', "%$q%")->orWhere('group', 'like', "%$q%")))
            ->orderBy('group')->orderBy('name')->get();

        return view('company.erp.stock', [
            'ws' => $ws, 'whs' => $whs, 'wid' => $wid, 'items' => $items, 'bal' => Stock::balances($ws), 'q' => $q, 'low' => Stock::low($ws),
            'projects' => $this->projects(), 'counterparties' => $this->counterparties(), 'staff' => $this->staff(),
            'moves' => StockMove::of($ws->id)->with(['item', 'warehouse', 'user', 'counterparty'])->whereIn('warehouse_id', $whs->pluck('id'))->when($wid, fn ($x) => $x->where('warehouse_id', $wid))
                ->latest('date')->latest('id')->limit(60)->get(),
            'costs' => Stock::costByProject($ws),
        ]);
    }

    public function item(Item $item)
    {
        $this->mine($item);
        $ws = $this->ws();
        $whs = Warehouse::of($ws->id)->orderBy('id')->get();

        return view('company.erp.item', ['ws' => $ws, 'item' => $item, 'whs' => $whs, 'bal' => Stock::balances($ws)[$item->id] ?? [], 'projects' => $this->projects(),
            'batches' => $whs->mapWithKeys(fn ($w) => [$w->id => Stock::batches($ws, $item->id, $w->id)]),
            'moves' => $item->moves()->with(['warehouse', 'user', 'counterparty'])->latest('date')->latest('id')->limit(200)->get(), 'avg' => Stock::avgPrice($ws, $item->id)]);
    }

    public function itemSave(Request $request)
    {
        $this->editor();
        $ws = $this->ws();
        $d = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:160'], 'unit' => ['required', 'string', 'max:16'],
            'sku' => ['nullable', 'string', 'max:40'], 'group' => ['nullable', 'string', 'max:60'], 'min_qty' => ['nullable', 'numeric', 'min:0'], 'price' => ['nullable', 'numeric', 'min:0']]);
        $d['min_qty'] = (float) ($d['min_qty'] ?? 0);
        $d['price'] = (float) ($d['price'] ?? 0);
        if (! empty($d['id'])) {
            Item::of($ws->id)->findOrFail($d['id'])->update($d);
        } else {
            $item = Item::create($d + ['workspace_id' => $ws->id]);
            if ($request->filled('qty') && $request->filled('warehouse_id')) {
                Stock::receive($ws, $request->user(), ['item_id' => $item->id, 'warehouse_id' => (int) $request->input('warehouse_id'), 'qty' => (float) $request->input('qty'), 'price' => $d['price'], 'note' => 'Начальный остаток']);
            }
        }

        return back()->with('ok', __('Сохранено'));
    }

    public function warehouseSave(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $ws = $this->ws();
        $d = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'project_id' => ['nullable', 'string', 'max:40'], 'keeper_id' => ['nullable', 'integer']]);
        $d['is_active'] = ! $request->has('archive');
        ! empty($d['id']) ? Warehouse::of($ws->id)->findOrFail($d['id'])->update($d) : Warehouse::create($d + ['workspace_id' => $ws->id]);

        return back()->with('ok', __('Сохранено'));
    }

    /** Приход / выдача / перемещение / инвентаризация */
    public function move(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate([
            'op' => ['required', Rule::in(['in', 'out', 'transfer', 'adjust'])],
            'item_id' => ['required', Rule::exists('items', 'id')->where('workspace_id', $ws->id)],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('workspace_id', $ws->id)],
            'to_warehouse_id' => ['nullable', 'required_if:op,transfer', 'different:warehouse_id', Rule::exists('warehouses', 'id')->where('workspace_id', $ws->id)],
            'qty' => ['required', 'numeric', 'min:0'], 'price' => ['nullable', 'numeric', 'min:0'], 'batch' => ['nullable', 'string', 'max:40'],
            'project_id' => ['nullable', 'string', 'max:40'], 'counterparty_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:300'], 'date' => ['nullable', 'date'],
        ]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        try {
            match ($d['op']) {
                'in' => Stock::receive($ws, $u, $d),
                'out' => Stock::issue($ws, $u, $d),
                'transfer' => Stock::transfer($ws, $u, $d),
                'adjust' => Stock::adjust($ws, $u, (int) $d['item_id'], (int) $d['warehouse_id'], (float) $d['qty'], $d['note'] ?? null),
            };
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['qty' => $e->getMessage()]);
        }

        // материал привезли — сразу заявка на оплату поставщику (бухгалтер и руководитель согласуют, кассир оплатит)
        if ($d['op'] === 'in' && $request->boolean('make_pr')) {
            $pr = $this->payFor($ws, $u, [[(int) $d['item_id'], (float) $d['qty'], (float) ($d['price'] ?? 0)]], $d['counterparty_id'] ?? null, (int) $d['warehouse_id'], $d['note'] ?? null);
            if ($pr) {
                return back()->with('ok', __('Приход проведён. Создана заявка на оплату №:n — ушла на согласование.', ['n' => $pr->number]));
            }
        }

        return back()->with('ok', __('Проведено'));
    }

    /** Заявка на оплату за привезённый материал: сумма = количество × цена */
    private function payFor(\App\Models\Workspace $ws, \App\Models\User $u, array $lines, ?int $cpId, int $warehouseId, ?string $note): ?\App\Models\PaymentRequest
    {
        $sum = 0;
        $names = [];
        foreach ($lines as [$itemId, $qty, $price]) {
            $it = Item::of($ws->id)->find($itemId);
            if ($it && $qty > 0 && $price > 0) {
                $sum += $qty * $price;
                $names[] = $it->name.' '.Stock::n($qty).' '.$it->unit;
            }
        }
        if ($sum <= 0) {
            return null;
        }
        $wh = Warehouse::find($warehouseId);

        return \App\Services\Erp\Payments::create($ws, $u, ['amount' => round($sum, 2), 'currency' => \App\Services\Erp\Money::base($ws),
            'purpose' => mb_substr('Оплата за материал: '.implode(', ', $names).($note ? ' · '.$note : ''), 0, 300), 'project_id' => $wh?->project_id,
            'counterparty_id' => $cpId ?: null, 'category_id' => \App\Models\FinCategory::of($ws->id)->where('name', 'Материалы')->value('id'),
            'due_date' => now()->addDays(3)->toDateString()]);
    }

    /** Этикетки с QR-кодом для товаров (печать) */
    public function labels(Request $request)
    {
        $ws = $this->ws();
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $items = Item::of($ws->id)->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get();

        return view('company.erp.labels', ['ws' => $ws, 'items' => $items, 'copies' => max(1, min(50, (int) $request->query('n', 1)))]);
    }

    /* ---------------- Снабжение ---------------- */

    public function supply(Request $request)
    {
        $ws = $this->ws();
        $st = $request->query('status');
        $list = SupplyRequest::of($ws->id)->with(['lines.item', 'user', 'counterparty', 'warehouse'])->when($st, fn ($q, $v) => $q->where('status', $v))
            ->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->paginate(30)->withQueryString();

        return view('company.erp.supply', ['ws' => $ws, 'list' => $list, 'st' => $st, 'projects' => $this->projects(), 'bal' => Stock::balances($ws),
            'items' => Item::of($ws->id)->orderBy('name')->get(), 'whs' => Warehouse::of($ws->id)->where('is_active', true)->get(), 'counterparties' => $this->counterparties(),
            'counts' => SupplyRequest::of($ws->id)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')]);
    }

    public function supplyStore(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate(['project_id' => ['nullable', 'string', 'max:40'], 'need_by' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item' => ['nullable', 'string', 'max:160'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.unit' => ['nullable', 'string', 'max:16']]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $lines = array_filter($d['lines'], fn ($l) => trim((string) ($l['item'] ?? '')) !== '' && (float) ($l['qty'] ?? 0) > 0);
        if (! $lines) {
            return back()->withInput()->withErrors(['lines' => __('Добавьте хотя бы одну позицию')]);
        }
        $sr = DB::transaction(function () use ($ws, $u, $d, $lines) {
            $sr = SupplyRequest::create(['workspace_id' => $ws->id, 'number' => (int) SupplyRequest::of($ws->id)->lockForUpdate()->max('number') + 1, 'project_id' => $d['project_id'],
                'user_id' => $u->id, 'need_by' => $d['need_by'] ?? null, 'note' => $d['note'] ?? null, 'status' => $u->isAdmin() ? 'approved' : 'new']);
            foreach ($lines as $l) {
                $name = trim($l['item']);
                $item = Item::of($ws->id)->where('name', $name)->first() ?? Item::create(['workspace_id' => $ws->id, 'name' => $name, 'unit' => $l['unit'] ?: 'шт']);
                SupplyLine::create(['supply_request_id' => $sr->id, 'item_id' => $item->id, 'qty' => (float) $l['qty'], 'price' => (float) $item->price]);
            }

            return $sr;
        });
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'project' => $this->projects()[$sr->project_id] ?? null, 'action' => 'supply.add',
            'summary' => 'Заявка на снабжение №'.$sr->number.' · позиций: '.count($lines)]);
        if ($sr->status === 'new' && Telegram::enabled()) {
            foreach ($ws->users()->whereIn('role', ['admin', 'accountant'])->whereNotNull('telegram_chat_id')->get() as $a) {
                try {
                    Telegram::message($a->telegram_chat_id, '📦 <b>'.__('Заявка на снабжение').' №'.$sr->number.'</b>'."\n".e($this->projects()[$sr->project_id] ?? '')."\n👤 ".e($u->name),
                        [[['text' => __('Открыть'), 'url' => url('/company/supply')]]]);
                } catch (\Throwable) {
                }
            }
        }

        return back()->with('ok', __('Заявка №:n создана', ['n' => $sr->number]));
    }

    /** Согласовать, отклонить, заказать у поставщика (с ценами), принять (частично) */
    public function supplyAction(Request $request, SupplyRequest $sr)
    {
        $this->mine($sr);
        $u = $this->editor();
        $ws = $this->ws();
        $a = $request->input('action');
        $boss = $u->isAdmin() || $u->role === 'accountant';
        if (in_array($a, ['approve', 'reject'], true)) {
            abort_unless($boss && $sr->status === 'new', 403);
            $sr->update(['status' => $a === 'approve' ? 'approved' : 'rejected']);
        } elseif ($a === 'order') {
            abort_unless($boss && in_array($sr->status, ['approved', 'ordered'], true), 403);
            $d = $request->validate(['counterparty_id' => ['required', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)], 'price' => ['array'], 'price.*' => ['nullable', 'numeric', 'min:0']]);
            foreach ($sr->lines as $l) {
                $l->update(['price' => (float) ($d['price'][$l->id] ?? $l->price)]);
            }
            $sr->update(['status' => 'ordered', 'counterparty_id' => $d['counterparty_id']]);
        } elseif ($a === 'receive') {
            abort_unless(in_array($sr->status, ['approved', 'ordered', 'partial'], true), 403);
            $d = $request->validate(['warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('workspace_id', $ws->id)], 'qty' => ['array'], 'qty.*' => ['nullable', 'numeric', 'min:0']]);
            Stock::receiveSupply($ws, $u, $sr, $d['qty'] ?? [], (int) $d['warehouse_id']);
            if ($request->boolean('make_pr')) {
                $lines = $sr->lines->map(fn ($l) => [$l->item_id, (float) ($d['qty'][$l->id] ?? 0), (float) $l->price])->all();
                if ($pr = $this->payFor($ws, $u, $lines, $sr->counterparty_id, (int) $d['warehouse_id'], 'заявка на снабжение №'.$sr->number)) {
                    return back()->with('ok', __('Приход проведён. Создана заявка на оплату №:n — ушла на согласование.', ['n' => $pr->number]));
                }
            }
        } else {
            abort(422);
        }
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'supply.edit', 'summary' => 'Заявка на снабжение №'.$sr->number.': '.$sr->fresh()->status]);

        return back()->with('ok', __('Сохранено'));
    }

    /* ---------------- Тендеры ---------------- */

    public function tenders(Request $request)
    {
        $ws = $this->ws();

        return view('company.erp.tenders', ['ws' => $ws, 'projects' => $this->projects(),
            'list' => Tender::of($ws->id)->with(['bids.counterparty', 'user'])->withCount('bids')->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->paginate(30),
            'supply' => SupplyRequest::of($ws->id)->whereIn('status', ['approved', 'new'])->latest('id')->get()]);
    }

    public function tender(Tender $t)
    {
        $this->mine($t);

        return view('company.erp.tender', ['ws' => $this->ws(), 't' => $t->load(['bids.counterparty', 'user', 'approver']), 'projects' => $this->projects(),
            'counterparties' => $this->counterparties(), 'sr' => $t->supply_request_id ? SupplyRequest::with('lines.item')->find($t->supply_request_id) : null]
            + $this->thread('tender', $t->id));
    }

    public function tenderStore(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate(['title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'], 'project_id' => ['nullable', 'string', 'max:40'],
            'deadline' => ['nullable', 'date'], 'supply_request_id' => ['nullable', Rule::exists('supply_requests', 'id')->where('workspace_id', $ws->id)]]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $t = Tender::create($d + ['workspace_id' => $ws->id, 'user_id' => $u->id]);
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'tender.add', 'summary' => 'Тендер: '.$t->title]);

        return redirect('/company/tenders/'.$t->id)->with('ok', __('Тендер создан'));
    }

    public function bid(Request $request, Tender $t)
    {
        $this->mine($t);
        $this->editor();
        abort_unless(in_array($t->status, ['open', 'review'], true), 422);
        $d = $request->validate(['counterparty_id' => ['required', Rule::exists('counterparties', 'id')->where('workspace_id', $t->workspace_id)], 'amount' => ['required', 'numeric', 'min:0'],
            'days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'note' => ['nullable', 'string', 'max:300']]);
        TenderBid::updateOrCreate(['tender_id' => $t->id, 'counterparty_id' => $d['counterparty_id']], $d);

        return back()->with('ok', __('Предложение добавлено'));
    }

    /** Выбор победителя: сотрудник предлагает, руководитель утверждает */
    public function award(Request $request, Tender $t)
    {
        $this->mine($t);
        $u = $this->editor();
        $bid = TenderBid::where('tender_id', $t->id)->findOrFail((int) $request->input('bid_id'));
        if ($request->input('action') === 'cancel') {
            abort_unless($u->isAdmin(), 403);
            $t->update(['status' => 'cancelled']);

            return back()->with('ok', __('Тендер отменён'));
        }
        if ($u->isAdmin()) {
            $t->update(['status' => 'awarded', 'winner_bid_id' => $bid->id, 'approved_by' => $u->id]);
            if ($t->supply_request_id && ($sr = SupplyRequest::find($t->supply_request_id)) && in_array($sr->status, ['new', 'approved'], true)) {
                $sr->update(['status' => 'ordered', 'counterparty_id' => $bid->counterparty_id]);
            }
            AuditLog::create(['workspace_id' => $t->workspace_id, 'user_id' => $u->id, 'action' => 'tender.edit', 'summary' => 'Тендер «'.$t->title.'»: победитель '.$bid->counterparty->name]);

            return back()->with('ok', __('Победитель утверждён'));
        }
        $t->update(['status' => 'review', 'winner_bid_id' => $bid->id]);
        if (Telegram::enabled()) {
            foreach ($this->ws()->users()->where('role', 'admin')->whereNotNull('telegram_chat_id')->get() as $a) {
                try {
                    Telegram::message($a->telegram_chat_id, '🏆 <b>'.__('Тендер ждёт утверждения').'</b>'."\n".e($t->title)."\n".__('Предложен победитель').': '.e($bid->counterparty->name),
                        [[['text' => __('Открыть'), 'url' => url('/company/tenders/'.$t->id)]]]);
                } catch (\Throwable) {
                }
            }
        }

        return back()->with('ok', __('Отправлено руководителю на утверждение'));
    }
}
