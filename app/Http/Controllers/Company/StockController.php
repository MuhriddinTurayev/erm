<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockMove;
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
        // начальный остаток — только на свой склад (своей компании и своего объекта)
        $start = $request->validate(['qty' => ['nullable', 'numeric', 'min:0'], 'warehouse_id' => ['nullable', Rule::exists('warehouses', 'id')->where('workspace_id', $ws->id)]]);
        $d['min_qty'] = (float) ($d['min_qty'] ?? 0);
        $d['price'] = (float) ($d['price'] ?? 0);
        if (! empty($d['id'])) {
            Item::of($ws->id)->findOrFail($d['id'])->update($d);
        } else {
            if (! empty($start['warehouse_id']) && (float) ($start['qty'] ?? 0) > 0) {
                $this->myWarehouse((int) $start['warehouse_id']);
            }
            $item = Item::create($d + ['workspace_id' => $ws->id]);
            if (! empty($start['warehouse_id']) && (float) ($start['qty'] ?? 0) > 0) {
                Stock::receive($ws, $request->user(), ['item_id' => $item->id, 'warehouse_id' => (int) $start['warehouse_id'], 'qty' => (float) $start['qty'], 'price' => $d['price'], 'note' => 'Начальный остаток']);
            }
        }

        return back()->with('ok', __('Сохранено'));
    }

    /** Склад своей компании, доступный пользователю (склад объекта — только сотрудникам этого объекта) */
    private function myWarehouse(int $id): Warehouse
    {
        $w = Warehouse::of($this->ws()->id)->findOrFail($id);
        if ($w->project_id) {
            $this->checkProject($w->project_id);
        }

        return $w;
    }

    public function warehouseSave(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $ws = $this->ws();
        $d = $request->validate(['id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'project_id' => ['nullable', 'string', 'max:40'],
            'keeper_id' => ['nullable', Rule::exists('users', 'id')->where('workspace_id', $ws->id)]]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
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
            'project_id' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:300'], 'date' => ['nullable', 'date'],
            // поставщик — только из контрагентов своей компании
            'counterparty_id' => ['nullable', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)],
        ]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $this->myWarehouse((int) $d['warehouse_id']);
        if (! empty($d['to_warehouse_id'])) {
            $this->myWarehouse((int) $d['to_warehouse_id']);
        }
        if ($d['op'] === 'in' && ! empty($d['counterparty_id']) && \App\Models\Counterparty::whereKey($d['counterparty_id'])->value('status') === 'blocked') {
            return back()->withInput()->withErrors(['counterparty_id' => __('Контрагент заблокирован — приход от него не принимаем')]);
        }
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
            try {
                $pr = $this->payFor($ws, $u, [[(int) $d['item_id'], (float) $d['qty'], (float) ($d['price'] ?? 0)]], $d['counterparty_id'] ?? null, (int) $d['warehouse_id'], $d['note'] ?? null);
            } catch (\DomainException $e) {
                return back()->with('warn', __('Приход проведён, но заявка на оплату не создана: :e', ['e' => $e->getMessage()]));
            }
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
        $wh = Warehouse::of($ws->id)->find($warehouseId);

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
            'items' => Item::of($ws->id)->orderBy('name')->get(), 'whs' => Warehouse::of($ws->id)->where('is_active', true)->tap(fn ($q) => $this->scopeProjects($q))->get(),
            'counterparties' => $this->counterparties(),
            'counts' => SupplyRequest::of($ws->id)->tap(fn ($q) => $this->scopeProjects($q))->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')]);
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
        // руководитель и бухгалтер получают её в Telegram с кнопками «Согласовать / Отклонить»
        $sr = \App\Services\Erp\Supply::create($ws, $u, $d['project_id'], array_map(fn ($l) => ['name' => $l['item'], 'qty' => $l['qty'], 'unit' => $l['unit'] ?? ''], $lines),
            $d['need_by'] ?? null, $d['note'] ?? null);

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
            // согласование и ответ автору в Telegram — так же, как кнопкой в боте (повторное решение не пройдёт)
            \App\Services\Erp\Supply::decide($sr, $u, $a === 'approve');

            return back()->with('ok', __('Сохранено'));
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
            $this->myWarehouse((int) $d['warehouse_id']);
            Stock::receiveSupply($ws, $u, $sr, $d['qty'] ?? [], (int) $d['warehouse_id']);
            if ($request->boolean('make_pr')) {
                \App\Services\Erp\Supply::notifyAuthor($sr->fresh(), $u);
                $lines = $sr->lines->map(fn ($l) => [$l->item_id, (float) ($d['qty'][$l->id] ?? 0), (float) $l->price])->all();
                try {
                    $pr = $this->payFor($ws, $u, $lines, $sr->counterparty_id, (int) $d['warehouse_id'], 'заявка на снабжение №'.$sr->number);
                } catch (\DomainException $e) {
                    return back()->with('warn', __('Приход проведён, но заявка на оплату не создана: :e', ['e' => $e->getMessage()]));
                }
                if ($pr) {
                    return back()->with('ok', __('Приход проведён. Создана заявка на оплату №:n — ушла на согласование.', ['n' => $pr->number]));
                }
            }
        } else {
            abort(422);
        }
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'supply.edit', 'summary' => 'Заявка на снабжение №'.$sr->number.': '.$sr->fresh()->status]);
        \App\Services\Erp\Supply::notifyAuthor($sr->fresh(), $u);   // прорабу в Telegram: заказана / принята

        return back()->with('ok', __('Сохранено'));
    }

    /* ---------------- Тендеры ---------------- */

    public function tenders(Request $request)
    {
        $ws = $this->ws();

        return view('company.erp.tenders', ['ws' => $ws, 'projects' => $this->projects(),
            'list' => Tender::of($ws->id)->with(['bids.counterparty', 'user'])->withCount('bids')->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->paginate(30),
            'supply' => SupplyRequest::of($ws->id)->whereIn('status', ['approved', 'new'])->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->get()]);
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
