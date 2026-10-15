@extends('layouts.panel')
@section('title', __('Кассы и счета'))
@php
  $u = auth()->user(); $admin = $u->isAdmin(); $cur = \App\Services\CompanyStats::currency($ws);
  $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c);
  $kinds = ['cash' => __('Наличные'), 'bank' => __('Банк'), 'card' => __('Карта')];
@endphp
@section('actions')
@if ($u->canEdit())
<button class="btn btn-s" data-open="dlg-op" data-fill='{"type":"in"}'>{!! ic('arrow-down-left') !!}{{ __('Приход') }}</button>
<button class="btn btn-s" data-open="dlg-op" data-fill='{"type":"out"}'>{!! ic('arrow-up-right') !!}{{ __('Расход') }}</button>
<button class="btn btn-s" data-open="dlg-pay">{!! ic('hand-coins') !!}{{ __('Зарплата') }}</button>
<button class="btn btn-p btn-s" data-open="dlg-tr">{!! ic('arrow-left-right') !!}{{ __('Перевод / обмен') }}</button>
@endif
@endsection
@section('content')
<div class="desk-grid">
  <div class="desk total">
    <div class="d-t">{{ __('Всего во всех кассах') }}</div>
    <div class="d-v" title="{{ money_s($total, $cur) }}">{{ money_f($total, $cur) }}</div>
    <div class="d-s">1 USD = {{ number_format($rate, 2, '.', ' ') }} {{ __(['UZS' => 'сум', 'TJS' => 'смн', 'RUB' => '₽'][$base] ?? $base) }} @if ($ws->usd_rate > 0)({{ __('курс компании') }})@else({{ __('курс ЦБ') }})@endif</div>
  </div>
  @foreach ($desks as $d)
  <a class="desk {{ (string) ($f['desk'] ?? '') === (string) $d->id ? 'on' : '' }}" href="?desk={{ $d->id }}">
    <div class="d-t"><span>{!! ic($d->kind === 'bank' ? 'landmark' : ($d->kind === 'card' ? 'credit-card' : 'wallet')) !!} {{ __($d->name) }}</span><span class="cur {{ $d->currency === 'USD' ? 'usd' : '' }}">{{ $d->currency }}</span></div>
    <div class="d-v {{ ($balances[$d->id] ?? 0) < 0 ? 'out-c' : '' }}">{{ $M($balances[$d->id] ?? 0, $d->currency) }}</div>
    <div class="d-s">{{ $kinds[$d->kind] ?? '' }}@if ($d->project_id) · {{ $projects[$d->project_id] ?? '' }}@endif @if ($d->currency === 'USD') · ≈ {{ money_s(($balances[$d->id] ?? 0) * $rate, $cur) }}@endif</div>
  </a>
  @endforeach
</div>

