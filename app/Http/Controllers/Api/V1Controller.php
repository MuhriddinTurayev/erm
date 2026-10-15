<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Company\PartyController;
use App\Http\Controllers\Company\SalesController;
use App\Http\Controllers\Company\WorkController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LeadController;
use App\Http\Middleware\ApiAuth;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\CashDesk;
use App\Models\CashOp;
use App\Models\Counterparty;
use App\Models\Item;
use App\Models\LeadInbox;
use App\Models\PaymentRequest;
use App\Models\StockMove;
use App\Models\Task;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Services\Api\Present;
use App\Services\Api\Spec;
use App\Services\Apartments;
use App\Services\Erp\Cash;
use App\Services\Erp\Money;
use App\Services\Erp\Stock;
use App\Services\Inventory;
use App\Services\StateWriter;
use App\Services\Telegram;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Открытый API v1 для других программ (сайт, CRM, 1С, Make/Zapier, свои приложения).
 * Ключ выдаёт администратор платформы с ролью в компании: ключ видит и меняет то же, что сотрудник с этой ролью,
 * и только на своих объектах (см. ApiAuth). Каждое изменение — в «Истории изменений».
 */
class V1Controller extends Controller
{
    private function token(Request $r): ApiToken
    {
        return $r->attributes->get('api_token');
    }

    private function company(Request $r): Workspace
    {
        return $this->token($r)->workspace;
    }

    /** Список с постраничной выдачей: {"data": […], "meta": {page, per_page, total, last_page}} */
    private function paged($query, Request $r, Closure $map)
    {
        $per = max(1, min(200, (int) $r->query('per_page', 50)));
        $p = $query->paginate($per, ['*'], 'page', max(1, (int) $r->query('page', 1)));

        return response()->json(['data' => $p->getCollection()->map($map)->values(), 'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]]);
    }

    private function pagedArray(array $rows, Request $r)
    {
        $per = max(1, min(200, (int) $r->query('per_page', 50)));
        $page = max(1, (int) $r->query('page', 1));

        return response()->json(['data' => array_values(array_slice($rows, ($page - 1) * $per, $per)),
            'meta' => ['page' => $page, 'per_page' => $per, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $per))]]);
    }

    /** ?updated_since=2026-10-01T00:00:00+05:00 */
    private function since($q, Request $r)
    {
        if ($s = $r->query('updated_since')) {
            try {
                $q->where($q->getModel()->getTable().'.updated_at', '>=', Carbon::parse((string) $s)->utc());
            } catch (\Throwable) {
                throw ValidationException::withMessages(['updated_since' => 'Use ISO 8601, e.g. 2026-10-01T00:00:00+05:00']);
            }
        }

        return $q;
    }

    private function dates(Request $r): array
    {
        $d = $r->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return [isset($d['from']) ? Carbon::parse($d['from'])->toDateString() : null, isset($d['to']) ? Carbon::parse($d['to'])->toDateString() : null];
    }

    private function audit(Request $r, string $action, string $summary, ?string $project = null): void
    {
        $t = $this->token($r);
        AuditLog::create(['workspace_id' => $t->workspace_id, 'user_id' => null, 'project' => $project, 'action' => $action, 'summary' => 'API «'.$t->name.'»: '.$summary]);
    }

    /** Объект, если он есть и ключ его видит (чужой объект — как будто его нет) */
    private function projectOr404(Workspace $ws, string $id, ?Request $r = null): array
    {
        $p = StateWriter::project($ws, $id);
        abort_if(! $p || ($r && ! $this->token($r)->seesObject($id)), 404, 'Object not found');

        return $p;
    }

    /** Объекты, которые видит ключ */
    private function visibleProjects(Request $r): array
    {
        $t = $this->token($r);

        return array_values(array_filter($this->company($r)->projects(), fn ($p) => is_array($p) && ! empty($p['id']) && $t->seesObject((string) $p['id'])));
    }

    /** Записи своих объектов и общие (без объекта) — как у сотрудника объекта */
    private function scoped($q, Request $r, string $col = 'project_id')
    {
        $only = $this->token($r)->allowedObjects();

        return $only === null ? $q : $q->where(fn ($x) => $x->whereIn($col, $only)->orWhereNull($col));
    }

