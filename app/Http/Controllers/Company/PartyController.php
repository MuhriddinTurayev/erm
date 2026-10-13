<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\CashOp;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\PaymentRequest;
use App\Services\Erp\Money;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Контрагенты и договоры: карточки, папки, проверка, блокировка, оплаты по договору */
class PartyController extends ErpController
{
    public const KINDS = ['supplier' => 'Поставщик', 'contractor' => 'Подрядчик', 'client' => 'Клиент', 'other' => 'Другое'];

    public const STATUSES = ['check' => 'На проверке', 'ok' => 'Проверен', 'suspicious' => 'Подозрительный', 'blocked' => 'Заблокирован'];

    /** Сальдо по контрагентам: сколько мы заплатили и сколько получили (в валюте компании) */
    private function turnover(int $wsId): array
    {
        return CashOp::of($wsId)->whereNotNull('counterparty_id')->whereNull('transfer_key')
            ->selectRaw("counterparty_id, sum(case when type='out' then amount_base else 0 end) o, sum(case when type='in' then amount_base else 0 end) i")
            ->groupBy('counterparty_id')->get()->keyBy('counterparty_id')->all();
    }

    /** Остаток по договору в валюте компании: считается в валюте договора, поэтому долларовый договор, оплаченный долларами, закрыт при любом курсе */
    private function contractDebt(Contract $c, $ws): float
    {
        return \App\Services\Erp\Contracts::debtBase($ws, $c);
    }

