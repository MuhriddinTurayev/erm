<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\BuyerLink;
use App\Models\LeadInbox;
use App\Models\ObjectMedia;
use App\Services\Apartments;
use App\Services\Inventory;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Продажи квартир по каждому объекту отдельно: шахматка, бронь, продажа, цена, рассрочка,
 * приём оплат (сразу в бухгалтерию объекта), просрочки, кабинет покупателя, 3D для клиента.
 * Данные — те же, что в программе (шахматка в /crm видит все изменения).
 */
class SalesController extends ErpController
{
    public const PLANS = ['Полная оплата', 'Рассрочка 6 мес', 'Рассрочка 12 мес', 'Рассрочка 24 мес', 'Рассрочка 36 мес', 'Ипотека'];

    public const STATUSES = ['free' => 'Свободна', 'booked' => 'Бронь', 'sold' => 'Продана', 'reserve' => 'Не в продаже'];

    private function canSell(): void
    {
        $u = auth()->user();
        abort_unless($u->canEdit() && in_array($u->role, ['admin', 'manager', 'accountant', 'cashier'], true), 403, __('Нет доступа к этому разделу'));
    }

    public function index(Request $request)
    {
        $ws = $this->ws();
        $projects = $this->projects();
        abort_if(! $projects, 404, __('Объектов нет'));
        $pid = (string) $request->query('o', array_key_first($projects));
        $this->checkProject($pid);
        $p = StateWriter::project($ws, $pid);
        $apts = Apartments::list($p);
        $f = $request->only(['rooms', 'status', 'q']);
        $today = $ws->localNow()->toDateString();
        foreach ($apts as &$a) {
            $a['overdue'] = in_array($a['status'], ['sold', 'booked'], true) ? Apartments::overdue($a, $today) : 0;
            $a['next'] = in_array($a['status'], ['sold', 'booked'], true) ? Apartments::nextPayment($a) : null;
            $a['note'] = (string) (($p['apts'][$a['id']]['note'] ?? ''));
        }
        unset($a);
        $deals = array_values(array_filter($apts, fn ($a) => in_array($a['status'], ['sold', 'booked'], true)));
        $payments = [];
        foreach ($deals as $a) {
            foreach ($a['payments'] as $x) {
                $payments[] = $x + ['apt' => $a['num'], 'buyer' => $a['buyer']];
            }
        }
        usort($payments, fn ($x, $y) => strcmp($y['date'] ?? '', $x['date'] ?? ''));
        $month = substr($today, 0, 7);

        $media = ObjectMedia::of($ws->id)->where('project_id', $pid)->whereIn('kind', ['interior', 'pano', 'plan'])->orderBy('sort')->orderBy('id')->get();

        return view('company.sales', [
            'ws' => $ws, 'projects' => $projects, 'pid' => $pid, 'p' => $p, 'apts' => $apts, 'f' => $f, 'cur' => \App\Services\CompanyStats::currency($ws),
            's' => ObjectController::summary($p), 'deals' => $deals, 'payments' => array_slice($payments, 0, 12),
            'monthIn' => array_sum(array_map(fn ($x) => str_starts_with($x['date'] ?? '', $month) ? (float) $x['amount'] : 0, $payments)),
            'overdueList' => array_values(array_filter($deals, fn ($a) => $a['overdue'] > 0)),
            'leads' => LeadInbox::where('workspace_id', $ws->id)->latest('id')->limit(8)->get(),
            'media' => $media, 'types' => ObjectController::layouts($p), 'ready' => Inventory::ready($p),
            'links' => BuyerLink::where('workspace_id', $ws->id)->where('project_id', $pid)->pluck('token', 'apt_id'),
            'canSell' => auth()->user()->canEdit() && in_array(auth()->user()->role, ['admin', 'manager', 'accountant', 'cashier'], true),
        ]);
    }

    /** Объект ещё готовится к продаже: бронировать и продавать может только руководитель */
    private function guardReady(array $p, string $status): ?string
    {
        if (in_array($status, ['sold', 'booked'], true) && ! Inventory::ready($p) && ! auth()->user()->isAdmin()) {
            return __('Объект ещё готовится к продаже. Бронь и продажа откроются, когда администратор объектов выставит его на продажу.');
        }

        return null;
    }

