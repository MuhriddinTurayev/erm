@extends('layouts.panel')
@section('title', __('Мониторинг'))
@php $u = auth()->user(); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c); $W = \App\Http\Controllers\Company\MonitorController::WIDGETS; @endphp
@section('actions')
<button class="btn btn-s" data-open="dlg-w">{!! ic('sliders-horizontal') !!}{{ __('Настроить панель') }}</button>
@endsection
@section('content')
<div class="page-head" style="margin-bottom:16px"><p>{{ __('Добрый день, :n. Вот что происходит в компании прямо сейчас.', ['n' => \Illuminate\Support\Str::before($u->name, ' ')]) }} <span class="faint">{{ $ws->localNow()->translatedFormat('d F') }}, {{ weekday($ws->localNow()) }}, {{ $ws->localNow()->format('H:i') }}</span></p></div>
<div class="wgrid">
  @isset($w['money'])
  <div class="card w8">
    <div class="card-h"><h3>{!! ic('wallet') !!}{{ __($W['money'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/cash">{{ __('Кассы') }}{!! ic('chevron-right') !!}</a></div>
    <div class="appr-sum" style="font-size:30px">{{ money_s($w['money']['total'], $cur) }}</div>
    <div class="muted" style="margin-bottom:12px">1 USD = {{ number_format($w['money']['rate'], 2, '.', ' ') }}</div>
    <div class="mini-stats">@foreach ($w['money']['desks'] as $x)<div><span>{{ __($x['d']->name) }}</span><b class="{{ $x['b'] < 0 ? 'out-c' : '' }}">{{ $M($x['b'], $x['d']->currency) }}</b></div>@endforeach</div>
  </div>
  @endisset
  @isset($w['approvals'])
  <div class="card w4">
    <div class="card-h"><h3>{!! ic('file-check') !!}{{ __($W['approvals'][0]) }}</h3><span class="badge {{ $w['approvals']['n'] ? 'b-warn' : '' }}">{{ $w['approvals']['n'] }}</span></div>
    @forelse ($w['approvals']['list'] as $r)
      <div class="list-row"><div><a href="/company/payments/{{ $r->id }}">№{{ $r->number }} · {{ \Illuminate\Support\Str::limit($r->purpose, 32) }}</a><br><small>{{ $r->user?->name }}</small></div><b>{{ $M($r->amount, $r->currency) }}</b></div>
    @empty
      <div class="muted">{{ __('Всё согласовано') }}</div>
    @endforelse
    <div class="sum-line mt"><span>{{ __('К оплате') }}: <b>{{ $w['approvals']['toPay'] }}</b></span>@if ($u->isAdmin())<span><a href="/company/approvals">{{ __('Расходы из Telegram') }}</a>: <b>{{ $w['approvals']['exp'] }}</b></span>@endif</div>
  </div>
  @endisset
  @isset($w['today'])
  @php $T = $w['today']['totals']; @endphp
  <div class="card w12">
    <div class="card-h"><h3>{!! ic('calendar') !!}{{ __($W['today'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/overview">{{ __('Сводка') }}{!! ic('chevron-right') !!}</a></div>
    <div class="mini-stats" style="margin-bottom:14px">
      <div><span>{{ __('Потрачено сегодня') }}</span><b class="out-c">{{ money_s($T['out_day'], $cur) }}</b></div>
      <div><span>{{ __('Вчера') }}</span><b>{{ money_s($T['out_yest'], $cur) }}</b></div>
      <div><span>{{ __('Поступило сегодня') }}</span><b class="in-c">{{ money_s($T['in_day'], $cur) }}</b></div>
      <div><span>{{ __('За месяц потрачено') }}</span><b>{{ money_s($T['out_month'], $cur) }}</b></div>
      <div><span>{{ __('Баланс объектов') }}</span><b>{{ money_s($T['balance'], $cur) }}</b></div>
    </div>
    @php $mx = max(1, max($w['today']['days14'])); @endphp
    <div class="fc" style="height:110px">@foreach ($w['today']['days14'] as $d => $v)<div class="b" title="{{ \Carbon\Carbon::parse($d)->format('d.m') }}: {{ money_s($v, $cur) }}"><i style="height:{{ max(2, $v / $mx * 100) }}%"></i><small>{{ \Carbon\Carbon::parse($d)->format('d') }}</small></div>@endforeach</div>
  </div>
  @endisset
  @isset($w['forecast'])
  @php $fx = max(1, max(array_map(fn ($r) => abs($r['close']), $w['forecast']))); $neg = collect($w['forecast'])->first(fn ($r) => $r['close'] < 0); @endphp
  <div class="card w8">
    <div class="card-h"><h3>{!! ic('trending-up') !!}{{ __($W['forecast'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/forecast">{{ __('Подробно') }}{!! ic('chevron-right') !!}</a></div>
    @if ($neg)<div class="warn-line" style="margin-bottom:10px">{!! ic('triangle-alert') !!}<div>{{ __('Кассовый разрыв ожидается с :d — не хватит :s.', ['d' => $neg['from']->format('d.m'), 's' => money_s(abs($neg['close']), $cur)]) }}</div></div>@endif
    <div class="fc">@foreach ($w['forecast'] as $r)<div class="b" title="{{ $r['from']->format('d.m') }}: {{ money_s($r['close'], $cur) }}"><i class="{{ $r['close'] < 0 ? 'neg' : '' }}" style="height:{{ max(2, abs($r['close']) / $fx * 100) }}%"></i><small>{{ $r['from']->format('d.m') }}</small></div>@endforeach</div>
  </div>
  @endisset
  @isset($w['tasks'])
  <div class="card w4">
    <div class="card-h"><h3>{!! ic('list-checks') !!}{{ __($W['tasks'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/tasks">{!! ic('chevron-right') !!}</a></div>
    <div class="mini-stats" style="margin-bottom:10px"><div><span>{{ __('Открыто') }}</span><b>{{ $w['tasks']['open'] }}</b></div><div><span>{{ __('Просрочено') }}</span><b class="{{ $w['tasks']['overdue'] ? 'out-c' : '' }}">{{ $w['tasks']['overdue'] }}</b></div><div><span>{{ __('На проверке') }}</span><b>{{ $w['tasks']['review'] }}</b></div></div>
    @foreach ($w['tasks']['mine'] as $t)<div class="list-row"><a href="/company/tasks/{{ $t->id }}">{{ \Illuminate\Support\Str::limit($t->title, 40) }}</a><small class="{{ $t->due_date?->isPast() ? 'out-c' : 'muted' }}">{{ $t->due_date?->format('d.m') }}</small></div>@endforeach
  </div>
  @endisset
  @isset($w['projects'])
  <div class="card w6" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('building-2') !!}{{ __($W['projects'][0]) }}</h3></div>
    @forelse ($w['projects'] as $p)
      <div class="hbar"><div class="hb-t"><span>{{ $p['name'] }}@if ($p['demo']) <small class="faint">({{ __('демо') }})</small>@endif</span><b>{{ $p['pct'] }}%</b></div><div class="hb-r"><i style="width:{{ $p['pct'] }}%;background:var(--ok)"></i></div></div>
    @empty<div class="muted">{{ __('Объектов нет') }}</div>@endforelse
  </div>
  @endisset
  @isset($w['limits'])
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('gauge') !!}{{ __($W['limits'][0]) }}</h3></div>
    @forelse ($w['limits'] as $r)
      <div class="hbar"><div class="hb-t"><span>{{ __($r['cat']->name) }}</span><b class="{{ ($r['pct'] ?? 0) >= 100 ? 'out-c' : '' }}">{{ money_s($r['spent'], $cur) }}@if ($r['limit'] > 0) / {{ money_s($r['limit'], $cur) }}@endif</b></div>
        <div class="hb-r"><i style="width:{{ $r['limit'] > 0 ? min(100, $r['pct']) : 100 }}%;background:{{ $r['limit'] <= 0 ? 'var(--chart)' : (($r['pct'] >= 100) ? 'var(--bad)' : ($r['pct'] >= 80 ? 'var(--warn)' : 'var(--ok)')) }}"></i></div></div>
    @empty<div class="muted">{{ __('Расходов из касс в этом месяце ещё нет') }}</div>@endforelse
  </div>
  @endisset
  @isset($w['debts'])
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('scale') !!}{{ __($W['debts'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/contracts">{!! ic('chevron-right') !!}</a></div>
    <div class="mini-stats" style="margin-bottom:10px"><div><span>{{ __('Мы должны') }}</span><b class="out-c">{{ money_s($w['debts']['we'], $cur) }}</b></div><div><span>{{ __('Нам должны') }}</span><b class="in-c">{{ money_s($w['debts']['us'], $cur) }}</b></div></div>
    @foreach ($w['debts']['top'] as $r)<div class="list-row"><div><a href="/company/contracts/{{ $r['c']->id }}">{{ $r['c']->counterparty?->name }}</a><br><small>№{{ $r['c']->number }}</small></div><b class="{{ $r['c']->direction === 'out' ? 'out-c' : 'in-c' }}">{{ money_s($r['left'], $cur) }}</b></div>@endforeach
    @if ($w['debts']['inter'])<div class="mt"><b style="font-size:13px">{{ __('Взаиморасчёты объектов') }}</b>@foreach ($w['debts']['inter'] as $r)<div class="list-row"><small>{{ $r['to'] }} → {{ $r['from'] }}</small><b>{{ money_s($r['amount'], $cur) }}</b></div>@endforeach</div>@endif
  </div>
  @endisset
  @isset($w['stock'])
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('package') !!}{{ __($W['stock'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/stock">{!! ic('chevron-right') !!}</a></div>
    @forelse ($w['stock'] as $r)<div class="list-row"><a href="/company/stock/items/{{ $r['item']->id }}">{{ $r['item']->name }}</a><b class="out-c">{{ \App\Services\Erp\Stock::n($r['qty']) }} / {{ \App\Services\Erp\Stock::n($r['item']->min_qty) }} {{ __($r['item']->unit ?? '') }}</b></div>
    @empty<div class="muted">{{ __('Всего хватает') }}</div>@endforelse
    @isset($w['supply'])<div class="sum-line mt">{{ __('Снабжение') }}: @foreach (['new' => 'новых', 'approved' => 'согласовано', 'ordered' => 'заказано', 'partial' => 'частично'] as $k => $l)<span>{{ __($l) }}: <b>{{ $w['supply'][$k] ?? 0 }}</b></span>@endforeach</div>@endisset
  </div>
  @endisset
  @isset($w['photos'])
  <div class="card w12">
    <div class="card-h"><h3>{!! ic('camera') !!}{{ __($W['photos'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/journal">{{ __('Журнал') }}{!! ic('chevron-right') !!}</a></div>
    @if ($w['photos']->count())
    <div class="gallery" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">@foreach ($w['photos'] as $l)<a href="/company/journal?date={{ $l->date->toDateString() }}" title="{{ $projects[$l->project_id] ?? '' }} · {{ $l->user?->name }} · {{ $l->date->format('d.m') }}"><img src="{{ $l->photoUrls()[0] ?? '' }}" loading="lazy" alt=""></a>@endforeach</div>
    @else<div class="muted">{{ __('Фотоотчётов пока нет') }}</div>@endif
  </div>
  @endisset
</div>

<dialog class="modal" id="dlg-w"><form method="post" action="/company/monitor/widgets">@csrf
  <div class="modal-h"><h3>{!! ic('sliders-horizontal') !!}{{ __('Что показывать на панели') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b check-list">
    @foreach ($W as $k => [$t, $i])<label><input type="checkbox" name="w[]" value="{{ $k }}" @checked(in_array($k, $on))> {!! ic($i, '', 16) !!} {{ __($t) }}</label>@endforeach
    <input type="hidden" name="w[]" value="_">
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@endsection
