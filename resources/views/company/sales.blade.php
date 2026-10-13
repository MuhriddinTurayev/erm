@extends('layouts.panel')
@section('title', __('Продажи квартир'))
@php
  $ST = \App\Http\Controllers\Company\SalesController::STATUSES; $PL = \App\Http\Controllers\Company\SalesController::PLANS;
  $rooms = $f['rooms'] ?? ''; $stF = $f['status'] ?? ''; $q = mb_strtolower(trim($f['q'] ?? ''));
  $match = fn ($a) => ($rooms === '' || (string) $a['rooms'] === (string) $rooms) && ($stF === '' || $a['status'] === $stF || ($stF === 'overdue' && $a['overdue'] > 0))
      && ($q === '' || str_contains(mb_strtolower($a['num'].' '.$a['buyer'].' '.$a['phone']), $q));
  $bySec = collect($apts)->groupBy('section');
  $js = collect($apts)->map(fn ($a) => ['id' => $a['id'], 'num' => apt_num($a['num']), 'section' => $a['section'], 'floor' => $a['floor'], 'rooms' => $a['rooms'], 'area' => $a['area'],
      'price' => $a['price'], 'base' => $a['basePrice'], 'status' => $a['status'], 'buyer' => $a['buyer'], 'phone' => $a['phone'], 'plan' => $a['plan'], 'note' => $a['note'],
      'paid' => $a['paid'], 'overdue' => $a['overdue'], 'next' => $a['next'], 'payments' => $a['payments'], 'link' => isset($links[$a['id']]) ? url('/b/'.$links[$a['id']]) : null])->keyBy('id');
  $int = $interiors->map(fn ($g) => $g->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'kind' => $m->kind])->values());