<div class="card">
  <div class="toolbar" style="margin-bottom:12px">
    <div class="sum-line grow">
      <span>{{ __('Сегодня пришло') }}: <b class="in-c">+{{ money_s($day->i ?? 0, $cur) }}</b></span>
      <span>{{ __('Сегодня ушло') }}: <b class="out-c">−{{ money_s($day->o ?? 0, $cur) }}</b></span>
    </div>
    @if ($admin)
    <button class="btn btn-s btn-ghost" data-open="dlg-desks">{!! ic('settings') !!}{{ __('Кассы') }}</button>
    <button class="btn btn-s btn-ghost" data-open="dlg-cats">{!! ic('gauge') !!}{{ __('Статьи и лимиты') }}</button>
    <button class="btn btn-s btn-ghost" data-open="dlg-rate">{!! ic('coins') !!}{{ __('Курс USD') }}</button>
    @endif
  </div>
  <form method="get" class="toolbar">
    @if (! empty($f['desk']))<input type="hidden" name="desk" value="{{ $f['desk'] }}">@endif
    <select class="input" name="type" onchange="this.form.submit()"><option value="">{{ __('Все операции') }}</option><option value="in" @selected(($f['type'] ?? '') === 'in')>{{ __('Приход') }}</option><option value="out" @selected(($f['type'] ?? '') === 'out')>{{ __('Расход') }}</option><option value="transfer" @selected(($f['type'] ?? '') === 'transfer')>{{ __('Переводы') }}</option></select>
    <select class="input" name="cat" onchange="this.form.submit()"><option value="">{{ __('Все статьи') }}</option>@foreach ($cats as $c)<option value="{{ $c->id }}" @selected((string) ($f['cat'] ?? '') === (string) $c->id)>{{ __($c->name) }}</option>@endforeach</select>
    <select class="input" name="project" onchange="this.form.submit()"><option value="">{{ __('Все объекты') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected(($f['project'] ?? '') === $pid)>{{ $pn }}</option>@endforeach</select>
    <input class="input" type="date" name="from" value="{{ $f['from'] ?? '' }}" title="{{ __('С') }}"><input class="input" type="date" name="to" value="{{ $f['to'] ?? '' }}" title="{{ __('По') }}">
    <input class="input grow" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('Поиск по описанию или сотруднику') }}">
    <button class="btn btn-s">{!! ic('search') !!}</button>
    @if (array_filter($f))<a class="btn btn-s btn-ghost" href="/company/cash">{{ __('Сбросить') }}</a>@endif
  </form>
  @if ($ops->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Касса') }}</th><th>{{ __('Статья') }}</th><th>{{ __('Описание') }}</th><th>{{ __('Объект') }}</th><th class="n">{{ __('Сумма') }}</th><th></th></tr></thead><tbody>
  @foreach ($ops as $o)
    <tr>
      <td class="nowrap">{{ $o->date->format('d.m.Y') }}<br><small>{{ $o->user?->name }}</small></td>
      <td>{{ __($o->desk?->name ?? '') }}</td>
      <td>@if ($o->transfer_key)<span class="badge b-info">{{ __('Перевод') }}</span>@else{{ $o->category ? __($o->category->name) : '—' }}@endif</td>
      <td>{{ sys_tr($o->note) }}@if ($o->employee)<br><small>{!! ic('user', '', 13) !!} {{ $o->employee }}</small>@endif @if ($o->counterparty)<br><small><a href="/company/parties/{{ $o->counterparty_id }}">{{ $o->counterparty->name }}</a></small>@endif @if ($o->contract)<small> · <a href="/company/contracts/{{ $o->contract_id }}">{{ __('дог.') }} {{ $o->contract->number }}</a></small>@endif</td>
      <td>{{ $o->project_id ? ($projects[$o->project_id] ?? '—') : '—' }}</td>
      <td class="n"><b class="{{ $o->type === 'in' ? 'in-c' : 'out-c' }}">{{ $o->type === 'in' ? '+' : '−' }}{{ $M($o->amount, $o->desk?->currency ?? $base) }}</b>@if ($o->desk?->currency === 'USD')<br><small>≈ {{ money_s($o->amount_base, $cur) }}</small>@endif</td>
      <td class="right">@if ($admin || ($o->user_id === $u->id && $o->created_at->gt(now()->subDay())))<form method="post" action="/company/cash/{{ $o->id }}" data-confirm="{{ $o->transfer_key ? __('Удалить перевод целиком (обе операции)?') : __('Удалить операцию?') }}">@csrf @method('delete')<button class="icon-btn" title="{{ __('Удалить') }}">{!! ic('trash-2') !!}</button></form>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
  <div class="mt">{{ $ops->links() }}</div>
  @else
  <div class="empty">{!! ic('wallet') !!}<div>{{ __('Операций пока нет. Нажмите «Приход» или «Расход».') }}</div></div>
  @endif
</div>

@php $lim = array_filter($limits, fn ($r) => $r['limit'] > 0); @endphp
<div class="grid {{ $lim ? 'g2' : '' }}" style="margin-top:20px;align-items:start">
@if ($lim)
<div class="card">
  <div class="card-h"><h3>{!! ic('gauge') !!}{{ __('Лимиты по статьям в этом месяце') }}</h3></div>
  @foreach ($lim as $r)
    <div class="hbar"><div class="hb-t"><span>{{ __($r['cat']->name) }}</span><b class="{{ $r['pct'] >= 100 ? 'out-c' : '' }}">{{ money_s($r['spent'], $cur) }} / {{ money_s($r['limit'], $cur) }} · {{ $r['pct'] }}%</b></div>
      <div class="hb-r"><i style="width:{{ min(100, $r['pct']) }}%;background:{{ $r['pct'] >= 100 ? 'var(--bad)' : ($r['pct'] >= 80 ? 'var(--warn)' : 'var(--ok)') }}"></i></div></div>
  @endforeach
</div>
@endif
@if (auth()->user()->seesMoney())
  @include('partials.insights', ['topic' => 'cash', 'title' => __('ИИ-анализ касс и расходов')])
@endif
</div>

{{-- ===== операция ===== --}}
<dialog class="modal" id="dlg-op"><form method="post" action="/company/cash">@csrf
  <div class="modal-h"><h3>{!! ic('wallet') !!}{{ __('Операция по кассе') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Тип') }}</span><select name="type"><option value="in">{{ __('Приход') }}</option><option value="out">{{ __('Расход') }}</option></select></label>
    <label class="field"><span>{{ __('Касса') }}</span><select name="cash_desk_id" required>@foreach ($desks as $d)<option value="{{ $d->id }}" @selected((string) ($f['desk'] ?? '') === (string) $d->id)>{{ __($d->name) }} ({{ $d->currency }})</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Сумма') }}</span><input name="amount" type="number" step="0.01" min="0.01" required inputmode="decimal"></label>
    <label class="field"><span>{{ __('Дата') }}</span><input name="date" type="date" value="{{ $ws->localNow()->toDateString() }}" required></label>
    <label class="field"><span>{{ __('Статья') }}</span><select name="category_id"><option value="">—</option>@foreach ($cats as $c)<option value="{{ $c->id }}" data-kind="{{ $c->kind }}">{{ __($c->name) }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">{{ __('Общие расходы компании') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Контрагент') }}</span><select name="counterparty_id"><option value="">—</option>@foreach ($counterparties as $c)<option value="{{ $c->id }}" @disabled($c->status === 'blocked')>{{ $c->name }}{{ $c->status === 'blocked' ? ' — '.__('заблокирован') : '' }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('По договору') }}</span><select name="contract_id"><option value="">—</option>@foreach ($contracts as $c)<option value="{{ $c->id }}">№{{ $c->number }} · {{ $c->counterparty?->name }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Описание') }}</span><input name="note" maxlength="300"></label>
    <small class="muted full">{{ __('Операция с объектом сразу попадает в бухгалтерию объекта в программе.') }}</small>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Провести') }}</button></div>
</form></dialog>

{{-- ===== зарплата ===== --}}
<dialog class="modal" id="dlg-pay"><form method="post" action="/company/cash">@csrf<input type="hidden" name="type" value="out"><input type="hidden" name="payroll" value="1">
  <div class="modal-h"><h3>{!! ic('hand-coins') !!}{{ __('Выплата зарплаты из кассы') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Сотрудник') }}</span><input name="employee" list="emp-list" required maxlength="120" placeholder="{{ __('Фамилия Имя') }}"><datalist id="emp-list">@foreach ($staff as $s)<option value="{{ $s->name }}">@endforeach</datalist></label>
    <label class="field"><span>{{ __('Касса') }}</span><select name="cash_desk_id" required>@foreach ($desks as $d)<option value="{{ $d->id }}">{{ __($d->name) }} ({{ $d->currency }})</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Сумма') }}</span><input name="amount" type="number" step="0.01" min="0.01" required></label>
    <label class="field"><span>{{ __('Дата') }}</span><input name="date" type="date" value="{{ $ws->localNow()->toDateString() }}" required></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">{{ __('Офис') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('За что') }}</span><input name="note" maxlength="300" placeholder="{{ __('Зарплата за октябрь, аванс, премия…') }}"></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Выплатить') }}</button></div>
</form></dialog>

{{-- ===== перевод и обмен ===== --}}
<dialog class="modal" id="dlg-tr"><form method="post" action="/company/cash/transfer" id="tr-form">@csrf
  <div class="modal-h"><h3>{!! ic('arrow-left-right') !!}{{ __('Перевод между кассами и обмен валюты') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    @php $deskObj = fn ($d) => $d->project_id ? ($projects[$d->project_id] ?? '') : ''; $q = fn ($x) => str_starts_with($x, '«') ? $x : '«'.$x.'»';
      $deskTag = fn ($d) => $deskObj($d) && ! str_contains(__($d->name), $deskObj($d)) ? ' · '.__('касса объекта').' '.$q($deskObj($d)) : ''; @endphp
    <label class="field"><span>{{ __('Откуда (списать)') }}</span><select name="from" required>@foreach ($desks as $d)<option value="{{ $d->id }}" data-cur="{{ $d->currency }}" data-name="{{ __($d->name) }}" data-obj="{{ $deskObj($d) }}">{{ __($d->name) }}{{ $deskTag($d) }} ({{ $M($balances[$d->id] ?? 0, $d->currency) }})</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Куда (зачислить)') }} <button type="button" class="btn btn-s btn-ghost" data-swap title="{{ __('Поменять местами') }}" style="height:22px;padding:0 6px;margin-left:4px">⇅</button></span><select name="to" required>@foreach ($desks as $d)<option value="{{ $d->id }}" data-cur="{{ $d->currency }}" data-name="{{ __($d->name) }}" data-obj="{{ $deskObj($d) }}" @selected($loop->index === 1)>{{ __($d->name) }}{{ $deskTag($d) }} ({{ $M($balances[$d->id] ?? 0, $d->currency) }})</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Сумма списания') }}</span><input name="amount" type="number" step="0.01" min="0.01" required></label>
    <label class="field" id="tr-rate"><span>{{ __('Курс сделки, 1 USD =') }}</span><input name="rate" type="number" step="0.0001" min="0" value="{{ $rate }}"></label>
    <label class="field"><span>{{ __('Дата') }}</span><input name="date" type="date" value="{{ $ws->localNow()->toDateString() }}" required></label>
    <label class="field"><span>{{ __('Комментарий') }}</span><input name="note" maxlength="300"></label>
    <div class="full alert alert-info" style="margin:0" id="tr-res">{!! ic('arrow-left-right') !!}<div></div></div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Перевести') }}</button></div>
</form></dialog>

@if ($admin)
{{-- ===== кассы ===== --}}
<dialog class="modal wide" id="dlg-desks">
  <div class="modal-h"><h3>{!! ic('settings') !!}{{ __('Кассы и счета') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b">
    @foreach ($desks->push(new \App\Models\CashDesk(['currency' => $base, 'kind' => 'cash'])) as $d)
    <form method="post" action="/company/cash/desks" class="form" style="grid-template-columns:2fr 1fr 1fr 1.4fr 1fr auto;align-items:end;margin-bottom:12px">@csrf
      <input type="hidden" name="id" value="{{ $d->id }}">
      <label class="field"><span>{{ $d->id ? __('Название') : __('Новая касса') }}</span><input name="name" value="{{ $d->name }}" required maxlength="80"></label>
      <label class="field"><span>{{ __('Вид') }}</span><select name="kind">@foreach ($kinds as $k => $n)<option value="{{ $k }}" @selected($d->kind === $k)>{{ $n }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Валюта') }}</span><select name="currency" @disabled($d->id)>@foreach ([$base, 'USD'] as $c)<option @selected($d->currency === $c)>{{ $c }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Касса объекта') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected($d->project_id === $pid)>{{ $pn }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Начальный остаток') }}</span><input name="opening" type="number" step="0.01" value="{{ $d->opening }}"></label>
      <div class="flex"><button class="btn btn-s btn-p">{!! ic('check') !!}</button>@if ($d->id)<button class="btn btn-s" name="archive" value="1" title="{{ __('В архив') }}">{!! ic('trash-2') !!}</button>@endif</div>
    </form>
    @endforeach
    <small class="muted">{{ __('Касса объекта нужна для взаиморасчётов: если касса одного объекта платит за другой, программа покажет, кто кому должен.') }}</small>
  </div>
</dialog>

{{-- ===== статьи и лимиты ===== --}}
<dialog class="modal wide" id="dlg-cats"><form method="post" action="/company/cash/categories">@csrf
  <div class="modal-h"><h3>{!! ic('gauge') !!}{{ __('Статьи и месячные лимиты') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b">
    <div class="tbl-wrap"><table class="tbl lines-tbl"><thead><tr><th>{{ __('Статья') }}</th><th>{{ __('Тип') }}</th><th>{{ __('Лимит в месяц') }}, {{ __($cur) }}</th><th>{{ __('Вкл.') }}</th></tr></thead><tbody>
    @foreach (\App\Models\FinCategory::of($ws->id)->orderBy('kind')->orderBy('sort')->get() as $c)
      <tr><td><input class="input" name="cats[{{ $c->id }}][name]" value="{{ $c->name }}"></td><td>{{ $c->kind === 'in' ? __('Приход') : __('Расход') }}</td>
        <td>@if ($c->kind === 'out')<input class="input" type="number" min="0" step="1" name="cats[{{ $c->id }}][limit]" value="{{ $c->month_limit > 0 ? (int) $c->month_limit : '' }}" placeholder="{{ __('без лимита') }}">@endif</td>
        <td><input type="checkbox" name="cats[{{ $c->id }}][on]" value="1" @checked($c->is_active)></td></tr>
    @endforeach
      <tr><td><input class="input" name="new_name" placeholder="{{ __('Новая статья') }}"></td><td><select class="input" name="new_kind"><option value="out">{{ __('Расход') }}</option><option value="in">{{ __('Приход') }}</option></select></td><td><input class="input" type="number" min="0" name="new_limit"></td><td></td></tr>
    </tbody></table></div>
    <small class="muted">{{ __('Если расход превысит лимит статьи, сотрудник не сможет его провести — только руководитель.') }}</small>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>

{{-- ===== курс ===== --}}
<dialog class="modal" id="dlg-rate"><form method="post" action="/company/cash/rate">@csrf
  <div class="modal-h"><h3>{!! ic('coins') !!}{{ __('Курс доллара') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <div class="full">{{ __('Официальный курс ЦБ сегодня') }}: <b>1 USD = {{ number_format($cbRate, 2, '.', ' ') }}</b></div>
    <label class="field full"><span>{{ __('Свой курс компании (пусто — курс ЦБ)') }}</span><input name="usd_rate" type="number" step="0.0001" min="0" value="{{ $ws->usd_rate > 0 ? $ws->usd_rate : '' }}"></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@endif
@endsection
@push('scripts')
<script>
(() => {
  const f = document.getElementById('tr-form'); if (!f) return;
  const TT = { got: @json(__('Поступит')), objIn: @json(__('В бухгалтерии объекта «:o» — приход: деньги для объекта')), objOut: @json(__('В бухгалтерии объекта «:o» — расход: деньги ушли с объекта')),
    same: @json(__('Выберите разные кассы')), total: @json(__('Общая сумма денег компании не меняется — деньги просто переходят из одной кассы в другую.')) };
  const calc = () => {
    const A = f.from.selectedOptions[0]?.dataset || {}, Bd = f.to.selectedOptions[0]?.dataset || {}, a = A.cur, b = Bd.cur, s = +f.amount.value || 0, r = +f.rate.value || 0;
    document.getElementById('tr-rate').style.display = a !== b ? '' : 'none';
    let got = s; if (a !== b && r) got = a === 'USD' ? s * r : s / r;
    const box = f.querySelector('#tr-res div');
    if (f.from.value === f.to.value) { box.textContent = TT.same; return; }
    const q = x => /^«/.test(x || '') ? x : `«${x}»`;
    const lines = [`${q(A.name)} → ${q(Bd.name)} · ${TT.got}: ${got.toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ${b || ''}`];
    if (A.obj !== Bd.obj) { if (Bd.obj) lines.push('↳ ' + TT.objIn.replace(/«?:o»?/, q(Bd.obj))); if (A.obj) lines.push('↳ ' + TT.objOut.replace(/«?:o»?/, q(A.obj))); }
    lines.push(TT.total);
    box.innerHTML = lines.map(x => `<div>${x.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))}</div>`).join('');
  };
  f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
  f.querySelector('[data-swap]').addEventListener('click', () => { const x = f.from.value; f.from.value = f.to.value; f.to.value = x; calc(); });
  // статьи по типу операции
  const op = document.querySelector('#dlg-op form');
  const sync = () => op.category_id.querySelectorAll('option[data-kind]').forEach(o => o.hidden = o.dataset.kind !== op.type.value);
  op.type.addEventListener('change', sync); sync();
})();
</script>
@endpush
