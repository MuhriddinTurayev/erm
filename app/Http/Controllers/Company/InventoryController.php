<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\Inventory;
use App\Services\Locate;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Перечень помещений объекта и сами объекты: ведут руководитель и администратор объектов.
 * Корпуса, подъезды, этажи, квартиры, планировки, цены; «Выставить на продажу».
 * Продажи (бронь, оплаты) сюда не входят — их ведут менеджеры в разделе «Продажи».
 */
class InventoryController extends ErpController
{
    private function guard(?string $pid = null): ?array
    {
        $u = auth()->user();
        abort_unless($u->canEdit() && $u->managesObjects(), 403, __('Нет доступа к этому разделу'));
        if ($pid === null) {
            return null;
        }
        $this->checkProject($pid);

        return StateWriter::project($this->ws(), $pid) ?? abort(404);
    }

    /** Отпечаток перечня: редактор присылает его обратно, чтобы не затереть чужие изменения */
    public static function rev(array $p): string
    {
        return md5(json_encode($p['inv'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    /** Квартиры с действующей сделкой: [id => №] */
    private static function deals(array $list): array
    {
        $out = [];
        foreach ($list as $a) {
            if (in_array($a['status'], ['sold', 'booked'], true)) {
                $out[$a['id']] = $a['num'];
            }
        }

        return $out;
    }

    /** Изменить данные одного объекта под блокировкой. Ошибки — понятным текстом (422). */
    private function change(string $pid, callable $fn)
    {
        try {
            $res = StateWriter::mutate($this->ws()->id, function (array &$data) use ($pid, $fn) {
                foreach ($data['projects'] as &$p) {
                    if ((string) ($p['id'] ?? '') === $pid) {
                        return $fn($p);
                    }
                }
                throw new \DomainException(__('Объект не найден'));
            });
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + (is_array($res) ? $res : []));
    }

    private function audit(string $pid, string $action, string $summary): void
    {
        AuditLog::create(['workspace_id' => $this->ws()->id, 'user_id' => auth()->id(), 'project' => $this->projects()[$pid] ?? null, 'action' => $action, 'summary' => $summary]);
    }

    /** Сохранить перечень целиком (из редактора) */
    public function save(Request $request, string $pid)
    {
        $this->guard($pid);
        $d = $request->validate(['inv' => ['required', 'array'], 'rev' => ['required', 'string', 'size:32'], 'st' => ['nullable', 'array'], 'st.*' => ['string', 'in:free,reserve']]);
        $stats = null;
        $res = $this->change($pid, function (array &$p) use ($d, &$stats) {
            if (self::rev($p) !== $d['rev']) {
                throw new \DomainException(__('Перечень только что изменил другой сотрудник. Обновите страницу — ваши изменения не сохранены.'));
            }
            $before = Apartments::list($p);
            $deals = self::deals($before);
            $old = Inventory::has($p) ? (array) $p['inv'] : null;
            $clean = Inventory::clean($d['inv'], $deals, $old);
            $p['invPrev'] = $old;   // для «Отменить последнее сохранение»
            $p['inv'] = $clean;
            $p['apts'] = (array) ($p['apts'] ?? []);
            // цена по договору не меняется вслед за прайсом: закрепляем её у квартир со сделкой
            foreach ($before as $a) {
                if (isset($deals[$a['id']]) && empty($p['apts'][$a['id']]['price'])) {
                    $p['apts'][$a['id']]['price'] = $a['price'];
                }
            }
            // «в продаже» / «не в продаже» — только у свободных помещений
            $ids = array_flip(array_column($clean['units'], 'id'));
            foreach ((array) ($d['st'] ?? []) as $id => $st) {
                $id = (string) $id;
                if (! isset($ids[$id]) || isset($deals[$id])) {
                    continue;
                }
                $o = (array) ($p['apts'][$id] ?? []);
                $o['status'] = $st;
                if ($st === 'free' && array_keys($o) === ['status']) {
                    unset($p['apts'][$id]);
                } else {
                    $p['apts'][$id] = $o;
                }
            }
            $stats = ['units' => count($clean['units']), 'types' => count($clean['types']), 'blocks' => count($clean['blocks'])];

            return ['rev' => self::rev($p), 'dups' => Inventory::duplicates($clean)];
        });
        if ($stats) {
            $this->audit($pid, 'inv.save', __('Перечень помещений: корпусов :b, планировок :t, помещений :u', ['b' => $stats['blocks'], 't' => $stats['types'], 'u' => $stats['units']]));
        }

        return $res;
    }

    /** Завести перечень из рассчитанных квартир (номера и сделки сохраняются) */
    public function convert(string $pid)
    {
        $this->guard($pid);
        $res = $this->change($pid, function (array &$p) {
            if (Inventory::has($p)) {
                throw new \DomainException(__('Перечень помещений уже заведён'));
            }
            $p['inv'] = Inventory::fromGenerator($p, $this->ws()->locale ?: null);

            return ['rev' => self::rev($p)];
        });
        if ($res->getStatusCode() !== 200) {
            return $res;
        }
        // у рассчитанных квартир корпус без имени (''), в перечне он — b1: привязка 3D-модели переезжает вместе с ним
        Locate::renameBlock($this->ws()->id, $pid, '', 'b1');
        $this->audit($pid, 'inv.save', __('Перечень помещений заведён из параметров здания'));

        return $res;
    }

    /**
     * Одна квартира прямо из привязки к 3D-модели: число комнат и площадь (планировка подбирается по ним).
     * Квартиры ещё рассчитаны по параметрам здания — сначала заводим перечень (номера и сделки сохраняются).
     */
    public function unit(Request $request, string $pid, string $uid)
    {
        $p0 = $this->guard($pid);
        $d = $request->validate(['rooms' => ['required', 'integer', 'min:1', 'max:9'], 'area' => ['nullable', 'numeric', 'min:5', 'max:5000']]);
        $a = Apartments::find($p0, $uid) ?? abort(404);
        if (in_array($a['status'], ['sold', 'booked'], true)) {
            return response()->json(['error' => __('По кв. №:n есть бронь или продажа — её планировку менять нельзя.', ['n' => $a['num']])], 422);
        }
        if (($a['kind'] ?? 'flat') !== 'flat') {
            return response()->json(['error' => __('Это не квартира — у неё нет комнат')], 422);
        }
        $converted = false;
        $res = $this->change($pid, function (array &$p) use ($uid, $d, &$converted) {
            if (! Inventory::has($p)) {
                $p['inv'] = Inventory::fromGenerator($p, $this->ws()->locale ?: null);
                $converted = true;
            }
            $inv = (array) $p['inv'];
            $types = array_values(array_filter((array) ($inv['types'] ?? []), fn ($t) => ($t['kind'] ?? 'flat') === 'flat' && (int) ($t['rooms'] ?? 0) === (int) $d['rooms']));
            foreach ($inv['units'] as &$u) {
                if ((string) $u['id'] !== $uid) {
                    continue;
                }
                $area = isset($d['area']) ? round((float) $d['area'], 2) : (float) ($u['area'] ?? 0);
                // планировка с тем же числом комнат и ближайшей площадью; другой нет — квартира без планировки
                usort($types, fn ($x, $y) => abs((float) $x['area'] - $area) <=> abs((float) $y['area'] - $area));
                $u['rooms'] = (int) $d['rooms'];
                $u['area'] = $area;
                $u['type'] = $types && abs((float) $types[0]['area'] - $area) <= max(0.5, $area * 0.02) ? (string) $types[0]['id'] : null;
                if (! $u['type']) {
                    unset($u['type']);
                }
            }
            unset($u);
            $p['inv'] = Inventory::clean($inv, self::deals(Apartments::list($p)), $inv);

            return ['rev' => self::rev($p)];
        });
        if ($res->getStatusCode() !== 200) {
            return $res;
        }
        if ($converted) {
            Locate::renameBlock($this->ws()->id, $pid, '', 'b1');
        }
        $this->audit($pid, 'inv.save', __('Кв. №:n: комнат :r, площадь :a м²', ['n' => $a['num'], 'r' => $d['rooms'], 'a' => $d['area'] ?? $a['area']]));
        $fresh = Apartments::find(StateWriter::project($this->ws()->fresh(), $pid) ?? [], $uid);   // данные компании только что переписаны — читаем заново
        $data = $res->getData(true);

        return response()->json($data + ['converted' => $converted, 'unit' => $fresh ? ['r' => (int) $fresh['rooms'], 'a' => (float) $fresh['area'], 't' => $fresh['type'] ?? null, 'b' => (string) ($fresh['blockId'] ?? '')] : null], $res->getStatusCode());
    }

    /** Вернуть перечень, каким он был до последнего сохранения */
    public function undo(string $pid)
    {
        $this->guard($pid);
        $res = $this->change($pid, function (array &$p) {
            $prev = $p['invPrev'] ?? null;
            if (! is_array($prev) || ! $prev) {
                throw new \DomainException(__('Отменять нечего'));
            }
            $clean = Inventory::clean($prev, self::deals(Apartments::list($p)), (array) ($p['inv'] ?? []));
            $p['invPrev'] = Inventory::has($p) ? $p['inv'] : null;
            $p['inv'] = $clean;

            return ['rev' => self::rev($p)];
        });
        $this->audit($pid, 'inv.save', __('Перечень помещений: отменено последнее сохранение'));

        return $res;
    }

    /** Выставить объект на продажу / снять (вернуть в подготовку) */
    public function ready(Request $request, string $pid)
    {
        $this->guard($pid);
        $on = $request->boolean('on');
        $res = $this->change($pid, function (array &$p) use ($on) {
            if (! Inventory::has($p)) {
                throw new \DomainException(__('Сначала заведите перечень помещений'));
            }
            $p['inv']['ready'] = $on ? $this->ws()->localNow()->toDateString() : false;

            return ['rev' => self::rev($p)];
        });
        $this->audit($pid, 'inv.ready', $on ? __('Объект выставлен на продажу') : __('Объект снят с продажи и возвращён в подготовку'));

        return $res;
    }

    /** Новый объект (руководитель или администратор объектов) */
    public function create(Request $request)
    {
        $this->guard();
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:200']]);
        $ws = Workspace::with('plan')->find($this->ws()->id);
        $max = $ws->maxObjects();
        if ($max && Workspace::countObjects($ws->projects()) >= $max) {
            return back()->withErrors(['name' => __('По вашему тарифу можно вести не больше :n объектов. Перейдите на тариф выше.', ['n' => $max])])->withInput();
        }
        $pid = Str::lower(Str::random(8));
        StateWriter::mutate($ws->id, function (array &$data) use ($pid, $d, $ws) {
            // остальные разделы объекта (смета, график, сотрудники…) программа дополнит сама при открытии
            $data['projects'][] = ['id' => $pid, 'name' => trim($d['name']), 'address' => trim((string) ($d['address'] ?? '')), 'status' => 'Проектирование',
                'startDate' => $ws->localNow()->toDateString(), 'apts' => [], 'tx' => [], 'stages' => []];
        });
        // администратору объектов с доступом к отдельным объектам — открыть и новый
        $u = $request->user();
        if ($u->allowedObjects() !== null) {
            $u->forceFill(['object_ids' => array_values(array_unique(array_merge($u->allowedObjects(), [$pid])))])->save();
        }
        $this->audit($pid, 'project.add', __('Новый объект: :n', ['n' => $d['name']]));

        return redirect('/company/objects/'.$pid.'?tab=units')->with('ok', __('Объект создан. Заведите корпуса, подъезды и квартиры.'));
    }

    /**
     * Удалить объект целиком (руководитель): подтверждение — название объекта.
     * Нельзя, если по нему есть брони и продажи или деньги и склад (касса, расходы, заявки на оплату, договоры, снабжение, движение
     * материалов) — история компании не должна ломаться; такие объекты остаются. Удаляются квартиры, 3D-модели, интерьеры, фото.
     */
    public function destroy(Request $request, string $pid)
    {
        abort_unless($request->user()->isAdmin(), 403, __('Удалить объект может только руководитель компании'));
        $p = $this->guard($pid);
        $d = $request->validate(['confirm' => ['required', 'string', 'max:200']]);
        if (mb_strtolower(trim($d['confirm'])) !== mb_strtolower(trim((string) ($p['name'] ?? '')))) {
            return response()->json(['error' => __('Название не совпадает — объект не удалён')], 422);
        }
        $ws = $this->ws();
        if (count(array_filter((array) (json_decode((string) $ws->data, true)['projects'] ?? []), fn ($x) => (string) ($x['id'] ?? '') !== $pid)) < 1) {
            return response()->json(['error' => __('Это единственный объект компании — его нельзя удалить. Создайте новый объект, потом удалите этот.')], 422);
        }
        $why = [];
        if ($n = count(self::deals(Apartments::list($p)))) {
            $why[] = __('брони и продажи: :n', ['n' => $n]);
        }
        // касса объекта в программе (приходы и расходы) и записи панели
        $tables = ['cash_ops' => [__('операции кассы'), count((array) ($p['tx'] ?? []))], 'expenses' => [__('расходы'), 0], 'payment_requests' => [__('заявки на оплату'), 0],
            'contracts' => [__('договоры'), 0], 'supply_requests' => [__('заявки снабжения'), 0], 'stock_moves' => [__('движение материалов'), 0]];
        foreach ($tables as $t => [$name, $n]) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($t, 'project_id')) {
                $n = max($n, DB::table($t)->where('workspace_id', $ws->id)->where('project_id', $pid)->count());
            }
            if ($n) {
                $why[] = $name.': '.$n;
            }
        }
        if ($why) {
            return response()->json(['error' => __('Объект нельзя удалить — по нему уже есть история: :w. Её нельзя терять: отчёты и деньги компании разойдутся.', ['w' => implode(', ', $why)])], 422);
        }
        StateWriter::mutate($ws->id, function (array &$data) use ($pid) {
            $data['projects'] = array_values(array_filter($data['projects'], fn ($x) => (string) ($x['id'] ?? '') !== $pid));
            if ((string) ($data['currentId'] ?? '') === $pid) {
                $data['currentId'] = (string) ($data['projects'][0]['id'] ?? '');
            }
        });
        foreach (\App\Models\ObjectMedia::of($ws->id)->where('project_id', $pid)->get() as $m) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete(array_filter([$m->path, $m->poster, $m->meta['finished'] ?? null]));
            $m->delete();
        }
        \App\Models\BuyerLink::where('workspace_id', $ws->id)->where('project_id', $pid)->delete();
        // сотрудники с доступом к отдельным объектам — убираем удалённый из их списка
        foreach ($ws->users()->whereNotNull('object_ids')->get() as $u) {
            $ids = $u->allowedObjects();
            if ($ids !== null && in_array($pid, $ids, true)) {
                $u->forceFill(['object_ids' => array_values(array_diff($ids, [$pid]))])->save();
            }
        }
        // объекта в списке уже нет — название пишем сами
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => auth()->id(), 'project' => $p['name'] ?? $pid, 'action' => 'project.delete', 'summary' => __('Объект удалён: :n', ['n' => $p['name'] ?? $pid])]);

        return response()->json(['ok' => true, 'to' => '/company/objects']);
    }

    /** Название и адрес объекта */
    public function info(Request $request, string $pid)
    {
        $this->guard($pid);
        $d = $request->validate(['name' => ['required', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:200']]);
        StateWriter::mutate($this->ws()->id, function (array &$data) use ($pid, $d) {
            foreach ($data['projects'] as &$p) {
                if ((string) ($p['id'] ?? '') === $pid) {
                    $p['name'] = trim($d['name']);
                    $p['address'] = trim((string) ($d['address'] ?? ''));
                }
            }
        });
        $this->audit($pid, 'project.edit', __('Объект: :n', ['n' => $d['name']]));

        return back()->with('ok', __('Сохранено'));
    }
}