@endphp
@section('actions')
<form method="get" class="flex"><select class="input" name="o" onchange="this.form.submit()" style="min-width:220px">@foreach ($projects as $id => $n)<option value="{{ $id }}" @selected($id === $pid)>{{ $n }}</option>@endforeach</select></form>
<a class="btn btn-s" href="/company/objects/{{ $pid }}">{!! ic('building-2') !!}{{ __('Объект') }}</a>
@endsection
@section('content')
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('key-round') !!}{{ __('Продано') }}</div><div class="k-v">{{ $s['sold'] }}</div><div class="k-s">{{ __('бронь') }}: {{ $s['booked'] }} · {{ __('свободно') }}: {{ $s['free'] }}</div><div class="prog"><i style="width:{{ $s['apts'] ? round(($s['sold'] + $s['booked']) / $s['apts'] * 100) : 0 }}%"></i></div></div>
  <div class="kpi"><div class="k-l">{!! ic('file-pen-line') !!}{{ __('Сумма сделок') }}</div><div class="k-v">{{ money_s($s['revenue'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('circle-check') !!}{{ __('Получено') }}</div><div class="k-v in-c">{{ money_s($s['paid'], $cur) }}</div><div class="k-s">{{ __('в этом месяце') }}: {{ money_s($monthIn, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('alarm-clock') !!}{{ __('Просрочка покупателей') }}</div><div class="k-v {{ $s['overdue'] ? 'out-c' : '' }}">{{ money_s($s['overdue'], $cur) }}</div><div class="k-s">{{ count($overdueList) }} {{ __('покупателей') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Осталось продать') }}</div><div class="k-v">{{ money_s($s['stock_value'], $cur) }}</div></div>
</div>

<div class="card">
  <form method="get" class="toolbar"><input type="hidden" name="o" value="{{ $pid }}">
    <div class="chips">
      <a class="chip {{ $rooms === '' ? 'on' : '' }}" href="?{{ http_build_query(['o' => $pid, 'status' => $stF]) }}">{{ __('Все') }}</a>
      @foreach ([1, 2, 3, 4, 0] as $r)<a class="chip {{ $rooms === (string) $r ? 'on' : '' }}" href="?{{ http_build_query(['o' => $pid, 'status' => $stF, 'rooms' => $r]) }}">{{ $r ? $r.__('-комн.') : __('Коммерция') }}</a>@endforeach
    </div>
    <select class="input" name="status" onchange="this.form.submit()"><option value="">{{ __('Любой статус') }}</option>@foreach ($ST as $k => $v)<option value="{{ $k }}" @selected($stF === $k)>{{ __($v) }}</option>@endforeach<option value="overdue" @selected($stF === 'overdue')>{{ __('Есть просрочка') }}</option></select>
    @if ($rooms !== '')<input type="hidden" name="rooms" value="{{ $rooms }}">@endif
    <input class="input grow" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('№ квартиры, покупатель или телефон') }}">
    <div class="chess-legend flex"><span><i class="st-free"></i>{{ __('Свободна') }}</span><span><i class="st-booked"></i>{{ __('Бронь') }}</span><span><i class="st-sold"></i>{{ __('Продана') }}</span><span><i class="st-reserve"></i>{{ __('Не в продаже') }}</span></div>
  </form>
  <div class="pub-sections sales-chess">
    @foreach ($bySec as $sec => $list)
      <div class="pub-sec">
        <div class="muted" style="margin-bottom:8px">{{ __('Подъезд') }} {{ $sec }}</div>
        @foreach ($list->groupBy('floor')->sortKeysDesc() as $fl => $row)
          <div class="pub-floor"><span class="fl">{{ $fl }}</span>
            @foreach ($row as $a)
              <button type="button" class="pub-apt st-{{ $a['status'] }} {{ $match($a) ? '' : 'dimmed' }} {{ $a['overdue'] > 0 ? 'late' : '' }}" data-apt="{{ $a['id'] }}" title="{{ __('Кв.') }} {{ apt_num($a['num']) }} · {{ $a['area'] }} {{ __('м²') }}{{ $a['buyer'] ? ' · '.$a['buyer'] : '' }}">
                <b>{{ apt_num($a['num']) }}</b><small>{{ $a['rooms'] ?: 'K' }}·{{ round($a['area']) }}</small>
                @if (in_array($a['status'], ['sold', 'booked']) && $a['price'] > 0)<i class="paid-bar" style="width:{{ min(100, round($a['paid'] / $a['price'] * 100)) }}%"></i>@endif
              </button>
            @endforeach
          </div>
        @endforeach
      </div>
    @endforeach
  </div>
  <small class="muted">{{ __('Полоска внизу квартиры — сколько оплачено. Красная рамка — просрочка платежа. Нажмите на квартиру, чтобы забронировать, продать или принять оплату.') }}</small>
</div>

<div class="grid g3 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('alarm-clock') !!}{{ __('Просрочили оплату') }}</h3><span class="badge {{ count($overdueList) ? 'b-bad' : '' }}">{{ count($overdueList) }}</span></div>
    @forelse (collect($overdueList)->sortByDesc('overdue')->take(10) as $a)
      <div class="list-row"><div><a href="#" data-apt="{{ $a['id'] }}"><b>{{ __('Кв.') }} {{ apt_num($a['num']) }}</b></a> · {{ $a['buyer'] }}<br><small class="muted">{{ $a['phone'] }}</small></div><b class="out-c">{{ money_s($a['overdue'], $cur) }}</b></div>
    @empty<div class="muted">{{ __('Все платят вовремя') }}</div>@endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('wallet') !!}{{ __('Последние оплаты') }}</h3></div>
    @forelse ($payments as $x)
      <div class="list-row"><div>{{ __('Кв.') }} {{ apt_num($x['apt']) }} · {{ $x['buyer'] }}<br><small class="muted">{{ dmy($x['date'] ?? null) }}</small></div><b class="in-c">+{{ money_s($x['amount'], $cur) }}</b></div>
    @empty<div class="muted">{{ __('Оплат пока нет') }}</div>@endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('phone') !!}{{ __('Новые заявки клиентов') }}</h3><a class="btn btn-s btn-ghost" href="/crm#leads">{!! ic('chevron-right') !!}</a></div>
    @forelse ($leads as $l)
      <div class="list-row"><div><b>{{ $l->name ?: '—' }}</b> <small class="muted">{{ $l->src }}</small><br><small class="muted">{{ $l->phone }}{{ $l->rooms ? ' · '.$l->rooms.__('-комн.') : '' }}</small></div><small class="muted">{{ $l->created_at?->format('d.m H:i') }}</small></div>
    @empty<div class="muted">{{ __('Заявок пока нет') }}</div>@endforelse
  </div>
</div>

<dialog class="modal wide" id="dlg-apt">
  <div class="modal-h"><h3 id="ap-title"></h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b">
    <div class="sum-line" id="ap-sub" style="margin-bottom:14px"></div>
    <div class="grid g2">
      <form method="post" id="ap-form" class="form" style="grid-template-columns:1fr 1fr;align-content:start">@csrf
        <label class="field"><span>{{ __('Статус') }}</span><select name="status">@foreach ($ST as $k => $v)<option value="{{ $k }}">{{ __($v) }}</option>@endforeach</select></label>
        <label class="field"><span>{{ __('Цена') }}, {{ __($cur) }}</span><input type="number" name="price" min="0" step="1000"></label>
        <label class="field full"><span>{{ __('Покупатель (ФИО)') }}</span><input name="buyer" maxlength="120"></label>
        <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" maxlength="40" placeholder="+998"></label>
        <label class="field"><span>{{ __('Условия оплаты') }}</span><select name="plan"><option value="">—</option>@foreach ($PL as $x)<option value="{{ $x }}">{{ __($x) }}</option>@endforeach</select></label>
        <label class="field full"><span>{{ __('Примечание') }}</span><input name="note" maxlength="300"></label>
        @if ($canSell)<div class="full"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>@endif
      </form>
      <div>
        <div class="hbar"><div class="hb-t"><span>{{ __('Оплачено') }}</span><b id="ap-paid"></b></div><div class="hb-r"><i id="ap-bar" style="background:var(--ok)"></i></div></div>
        <div id="ap-next" class="muted" style="font-size:13px;margin-bottom:8px"></div>
        <div id="ap-pays" class="files" style="max-height:180px;overflow:auto"></div>
        @if ($canSell)
        <form method="post" id="ap-pay" class="flex mt" style="flex-wrap:wrap">@csrf
          <input class="input" type="date" name="date" value="{{ $ws->localNow()->toDateString() }}" style="width:150px">
          <input class="input" type="number" name="amount" min="1" step="1000" placeholder="{{ __('Сумма') }}" style="width:150px" required>
          <select class="input" name="way" style="width:auto"><option value="">{{ __('Способ') }}</option>@foreach (['Наличные', 'Перечисление', 'Карта', 'Click', 'Payme'] as $w)<option value="{{ $w }}">{{ __($w) }}</option>@endforeach</select>
          <button class="btn btn-p btn-s">{!! ic('plus') !!}{{ __('Принять оплату') }}</button>
        </form>
        <small class="muted">{{ __('Оплата сразу попадает в бухгалтерию объекта как поступление.') }}</small>
        @endif
        <div class="flex mt" style="flex-wrap:wrap" id="ap-actions"></div>
      </div>
    </div>
  </div>
</dialog>
<form method="post" id="ap-del" hidden>@csrf @method('delete')</form>
<form method="post" id="ap-link" hidden>@csrf</form>
@endsection
@push('scripts')
<script>
(() => {
  const A = @json($js), INT = @json($int), CUR = @json(__($cur)), PID = @json($pid), canDel = @json(auth()->user()->isAdmin() || auth()->user()->role === 'accountant');
  const ST = @json(collect($ST)->map(fn ($v) => __($v))), T = { kv: @json(__('Кв.')), k: @json(__('Коммерция')), r: @json(__('-комн.')), sec: @json(__('Подъезд')), fl: @json(__('этаж')),
    none: @json(__('Оплат пока нет')), next: @json(__('Следующий платёж')), od: @json(__('Просрочено')), link: @json(__('Ссылка для покупателя')), mk: @json(__('Создать кабинет покупателя')),
    t3: @json(__('3D-интерьер')), m2: @json(__('м²')), del: @json(__('Удалить оплату?')) };
  const m = n => Math.round(n).toLocaleString('ru-RU') + ' ' + CUR, dlg = document.getElementById('dlg-apt'), esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function open(id) {
    const a = A[id]; if (!a) return;
    document.getElementById('ap-title').textContent = a.rooms ? `${T.kv} ${a.num} · ${a.rooms}${T.r}` : `${T.k} ${a.num}`;
    document.getElementById('ap-sub').innerHTML = `<span>${T.sec} <b>${a.section}</b>, ${T.fl} <b>${a.floor}</b></span><span><b>${a.area}</b> ${T.m2}</span><span><b>${m(a.price)}</b> (${m(a.price / a.area)}/${T.m2})</span><span class="st st-${a.status}">${esc(ST[a.status])}</span>`;
    const f = document.getElementById('ap-form'); f.action = `/company/sales/${PID}/apt/${a.id}`;
    f.status.value = a.status; f.price.value = Math.round(a.price); f.buyer.value = a.buyer; f.phone.value = a.phone; f.plan.value = a.plan; f.note.value = a.note;
    const pct = a.price ? Math.min(100, a.paid / a.price * 100) : 0;
    document.getElementById('ap-paid').textContent = `${m(a.paid)} / ${m(a.price)}`; document.getElementById('ap-bar').style.width = pct + '%';
    document.getElementById('ap-next').innerHTML = (a.overdue > 0 ? `<b class="out-c">${T.od}: ${m(a.overdue)}</b> · ` : '') + (a.next ? `${T.next}: <b>${m(a.next.amount)}</b> — ${a.next.date.split('-').reverse().join('.')}` : '');
    document.getElementById('ap-pays').innerHTML = a.payments.length ? a.payments.slice().reverse().map(x => `<div class="file-row"><span style="flex:1">${(x.date || '').split('-').reverse().join('.')}</span><b class="in-c">+${m(x.amount)}</b>${canDel ? `<button class="icon-btn" data-del="${x.id}" title="✕">✕</button>` : ''}</div>`).join('') : `<div class="muted">${T.none}</div>`;
    const pf = document.getElementById('ap-pay'); if (pf) { pf.action = `/company/sales/${PID}/apt/${a.id}/pay`; pf.amount.value = a.next ? Math.round(a.next.amount) : ''; }
    const ints = (INT[String(a.rooms)] || []).concat(INT[''] || []);
    document.getElementById('ap-actions').innerHTML = (a.link ? `<a class="btn btn-s" href="${a.link}" target="_blank">🔗 ${T.link}</a>` : (['sold', 'booked'].includes(a.status) ? `<button class="btn btn-s" data-link="${a.id}">${T.mk}</button>` : ''))
      + ints.map(x => `<a class="btn btn-s" href="/company/objects/${PID}/3d?m=${x.id}" target="_blank">${T.t3}: ${esc(x.title)}</a>`).join('');
    dlg.dataset.apt = a.id; dlg.showModal();
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-apt]'); if (b) { e.preventDefault(); open(b.dataset.apt); return; }
    const d = e.target.closest('[data-del]'); if (d && await ui.confirm(T.del)) { const f = document.getElementById('ap-del'); f.action = `/company/sales/${PID}/apt/${dlg.dataset.apt}/pay/${d.dataset.del}`; f.submit(); }
    const l = e.target.closest('[data-link]'); if (l) { const f = document.getElementById('ap-link'); f.action = `/company/sales/${PID}/apt/${l.dataset.link}/link`; f.submit(); }
  });
  @if (session('open')) open(@json(session('open'))); @endif
})();
</script>
@endpush