    /** Изменить квартиру: статус, покупатель, цена, условия */
    public function save(Request $request, string $pid, string $aptId)
    {
        $this->canSell();
        $this->checkProject($pid);
        $d = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::STATUSES))], 'buyer' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'],
            'price' => ['nullable', 'numeric', 'min:0'], 'plan' => ['nullable', Rule::in(self::PLANS)], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        if (in_array($d['status'], ['sold', 'booked'], true) && empty($d['buyer'])) {
            return back()->withErrors(['buyer' => __('Укажите покупателя')])->withInput();
        }
        $ws = $this->ws();
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        $a = Apartments::find($p, $aptId) ?? abort(404);
        if ($a['status'] !== $d['status'] && ($err = $this->guardReady($p, $d['status']))) {
            return back()->withErrors(['buyer' => $err])->withInput()->with('open', $aptId);
        }
        StateWriter::mutate($ws->id, function (array &$data) use ($pid, $aptId, $d, $a) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') !== $pid) {
                    continue;
                }
                $p['apts'] = (array) ($p['apts'] ?? []);
                $o = (array) ($p['apts'][$aptId] ?? []);
                $o['status'] = $d['status'];
                $o['buyer'] = trim((string) ($d['buyer'] ?? ''));
                $o['phone'] = trim((string) ($d['phone'] ?? ''));
                $o['plan'] = (string) ($d['plan'] ?? '');
                $o['note'] = (string) ($d['note'] ?? '');
                $price = (float) ($d['price'] ?? 0);
                if ($price > 0 && abs($price - $a['basePrice']) > 0.5) {
                    $o['price'] = $price;
                } else {
                    unset($o['price']);
                }
                $o['payments'] = array_values((array) ($o['payments'] ?? []));
                $p['apts'][$aptId] = $o;
            }
        });
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $request->user()->id, 'project' => $this->projects()[$pid] ?? null, 'action' => 'apt.edit',
            'summary' => __('Кв.').' '.$a['num'].': '.self::STATUSES[$d['status']].($d['buyer'] ? ' · '.$d['buyer'] : '').(! empty($d['price']) ? ' · '.number_format($d['price'], 0, '.', ' ') : '')]);

        return back()->with('ok', __('Сохранено'))->with('open', $aptId);
    }

    /** Принять оплату: проводится поступлением в бухгалтерии объекта */
    public function pay(Request $request, string $pid, string $aptId)
    {
        $this->canSell();
        $this->checkProject($pid);
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'date' => ['required', 'date'], 'way' => ['nullable', 'string', 'max:40']]);
        $ws = $this->ws();
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        $a = Apartments::find($p, $aptId) ?? abort(404);
        if ($a['status'] === 'reserve') {
            return back()->withErrors(['amount' => __('Квартира не в продаже — сначала поставьте бронь или продажу')])->with('open', $aptId);
        }
        if ($a['status'] === 'free' && ($err = $this->guardReady($p, 'booked'))) {   // первая оплата ставит бронь
            return back()->withErrors(['amount' => $err])->with('open', $aptId);
        }
        $payId = Str::lower(Str::random(8));
        StateWriter::mutate($ws->id, function (array &$data) use ($pid, $aptId, $d, $a, $payId) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') !== $pid) {
                    continue;
                }
                $txId = Str::lower(Str::random(8));
                $p['tx'] = (array) ($p['tx'] ?? []);
                $p['tx'][] = ['id' => $txId, 'date' => $d['date'], 'type' => 'in', 'cat' => $a['rooms'] ? 'Продажа квартир' : 'Продажа коммерции', 'amount' => (float) $d['amount'],
                    'who' => $a['buyer'], 'desc' => 'Оплата кв. №'.$a['num'].(! empty($d['way']) ? ' · '.$d['way'] : ''), 'link' => ['kind' => 'apt', 'id' => $aptId, 'payId' => $payId]];
                $o = (array) ($p['apts'][$aptId] ?? []);
                if (empty($o['status']) || $o['status'] === 'free') {
                    $o['status'] = 'booked';
                }
                $o['payments'] = array_values((array) ($o['payments'] ?? []));
                $o['payments'][] = ['id' => $payId, 'date' => $d['date'], 'amount' => (float) $d['amount'], 'txId' => $txId];
                $p['apts'][$aptId] = $o;
            }
        });
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $request->user()->id, 'project' => $this->projects()[$pid] ?? null, 'action' => 'apt.add',
            'summary' => 'Оплата кв. №'.$a['num'].' +'.number_format($d['amount'], 0, '.', ' ').($a['buyer'] ? ' · '.$a['buyer'] : '')]);

        return back()->with('ok', __('Оплата проведена'))->with('open', $aptId);
    }

    public function payDelete(Request $request, string $pid, string $aptId, string $payId)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->role === 'accountant', 403);
        $this->checkProject($pid);
        StateWriter::mutate($this->ws()->id, function (array &$data) use ($pid, $aptId, $payId) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') !== $pid) {
                    continue;
                }
                $o = (array) ($p['apts'][$aptId] ?? []);
                $tx = collect($o['payments'] ?? [])->firstWhere('id', $payId)['txId'] ?? null;
                $o['payments'] = array_values(array_filter((array) ($o['payments'] ?? []), fn ($x) => ($x['id'] ?? '') !== $payId));
                $p['apts'][$aptId] = $o;
                $p['tx'] = array_values(array_filter((array) ($p['tx'] ?? []), fn ($t) => ($t['id'] ?? '') !== $tx));
            }
        });
        AuditLog::create(['workspace_id' => $this->ws()->id, 'user_id' => $request->user()->id, 'action' => 'apt.del', 'summary' => 'Удалена оплата по квартире '.$aptId]);

        return back()->with('ok', __('Оплата удалена'))->with('open', $aptId);
    }

    /** Ссылка на кабинет покупателя */
    public function buyerLink(Request $request, string $pid, string $aptId)
    {
        $this->canSell();
        $this->checkProject($pid);
        $ws = $this->ws();
        try {
            $bl = \App\Services\Deals::link($ws, $pid, $aptId);
        } catch (\DomainException $e) {
            return back()->withErrors(['buyer' => $e->getMessage()])->with('open', $aptId);
        }

        return back()->with('ok', __('Ссылка для покупателя: :u', ['u' => $bl->url()]))->with('open', $aptId);
    }
}