    /* ---------------- общее ---------------- */

    public function index()
    {
        return response()->json(['name' => \App\Models\PlatformSetting::brand().' API', 'version' => Spec::VERSION,
            'auth' => 'Authorization: Bearer <key>', 'openapi' => url('/api/v1/openapi.json'), 'me' => url('/api/v1/me')]);
    }

    public function openapi()
    {
        return response()->json(Spec::openapi(url('/')), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function me(Request $r)
    {
        $t = $this->token($r);
        $ws = $t->workspace;

        return response()->json(['data' => [
            'company' => ['id' => $ws->id, 'name' => $ws->name, 'currency' => Money::base($ws), 'timezone' => $ws->timezone ?: 'Asia/Tashkent'],
            'key' => ['name' => $t->name, 'role' => $t->role, 'role_name' => $t->roleName(), 'read_only' => (bool) $t->read_only, 'objects' => $t->allowedObjects(),
                'scopes' => $t->effectiveScopes(), 'created_at' => $t->created_at?->toIso8601String(), 'expires_at' => $t->expires_at?->toIso8601String()],
            'limits' => ['requests_per_minute' => ApiAuth::PER_MINUTE],
        ]]);
    }

    public function users(Request $r)
    {
        $users = User::where('workspace_id', $this->token($r)->workspace_id)->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'role_name' => $u->roleName()])->values()]);
    }

    /* ---------------- объекты и квартиры ---------------- */

    public function objects(Request $r)
    {
        return response()->json(['data' => array_map(fn ($p) => Present::object($p), $this->visibleProjects($r))]);
    }

    public function object(Request $r, string $object)
    {
        return response()->json(['data' => Present::object($this->projectOr404($this->company($r), $object, $r))]);
    }

    public function apartments(Request $r, string $object)
    {
        $p = $this->projectOr404($this->company($r), $object, $r);
        $f = $r->validate(['status' => ['nullable', Rule::in(array_keys(SalesController::STATUSES))], 'rooms' => ['nullable', 'integer', 'min:0'], 'floor' => ['nullable', 'integer']]);
        $sales = $this->token($r)->can('sales:read');
        $rows = array_filter(Apartments::list($p), fn ($a) => (! isset($f['status']) || $a['status'] === $f['status'])
            && (! isset($f['rooms']) || (int) $a['rooms'] === (int) $f['rooms']) && (! isset($f['floor']) || (int) $a['floor'] === (int) $f['floor']));

        return $this->pagedArray(array_map(fn ($a) => Present::apartment($a, $object, $sales), array_values($rows)), $r);
    }

    public function apartment(Request $r, string $object, string $apartment)
    {
        $p = $this->projectOr404($this->company($r), $object, $r);
        $a = Apartments::find($p, $apartment) ?? abort(404, 'Apartment not found');

        return response()->json(['data' => Present::apartment($a, $object, $this->token($r)->can('sales:read'))]);
    }

    /** Бронь, продажа, цена, снять бронь — передаются только меняющиеся поля */
    public function apartmentUpdate(Request $r, string $object, string $apartment)
    {
        $ws = $this->company($r);
        $p = $this->projectOr404($ws, $object, $r);
        $a = Apartments::find($p, $apartment) ?? abort(404, 'Apartment not found');
        $d = $r->validate([
            'status' => ['sometimes', Rule::in(array_keys(SalesController::STATUSES))], 'buyer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'buyer_phone' => ['sometimes', 'nullable', 'string', 'max:40'], 'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'plan' => ['sometimes', 'nullable', Rule::in(SalesController::PLANS)], 'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        if (! $d) {
            throw ValidationException::withMessages(['status' => 'Nothing to change: send status, buyer_name, buyer_phone, price, plan or note']);
        }
        $status = $d['status'] ?? $a['status'];
        $buyer = array_key_exists('buyer_name', $d) ? trim((string) $d['buyer_name']) : $a['buyer'];
        if (in_array($status, ['sold', 'booked'], true) && $buyer === '') {
            throw ValidationException::withMessages(['buyer_name' => 'buyer_name is required for booked and sold']);
        }
        if ($status !== $a['status'] && in_array($status, ['sold', 'booked'], true) && ! Inventory::ready($p)) {
            return ApiAuth::fail(409, 'not_on_sale', 'The object is not on sale yet: the objects administrator must put it on sale first');
        }
        StateWriter::mutate($ws->id, function (array &$data) use ($object, $apartment, $d, $a, $status, $buyer) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') !== $object) {
                    continue;
                }
                $p['apts'] = (array) ($p['apts'] ?? []);
                $o = (array) ($p['apts'][$apartment] ?? []);
                $o['status'] = $status;
                $o['buyer'] = $buyer;
                foreach (['buyer_phone' => 'phone', 'plan' => 'plan', 'note' => 'note'] as $in => $key) {
                    if (array_key_exists($in, $d)) {
                        $o[$key] = trim((string) $d[$in]);
                    }
                }
                if (array_key_exists('price', $d)) {
                    $price = (float) ($d['price'] ?? 0);
                    if ($price > 0 && abs($price - $a['basePrice']) > 0.5) {
                        $o['price'] = $price;
                    } else {
                        unset($o['price']);
                    }
                }
                $o['payments'] = array_values((array) ($o['payments'] ?? []));
                $p['apts'][$apartment] = $o;
            }
        });
        $p = StateWriter::project(Workspace::find($ws->id), $object);
        $now = Apartments::find($p, $apartment);
        $this->audit($r, 'apt.edit', __('Кв.').' '.$now['num'].': '.SalesController::STATUSES[$now['status']].($now['buyer'] ? ' · '.$now['buyer'] : '')
            .' · '.number_format($now['price'], 0, '.', ' '), (string) ($p['name'] ?? $object));

        return response()->json(['data' => Present::apartment($now, $object, true)]);
    }

    /** Оплаты покупателей по квартирам за период */
    public function salesPayments(Request $r)
    {
        [$from, $to] = $this->dates($r);
        $only = $r->query('object');
        $rows = [];
        foreach ($this->visibleProjects($r) as $p) {
            if ($only && (string) $p['id'] !== $only) {
                continue;
            }
            foreach (Apartments::list($p) as $a) {
                foreach ($a['payments'] as $x) {
                    $date = (string) ($x['date'] ?? '');
                    if (($from && $date < $from) || ($to && $date > $to)) {
                        continue;
                    }
                    $rows[] = ['id' => (string) ($x['id'] ?? ''), 'date' => $date, 'amount' => (float) ($x['amount'] ?? 0), 'method' => $x['way'] ?? null,
                        'object_id' => (string) $p['id'], 'apartment_id' => $a['id'], 'apartment_number' => $a['num'], 'buyer' => ['name' => $a['buyer'], 'phone' => $a['phone']]];
                }
            }
        }
        usort($rows, fn ($x, $y) => strcmp($y['date'], $x['date']));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'sum' => array_sum(array_column($rows, 'amount'))]]);
    }

    /* ---------------- заявки клиентов ---------------- */

    public function leads(Request $r)
    {
        $r->validate(['since_id' => ['nullable', 'integer', 'min:0']]);
        $q = LeadInbox::where('workspace_id', $this->token($r)->workspace_id);
        // синхронизация: since_id — по возрастанию (сначала старые), иначе — новые сверху
        $q = $r->filled('since_id') ? $q->where('id', '>', (int) $r->query('since_id'))->orderBy('id') : $q->orderByDesc('id');

        return $this->paged($q, $r, fn ($l) => Present::lead($l));
    }

    public function leadStore(Request $r)
    {
        $d = $r->validate([
            'name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'], 'source' => ['nullable', 'string', 'max:40'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:9'], 'budget' => ['nullable', 'integer', 'min:0'], 'message' => ['nullable', 'string', 'max:1000'],
        ]);
        if (trim((string) ($d['name'] ?? '')) === '' && trim((string) ($d['phone'] ?? '')) === '') {
            throw ValidationException::withMessages(['phone' => 'name or phone is required']);
        }
        $digits = preg_replace('/\D/', '', (string) ($d['phone'] ?? ''));
        $lead = LeadController::push($this->token($r)->workspace_id, [
            'ch' => 'api', 'kind' => 'form', 'key' => 'api:'.($digits ?: bin2hex(random_bytes(6))), 'name' => trim((string) ($d['name'] ?? '')),
            'phone' => trim((string) ($d['phone'] ?? '')), 'src' => $d['source'] ?? $this->token($r)->name, 'rooms' => isset($d['rooms']) ? (string) $d['rooms'] : '',
            'budget' => $d['budget'] ?? null, 'text' => (string) ($d['message'] ?? ''),
        ]);

        return response()->json(['data' => Present::lead($lead)], 201);
    }

    /* ---------------- склад ---------------- */

    public function stockItems(Request $r)
    {
        $ws = $this->company($r);
        $q = trim((string) $r->query('q'));
        $items = $this->since(Item::of($ws->id), $r)
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('name', 'like', "%$q%")->orWhere('sku', 'like', "%$q%")->orWhere('group', 'like', "%$q%")))
            ->orderBy('group')->orderBy('name')->get();
        $bal = Stock::balances($ws);
        $names = $this->scoped(Warehouse::of($ws->id), $r)->get()->mapWithKeys(fn ($w) => [$w->id => __($w->name)])->all();
        $rows = $items->map(fn ($i) => Present::item($i, array_intersect_key($bal[$i->id] ?? [], $names), $names))->all();
        if ($r->boolean('low')) {
            $rows = array_filter($rows, fn ($x) => $x['low']);
        }

        return $this->pagedArray(array_values($rows), $r);
    }

    public function stockWarehouses(Request $r)
    {
        return response()->json(['data' => $this->scoped(Warehouse::of($this->token($r)->workspace_id), $r)->orderBy('id')->get()->map(fn ($w) => Present::warehouse($w))->values()]);
    }

    public function stockMoves(Request $r)
    {
        [$from, $to] = $this->dates($r);
        $f = $r->validate(['type' => ['nullable', Rule::in(['in', 'out', 'transfer', 'adjust'])], 'item' => ['nullable', 'integer'], 'warehouse' => ['nullable', 'integer'], 'object' => ['nullable', 'string', 'max:40']]);
        $q = StockMove::of($this->token($r)->workspace_id)->with(['item', 'warehouse'])
            ->whereIn('warehouse_id', $this->scoped(Warehouse::of($this->token($r)->workspace_id), $r)->pluck('id'))
            ->when($from, fn ($x) => $x->where('date', '>=', $from))->when($to, fn ($x) => $x->where('date', '<=', $to))
            ->when($f['type'] ?? null, fn ($x, $v) => $x->where('type', $v))->when($f['item'] ?? null, fn ($x, $v) => $x->where('item_id', $v))
            ->when($f['warehouse'] ?? null, fn ($x, $v) => $x->where('warehouse_id', $v))->when($f['object'] ?? null, fn ($x, $v) => $x->where('project_id', $v))
            ->orderByDesc('date')->orderByDesc('id');

        return $this->paged($q, $r, fn ($m) => Present::move($m));
    }

    /* ---------------- деньги ---------------- */

    public function cashDesks(Request $r)
    {
        $ws = $this->company($r);
        $bal = Cash::balances($ws);

        return response()->json(['data' => $this->scoped(CashDesk::of($ws->id), $r)->orderBy('id')->get()->map(fn ($d) => Present::desk($d, $bal[$d->id] ?? 0))->values()]);
    }

    public function cashOps(Request $r)
    {
        [$from, $to] = $this->dates($r);
        $f = $r->validate(['desk' => ['nullable', 'integer'], 'type' => ['nullable', Rule::in(['in', 'out'])], 'object' => ['nullable', 'string', 'max:40']]);
        $q = $this->scoped(CashOp::of($this->token($r)->workspace_id), $r)->with(['desk', 'category'])
            ->when($from, fn ($x) => $x->where('date', '>=', $from))->when($to, fn ($x) => $x->where('date', '<=', $to))
            ->when($f['desk'] ?? null, fn ($x, $v) => $x->where('cash_desk_id', $v))->when($f['type'] ?? null, fn ($x, $v) => $x->where('type', $v))
            ->when($f['object'] ?? null, fn ($x, $v) => $x->where('project_id', $v))
            ->orderByDesc('date')->orderByDesc('id');

        return $this->paged($q, $r, fn ($o) => Present::cashOp($o));
    }

    public function paymentRequests(Request $r)
    {
        $f = $r->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'paid', 'cancelled'])]]);
        $q = $this->scoped($this->since(PaymentRequest::of($this->token($r)->workspace_id), $r), $r)->with(['counterparty', 'category'])
            ->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v))->orderByDesc('id');

        return $this->paged($q, $r, fn ($x) => Present::paymentRequest($x));
    }

    /* ---------------- контрагенты ---------------- */

    public function counterparties(Request $r)
    {
        $f = $r->validate(['kind' => ['nullable', Rule::in(array_keys(PartyController::KINDS))], 'inn' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:100']]);
        $q = $this->since(Counterparty::of($this->token($r)->workspace_id), $r)
            ->when($f['kind'] ?? null, fn ($x, $v) => $x->where('kind', $v))
            ->when($f['inn'] ?? null, fn ($x, $v) => $x->where('inn', preg_replace('/\D/', '', $v)))
            ->when($f['q'] ?? null, fn ($x, $v) => $x->where(fn ($y) => $y->where('name', 'like', "%$v%")->orWhere('inn', 'like', "%$v%")->orWhere('phone', 'like', "%$v%")))
            ->orderByDesc('id');

        return $this->paged($q, $r, fn ($c) => Present::counterparty($c));
    }

    private function party(Request $r, int $id): Counterparty
    {
        return Counterparty::of($this->token($r)->workspace_id)->find($id) ?? abort(404, 'Counterparty not found');
    }

    public function counterparty(Request $r, int $id)
    {
        return response()->json(['data' => Present::counterparty($this->party($r, $id))]);
    }

    private function partyRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:160'], 'kind' => [$req, Rule::in(array_keys(PartyController::KINDS))],
            'inn' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9 ]*$/'], 'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:120'], 'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bank' => ['sometimes', 'nullable', 'string', 'max:255'], 'folder' => ['sometimes', 'nullable', 'string', 'max:60'], 'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function counterpartyStore(Request $r)
    {
        $ws = $this->company($r);
        $d = $r->validate($this->partyRules(true));
        $d['inn'] = isset($d['inn']) ? (preg_replace('/\D/', '', $d['inn']) ?: null) : null;
        // такой контрагент уже есть (тот же ИНН) — не дублируем; force=true — всё равно создать
        if ($d['inn'] && ! $r->boolean('force') && ($dup = Counterparty::of($ws->id)->where('inn', $d['inn'])->first())) {
            return response()->json(['error' => ['code' => 'duplicate', 'message' => 'Counterparty with this INN already exists', 'existing_id' => $dup->id]], 409);
        }
        $t = $this->token($r);
        $cp = Counterparty::create($d + ['workspace_id' => $ws->id, 'status' => 'check', 'created_by' => null]);
        $this->audit($r, 'party.add', 'Новый контрагент: '.$cp->name.($cp->inn ? ' · ИНН '.$cp->inn : ''));
        if (Telegram::enabled()) {
            foreach ($ws->users()->where('role', 'admin')->whereNotNull('telegram_chat_id')->get() as $a) {
                try {
                    Telegram::message($a->telegram_chat_id, '🔎 <b>'.__('Новый контрагент на проверку')."</b>\n".e($cp->name).($cp->inn ? "\n".__('ИНН').': '.$cp->inn : '')."\n🔌 API: ".e($t->name),
                        [[['text' => __('Открыть'), 'url' => url('/company/parties/'.$cp->id)]]]);
                } catch (\Throwable) {
                }
            }
        }

        return response()->json(['data' => Present::counterparty($cp)], 201);
    }

    public function counterpartyUpdate(Request $r, int $id)
    {
        $cp = $this->party($r, $id);
        $d = $r->validate($this->partyRules(false));
        if (array_key_exists('inn', $d)) {
            $d['inn'] = preg_replace('/\D/', '', (string) $d['inn']) ?: null;
        }
        $cp->update($d);
        if ($cp->wasChanged()) {
            $this->audit($r, 'party.edit', 'Контрагент изменён: '.$cp->name);
        }

        return response()->json(['data' => Present::counterparty($cp)]);
    }

    /* ---------------- задачи ---------------- */

    public function tasks(Request $r)
    {
        $f = $r->validate(['status' => ['nullable', Rule::in(array_keys(WorkController::STATUSES))], 'object' => ['nullable', 'string', 'max:40'], 'assignee' => ['nullable', 'integer']]);
        $q = $this->scoped($this->since(Task::of($this->token($r)->workspace_id), $r), $r)->with('assignee')
            ->when($f['status'] ?? null, fn ($x, $v) => $x->where('status', $v))->when($f['object'] ?? null, fn ($x, $v) => $x->where('project_id', $v))
            ->when($f['assignee'] ?? null, fn ($x, $v) => $x->where('assignee_id', $v))->orderByDesc('id');

        return $this->paged($q, $r, fn ($t) => Present::task($t));
    }

    private function taskOr404(Request $r, int $id): Task
    {
        $t = Task::of($this->token($r)->workspace_id)->with('assignee')->find($id);
        abort_if(! $t || ! $this->token($r)->seesObject($t->project_id), 404, 'Task not found');

        return $t;
    }

    public function task(Request $r, int $id)
    {
        return response()->json(['data' => Present::task($this->taskOr404($r, $id))]);
    }

    /** $only — объекты ключа: тогда задача обязательно на одном из них */
    private function taskRules(Workspace $ws, bool $create, ?array $only = null): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'object_id' => [$create && $only !== null ? 'required' : 'sometimes', $only === null ? 'nullable' : 'filled', Rule::in(array_keys(StateWriter::projects($ws, $only)))],
            'assignee_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('workspace_id', $ws->id)],
            'due_date' => ['sometimes', 'nullable', 'date'], 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])],
        ];
    }

    private function taskData(array $d): array
    {
        if (array_key_exists('object_id', $d)) {
            $d['project_id'] = $d['object_id'];
            unset($d['object_id']);
        }

        return $d;
    }

    public function taskStore(Request $r)
    {
        $ws = $this->company($r);
        $t = $this->token($r);
        $d = $this->taskData($r->validate($this->taskRules($ws, true, $t->allowedObjects())));
        $task = Task::create($d + ['workspace_id' => $ws->id, 'author_id' => null, 'priority' => 'normal', 'status' => 'todo']);
        $task->load('assignee');
        $this->audit($r, 'task.add', 'Задача: '.$task->title.($task->assignee ? ' → '.$task->assignee->name : ''), StateWriter::projects($ws)[$task->project_id] ?? null);
        $this->tell($task, '📌 <b>'.__('Новая задача').'</b>'."\n".e($task->title).($task->due_date ? "\n📅 ".$task->due_date->format('d.m.Y') : '')."\n🔌 API: ".e($t->name));

        return response()->json(['data' => Present::task($task)], 201);
    }

    public function taskUpdate(Request $r, int $id)
    {
        $ws = $this->company($r);
        $task = $this->taskOr404($r, $id);
        $d = $this->taskData($r->validate($this->taskRules($ws, false, $this->token($r)->allowedObjects()) + [
            'status' => ['sometimes', Rule::in(array_keys(WorkController::STATUSES))], 'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]));
        $before = $task->assignee_id;
        $task->fill($d);
        if (($d['status'] ?? null) === 'done' && $task->getOriginal('status') !== 'done') {
            $task->fill(['progress' => 100, 'done_at' => now()]);
        }
        $task->save();
        $task->load('assignee');
        if ($task->wasChanged()) {
            $this->audit($r, 'task.edit', 'Задача: '.$task->title.' · '.WorkController::STATUSES[$task->status].' · '.$task->progress.'%', StateWriter::projects($ws)[$task->project_id] ?? null);
        }
        if ($task->assignee_id && $task->assignee_id !== $before) {
            $this->tell($task, '📌 <b>'.__('Новая задача').'</b>'."\n".e($task->title).($task->due_date ? "\n📅 ".$task->due_date->format('d.m.Y') : ''));
        }

        return response()->json(['data' => Present::task($task)]);
    }

    /** Исполнителю — в Telegram, как при постановке задачи в программе */
    private function tell(Task $task, string $html): void
    {
        $u = $task->assignee;
        if (! $u || ! $u->telegram_chat_id || ! Telegram::enabled()) {
            return;
        }
        try {
            Telegram::message($u->telegram_chat_id, $html, [[['text' => __('Открыть'), 'url' => url('/company/tasks/'.$task->id)]]]);
        } catch (\Throwable) {
        }
    }
}