    public function index(Request $request)
    {
        $ws = $this->ws();
        $f = $request->only(['q', 'kind', 'status', 'folder']);
        $list = Counterparty::of($ws->id)->withCount('contracts')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('name', 'like', "%$v%")->orWhere('inn', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(array_key_exists('folder', $f) && $f['folder'] !== null, fn ($q) => $f['folder'] === '' ? $q->whereNull('folder') : $q->where('folder', $f['folder']))
            ->orderByRaw("status = 'check' desc")->orderBy('name')->paginate(60)->withQueryString();
        $debts = [];
        foreach (Contract::of($ws->id)->where('status', 'active')->tap(fn ($q) => $this->scopeProjects($q))->get() as $c) {
            $debts[$c->counterparty_id] = ($debts[$c->counterparty_id] ?? 0) + ($c->direction === 'out' ? 1 : -1) * $this->contractDebt($c, $ws);
        }

        return view('company.erp.parties', [
            'ws' => $ws, 'list' => $list, 'f' => $f, 'debts' => $debts, 'turn' => $this->turnover($ws->id),
            'folders' => Counterparty::of($ws->id)->whereNotNull('folder')->select('folder', DB::raw('count(*) n'))->groupBy('folder')->orderBy('folder')->pluck('n', 'folder'),
            'stats' => Counterparty::of($ws->id)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Counterparty $cp)
    {
        $this->mine($cp);
        $ws = $this->ws();
        // сотрудник объекта видит договоры, оплаты и заявки только своих объектов
        $contracts = $cp->contracts()->tap(fn ($q) => $this->scopeProjects($q))->latest('date')->get()->each(fn ($c) => $c->debt = $this->contractDebt($c, $ws));

        return view('company.erp.party', [
            'ws' => $ws, 'cp' => $cp, 'contracts' => $contracts, 'projects' => $this->projects(),
            'ops' => CashOp::of($ws->id)->where('counterparty_id', $cp->id)->tap(fn ($q) => $this->scopeProjects($q, 'cash_ops.project_id'))->with(['desk', 'category'])->latest('date')->limit(100)->get(),
            'requests' => PaymentRequest::of($ws->id)->where('counterparty_id', $cp->id)->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->limit(30)->get(),
            'same' => $cp->inn ? Counterparty::of($ws->id)->where('inn', $cp->inn)->where('id', '!=', $cp->id)->get() : collect(),
            'currencies' => Money::currencies($ws),
        ] + $this->thread('counterparty', $cp->id));
    }

    private function rules($ws): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'folder' => ['nullable', 'string', 'max:60'], 'name' => ['required', 'string', 'max:160'],
            'inn' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 ]*$/'], 'phone' => ['nullable', 'string', 'max:40'], 'contact' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'], 'bank' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate($this->rules($ws));
        $d['inn'] = isset($d['inn']) ? preg_replace('/\D/', '', $d['inn']) : null;
        // проверка дублей по ИНН и телефону
        $dup = null;
        if ($d['inn'] || ! empty($d['phone'])) {
            $dup = Counterparty::of($ws->id)->where(fn ($q) => $q->when($d['inn'], fn ($x) => $x->orWhere('inn', $d['inn']))
                ->when($d['phone'] ?? null, fn ($x, $p) => $x->orWhere('phone', $p)))->first();
        }
        if ($dup && ! $request->boolean('force')) {
            return back()->withInput()->with('dup', $dup->id)->withErrors(['name' => __('Такой контрагент уже есть: :n. Отметьте «Всё равно создать», если это другой.', ['n' => $dup->name])]);
        }
        // новый контрагент — сразу «на проверке»; руководитель получает запрос на проверку
        $cp = Counterparty::create($d + ['workspace_id' => $ws->id, 'status' => $u->isAdmin() ? 'ok' : 'check', 'created_by' => $u->id]);
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'party.add', 'summary' => 'Новый контрагент: '.$cp->name.($cp->inn ? ' · ИНН '.$cp->inn : '')]);
        if ($cp->status === 'check' && Telegram::enabled()) {
            foreach ($ws->users()->where('role', 'admin')->whereNotNull('telegram_chat_id')->get() as $a) {
                try {
                    Telegram::message($a->telegram_chat_id, '🔎 <b>'.__('Новый контрагент на проверку')."</b>\n".e($cp->name).($cp->inn ? "\n".__('ИНН').': '.$cp->inn : '')."\n👤 ".e($u->name),
                        [[['text' => __('Открыть'), 'url' => url('/company/parties/'.$cp->id)]]]);
                } catch (\Throwable) {
                }
            }
        }

        return redirect('/company/parties/'.$cp->id)->with('ok', __('Контрагент добавлен'));
    }

    public function update(Request $request, Counterparty $cp)
    {
        $this->mine($cp);
        $this->editor();
        $d = $request->validate($this->rules($this->ws()));
        $d['inn'] = isset($d['inn']) ? preg_replace('/\D/', '', $d['inn']) : null;
        $cp->update($d);

        return back()->with('ok', __('Сохранено'));
    }

    /** Проверен / подозрительный / заблокирован — с причиной */
    public function status(Request $request, Counterparty $cp)
    {
        $this->mine($cp);
        $u = $request->user();
        abort_unless($u->isAdmin() || $u->role === 'accountant', 403);
        $d = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))], 'reason' => ['nullable', 'string', 'max:300',
            Rule::requiredIf(in_array($request->input('status'), ['suspicious', 'blocked'], true))]]);
        $cp->update(['status' => $d['status'], 'status_reason' => $d['reason'] ?? null]);
        AuditLog::create(['workspace_id' => $cp->workspace_id, 'user_id' => $u->id, 'action' => 'party.edit',
            'summary' => $cp->name.': '.self::STATUSES[$d['status']].(! empty($d['reason']) ? ' · '.$d['reason'] : '')]);

        return back()->with('ok', __('Статус изменён'));
    }

    public function destroy(Counterparty $cp)
    {
        $this->mine($cp);
        abort_unless(auth()->user()->isAdmin(), 403);
        if ($cp->contracts()->exists() || $cp->ops()->exists()) {
            return back()->withErrors(['name' => __('У контрагента есть договоры или оплаты — его можно только заблокировать.')]);
        }
        $cp->delete();

        return redirect('/company/parties')->with('ok', __('Контрагент удалён'));
    }

    /* ---------------- Договоры ---------------- */

    public function contracts(Request $request)
    {
        $ws = $this->ws();
        $f = $request->only(['q', 'status', 'dir', 'project']);
        $list = Contract::of($ws->id)->with('counterparty')
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('number', 'like', "%$v%")->orWhere('subject', 'like', "%$v%")
                ->orWhereHas('counterparty', fn ($c) => $c->where('name', 'like', "%$v%"))))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['dir'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($f['project'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->tap(fn ($q) => $this->scopeProjects($q))
            ->latest('date')->paginate(50)->withQueryString();
        $list->getCollection()->each(fn ($c) => $c->debt = $this->contractDebt($c, $ws));

        return view('company.erp.contracts', ['ws' => $ws, 'list' => $list, 'f' => $f, 'projects' => $this->projects(),
            'counterparties' => $this->counterparties(), 'currencies' => Money::currencies($ws)]);
    }

    public function contractShow(Contract $c)
    {
        $this->mine($c);
        $ws = $this->ws();
        $c->debt = $this->contractDebt($c, $ws);

        return view('company.erp.contract', ['ws' => $ws, 'c' => $c->load('counterparty'), 'projects' => $this->projects(), 'currencies' => Money::currencies($ws),
            'ops' => $c->ops()->with(['desk', 'user'])->latest('date')->get(), 'requests' => PaymentRequest::where('contract_id', $c->id)->with('user')->latest('id')->get(),
            'history' => AuditLog::with('user')->where('workspace_id', $ws->id)->where('action', 'like', 'contract.%')->where('summary', 'like', '%№'.$c->number.'%')->latest('id')->limit(40)->get(),
            'didox' => (bool) $ws->setting('didox.token'),
        ] + $this->thread('contract', $c->id));
    }

    private function contractRules($ws): array
    {
        return [
            'counterparty_id' => ['required', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)], 'project_id' => ['nullable', 'string', 'max:40'],
            'number' => ['required', 'string', 'max:40'], 'date' => ['required', 'date'], 'subject' => ['required', 'string', 'max:300'],
            'direction' => ['required', Rule::in(['in', 'out'])], 'amount' => ['required', 'numeric', 'min:0'], 'currency' => ['required', Rule::in(Money::currencies($ws))],
            'due_date' => ['nullable', 'date'], 'status' => ['required', Rule::in(['draft', 'active', 'done', 'cancelled'])],
        ];
    }

    public function contractStore(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate($this->contractRules($ws));
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $cp = Counterparty::find($d['counterparty_id']);
        if ($cp->status === 'blocked') {
            return back()->withInput()->withErrors(['counterparty_id' => __('Контрагент заблокирован: :r', ['r' => $cp->status_reason])]);
        }
        $c = Contract::create($d + ['workspace_id' => $ws->id, 'created_by' => $u->id]);
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'project' => $this->projects()[$c->project_id] ?? null, 'action' => 'contract.add',
            'summary' => 'Договор №'.$c->number.' · '.$cp->name.' · '.Money::fmt($c->amount, $c->currency)]);

        return redirect('/company/contracts/'.$c->id)->with('ok', __('Договор добавлен'));
    }

    public function contractUpdate(Request $request, Contract $c)
    {
        $this->mine($c);
        $u = $this->editor();
        $d = $request->validate($this->contractRules($this->ws()));
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $cp = Counterparty::find($d['counterparty_id']);
        if ($cp->status === 'blocked' && (int) $cp->id !== (int) $c->counterparty_id) {
            return back()->withInput()->withErrors(['counterparty_id' => __('Контрагент заблокирован: :r', ['r' => $cp->status_reason])]);
        }
        // у договора с оплатами валюту не меняем: иначе оплаченное пересчитается неверно
        if ($d['currency'] !== $c->currency && $c->ops()->exists()) {
            return back()->withInput()->withErrors(['currency' => __('По договору уже есть оплаты — валюту изменить нельзя')]);
        }
        $changes = [];
        foreach (['amount' => 'сумма', 'status' => 'статус', 'due_date' => 'срок', 'subject' => 'предмет'] as $k => $label) {
            $old = $c->$k instanceof \DateTimeInterface ? $c->$k->format('Y-m-d') : (string) $c->$k;
            if ((string) ($d[$k] ?? '') !== $old && ! ($k === 'amount' && (float) $d[$k] === (float) $c->amount)) {
                $changes[] = $label.': '.$old.' → '.($d[$k] ?? '—');
            }
        }
        $c->update($d);
        if ($changes) {
            AuditLog::create(['workspace_id' => $c->workspace_id, 'user_id' => $u->id, 'action' => 'contract.edit', 'summary' => 'Договор №'.$c->number.': '.implode('; ', $changes)]);
        }

        return back()->with('ok', __('Сохранено'));
    }
}
