@extends('layouts.panel')
@section('title', __('Мониторинг'))
@php
  $u = auth()->user(); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c); $W = \App\Http\Controllers\Company\MonitorController::WIDGETS;
  $now = $ws->localNow();
  $dm = fn ($d) => \Carbon\Carbon::parse($d)->format('d.m');
  $chart = fn (array $c) => chart_json($c);
@endphp
@section('subtitle'){{ __('Добрый день, :n. Вот что происходит в компании прямо сейчас.', ['n' => \Illuminate\Support\Str::before($u->name, ' ')]) }} · {{ $now->translatedFormat('d F') }}, {{ weekday($now) }}, {{ $now->format('H:i') }}@endsection
@section('actions')
<button class="btn" data-open="dlg-w">{!! ic('sliders-horizontal') !!}{{ __('Настроить панель') }}</button>
@endsection
@section('content')
{{-- показатели --}}
<div class="kpis">
  @if ($kpi['money'])
    <a class="kpi hero" href="/company/cash" style="text-decoration:none">
      <span class="k-ic">{!! ic('wallet') !!}</span>
      <div class="k-l">{{ __('Деньги в кассах') }}</div>
      <div class="k-v">{{ money_s($kpi['cash'], $cur) }}</div>
      <div class="k-s">{!! trend($kpi['cash'], $kpi['cash_prev'], true, __('к неделе назад')) !!}{{ __('изменение за 7 дней') }}</div>
    </a>
    <a class="kpi tone-bad" href="/company/overview" style="text-decoration:none;color:inherit">
      <span class="k-ic">{!! ic('trending-down') !!}</span>
      <div class="k-l">{{ __('Потрачено за месяц') }}</div>
      <div class="k-v">{{ money_s($kpi['out'], $cur) }}</div>
      <div class="k-s">{!! trend($kpi['out'], $kpi['out_prev'], false, __('к тем же дням прошлого месяца')) !!}{{ __('прошлый месяц за те же дни') }}: {{ money_s($kpi['out_prev'], $cur) }}</div>
    </a>
    <a class="kpi tone-ok" href="/company/overview" style="text-decoration:none;color:inherit">
      <span class="k-ic">{!! ic('trending-up') !!}</span>
      <div class="k-l">{{ __('Поступило за месяц') }}</div>
      <div class="k-v">{{ money_s($kpi['in'], $cur) }}</div>
      <div class="k-s">{!! trend($kpi['in'], $kpi['in_prev'], true, __('к тем же дням прошлого месяца')) !!}{{ __('прошлый месяц за те же дни') }}: {{ money_s($kpi['in_prev'], $cur) }}</div>
    </a>
  @else
    <a class="kpi hero" href="/company/tasks" style="text-decoration:none">
      <span class="k-ic">{!! ic('list-checks') !!}</span>
      <div class="k-l">{{ __('Открытые задачи') }}</div>
      <div class="k-v">{{ $kpi['tasks'] }}</div>
      <div class="k-s">{{ __('Просрочено') }}: {{ $kpi['overdue'] }}</div>
    </a>
    <div class="kpi tone-info">
      <span class="k-ic">{!! ic('building-2') !!}</span>
      <div class="k-l">{{ __('Готовность объектов') }}</div>
      <div class="k-v">{{ $kpi['progress'] }}%</div>
      <div class="prog" style="margin-top:8px"><i style="width:{{ $kpi['progress'] }}%"></i></div>
    </div>
    <a class="kpi tone-warn" href="/company/supply" style="text-decoration:none;color:inherit">
      <span class="k-ic">{!! ic('truck') !!}</span>
      <div class="k-l">{{ __('Заявки на материалы в работе') }}</div>
      <div class="k-v">{{ $kpi['supply'] }}</div>
      <div class="k-s">{{ __('новые, согласованные и заказанные') }}</div>
    </a>
  @endif
  <a class="kpi {{ $kpi['decide'] ? 'tone-warn' : 'tone-ok' }}" href="{{ $kpi['decide'] ? '/company/payments' : '#' }}" style="text-decoration:none;color:inherit">
    <span class="k-ic">{!! ic($kpi['decide'] ? 'hourglass' : 'circle-check-big') !!}</span>
    <div class="k-l">{{ __('Ждут моего решения') }}</div>
    <div class="k-v">{{ $kpi['decide'] }}</div>
    <div class="k-s">{{ $kpi['decide'] ? __('оплаты, расходы и заявки') : __('Всё согласовано') }}</div>
  </a>
</div>

<div class="wgrid">
  @if (in_array('ai', $on))
    @include('partials.insights', ['topic' => 'company', 'class' => (isset($w['approvals']) || isset($w['tasks']) ? 'w8' : 'w12').(isset($w['approvals']) && isset($w['tasks']) ? ' tall' : ''), 'title' => __('ИИ-анализ компании')])
  @endif
  @isset($w['approvals'])
  <div class="card {{ in_array('ai', $on) ? 'w4' : 'w12' }}">
    <div class="card-h"><h3>{!! ic('file-check') !!}{{ __($W['approvals'][0]) }}</h3><span class="badge {{ $w['approvals']['n'] ? 'b-warn' : 'b-ok' }}">{{ $w['approvals']['n'] }}</span></div>
    @forelse ($w['approvals']['list'] as $r)
      <a class="m-row" href="/company/payments/{{ $r->id }}">
        <span class="avatar">{{ initials($r->user?->name) }}</span>
        <span class="m-row-t"><b>№{{ $r->number }} · {{ \Illuminate\Support\Str::limit($r->purpose, 34) }}</b><small>{{ $r->user?->name }}</small></span>
        <span class="m-row-v">{{ $M($r->amount, $r->currency) }}</span>
      </a>
    @empty
      <div class="empty">{!! ic('circle-check-big') !!}<div>{{ __('Всё согласовано') }}</div></div>
    @endforelse
    <div class="sum-line mt"><span>{{ __('К оплате') }}: <b>{{ $w['approvals']['toPay'] }}</b></span>@if ($u->isAdmin())<span><a href="/company/approvals">{{ __('Расходы из Telegram') }}</a>: <b>{{ $w['approvals']['exp'] }}</b></span>@endif</div>
  </div>
  @endisset

  @isset($w['tasks'])
  <div class="card {{ in_array('ai', $on) ? 'w4' : 'w6' }}">
    <div class="card-h"><h3>{!! ic('list-checks') !!}{{ __($W['tasks'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/tasks">{{ __('Все') }}{!! ic('chevron-right') !!}</a></div>
    <div class="mini-stats" style="margin-bottom:12px"><div><span>{{ __('Открыто') }}</span><b>{{ $w['tasks']['open'] }}</b></div><div><span>{{ __('Просрочено') }}</span><b class="{{ $w['tasks']['overdue'] ? 'out-c' : '' }}">{{ $w['tasks']['overdue'] }}</b></div><div><span>{{ __('На проверке') }}</span><b>{{ $w['tasks']['review'] }}</b></div></div>
    @forelse ($w['tasks']['mine'] as $t)
      <a class="m-row" href="/company/tasks/{{ $t->id }}"><span class="m-dot {{ $t->due_date?->isPast() ? 'bad' : '' }}"></span><span class="m-row-t"><b>{{ \Illuminate\Support\Str::limit($t->title, 44) }}</b></span><small class="{{ $t->due_date?->isPast() ? 'out-c' : 'muted' }}">{{ $t->due_date?->format('d.m') }}</small></a>
    @empty
      <div class="empty">{!! ic('circle-check-big') !!}<div>{{ __('Ваших задач нет') }}</div></div>
    @endforelse
  </div>
  @endisset


  @isset($w['today'])
  @php
    $T = $w['today']['totals'];
    $cats = $w['today']['cats_month']; $catTop = array_slice($cats, 0, 5, true); $catRest = array_sum(array_slice($cats, 5));
    if ($catRest > 0) { $catTop[__('Прочее')] = $catRest; }
    $catCols = ['c1', 'c2', 'c3', 'c4', 'c6', 'c8'];
  @endphp
  <div class="card w8">
    <div class="card-h"><h3>{!! ic('chart-column') !!}{{ __('Деньги за 14 дней') }}</h3><a class="btn btn-s btn-ghost" href="/company/overview">{{ __('Сводка') }}{!! ic('chevron-right') !!}</a>
      <p>{{ __('Сегодня потрачено') }} <b class="out-c">{{ money_s($T['out_day'], $cur) }}</b> · {{ __('вчера') }} {{ money_s($T['out_yest'], $cur) }} · {{ __('поступило сегодня') }} <b class="in-c">{{ money_s($T['in_day'], $cur) }}</b></p></div>
    <div class="chart-box" data-chart="{{ $chart(['type' => 'bar', 'money' => $cur, 'labels' => array_map($dm, array_keys($w['today']['days14'])),
      'toggle' => true, 'series' => [['name' => __('Расходы'), 'data' => array_values(array_map('round', $w['today']['days14'])), 'color' => 'c1', 'highlightLast' => true], ['name' => __('Поступления'), 'data' => array_values(array_map('round', $w['today']['in14'])), 'color' => 'c3', 'highlightLast' => true]]]) }}"></div>
  </div>
  <div class="card w4">
    <div class="card-h"><h3>{!! ic('chart-pie') !!}{{ __('На что ушли деньги') }}</h3><span class="muted" style="font-size:13px">{{ __('за месяц') }}</span></div>
    @if ($catTop)
      <div class="chart-box sm" style="height:190px" data-chart="{{ $chart(['type' => 'donut', 'money' => $cur, 'labels' => array_map(fn ($c) => __($c), array_keys($catTop)),
        'series' => [['data' => array_values(array_map('round', $catTop)), 'colors' => array_slice($catCols, 0, count($catTop))]], 'center' => ['value' => \App\Services\CompanyStats::short($T['out_month']), 'label' => __('за месяц')]]) }}"></div>
      <div class="leg-list mt">
        @foreach (array_values($catTop) as $i => $v)
          <div style="--c:var(--{{ $catCols[$i] }})"><i></i><span>{{ __(array_keys($catTop)[$i]) }}</span><b>{{ \App\Services\CompanyStats::short($v) }}</b><em>{{ $T['out_month'] > 0 ? round($v / $T['out_month'] * 100) : 0 }}%</em></div>
        @endforeach
      </div>
    @else
      <div class="empty">{!! ic('chart-pie') !!}<div>{{ __('Расходов в этом месяце ещё нет') }}</div></div>
    @endif
  </div>
  @endisset

  @isset($w['money'])
  <div class="card w12">
    <div class="card-h"><h3>{!! ic('wallet') !!}{{ __($W['money'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/cash">{{ __('Кассы') }}{!! ic('chevron-right') !!}</a><p>1 USD = {{ number_format($w['money']['rate'], 2, '.', ' ') }}</p></div>
    <div class="desk-grid" style="margin:0">
      <div class="desk total"><div class="d-t">{{ __('Всего в валюте компании') }}</div><div class="d-v">{{ money_s($w['money']['total'], $cur) }}</div></div>
      @foreach ($w['money']['desks'] as $x)
        <a class="desk" href="/company/cash?desk={{ $x['d']->id }}"><div class="d-t"><span>{{ __($x['d']->name) }}</span><span class="cur {{ strtolower($x['d']->currency) === 'usd' ? 'usd' : '' }}">{{ $x['d']->currency }}</span></div><div class="d-v {{ $x['b'] < 0 ? 'out-c' : '' }}">{{ $M($x['b'], $x['d']->currency) }}</div></a>
      @endforeach
    </div>
  </div>
  @endisset

  @isset($w['forecast'])
  @php $neg = collect($w['forecast'])->first(fn ($r) => $r['close'] < 0); @endphp
  <div class="card w12">
    <div class="card-h"><h3>{!! ic('trending-up') !!}{{ __($W['forecast'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/forecast">{{ __('Подробно') }}{!! ic('chevron-right') !!}</a>
      <p>{{ __('Столбики — сколько придёт и сколько уйдёт за неделю, линия — сколько останется в кассах.') }}</p></div>
    @if ($neg)<div class="warn-line" style="margin-bottom:12px">{!! ic('triangle-alert') !!}<div>{{ __('Кассовый разрыв ожидается с :d — не хватит :s.', ['d' => $neg['from']->format('d.m'), 's' => money_s(abs($neg['close']), $cur)]) }}</div></div>@endif
    <div class="chart-box" data-chart="{{ $chart(['type' => 'bar', 'money' => $cur, 'labels' => array_map(fn ($r) => $r['from']->format('d.m'), $w['forecast']),
      'series' => [['name' => __('Придёт'), 'data' => array_map(fn ($r) => round($r['in']), $w['forecast']), 'color' => 'c3', 'soft' => true],
        ['name' => __('Уйдёт'), 'data' => array_map(fn ($r) => round($r['out']), $w['forecast']), 'color' => 'c4', 'soft' => true],
        ['name' => __('Останется'), 'type' => 'line', 'axis' => 'y1', 'data' => array_map(fn ($r) => round($r['close']), $w['forecast']), 'color' => 'c1', 'neg' => 'c5']]]) }}"></div>
  </div>
  @endisset

  @isset($w['projects'])
  @php $pr = collect($w['projects'])->sortByDesc('pct')->values(); @endphp
  <div class="card w6" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('building-2') !!}{{ __($W['projects'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/objects">{!! ic('chevron-right') !!}</a></div>
    @forelse ($pr as $p)
      <div class="hbar"><div class="hb-t"><span>{{ $p['name'] }}@if ($p['demo']) <small class="faint">({{ __('демо') }})</small>@endif</span><b>{{ $p['pct'] }}%</b></div><div class="hb-r"><i style="width:{{ $p['pct'] }}%;background:linear-gradient(90deg,var(--c1),var(--c2))"></i></div></div>
    @empty<div class="empty">{!! ic('building-2') !!}<div>{{ __('Объектов нет') }}</div></div>@endforelse
  </div>
  @endisset

  @isset($w['limits'])
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('gauge') !!}{{ __($W['limits'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/cash">{!! ic('chevron-right') !!}</a></div>
    @forelse ($w['limits'] as $r)
      <div class="hbar"><div class="hb-t"><span>{{ __($r['cat']->name) }}</span><b class="{{ ($r['pct'] ?? 0) >= 100 ? 'out-c' : '' }}">{{ money_s($r['spent'], $cur) }}@if ($r['limit'] > 0) <span class="faint" style="font-weight:500">/ {{ money_s($r['limit'], $cur) }}</span>@endif</b></div>
        <div class="hb-r"><i style="width:{{ $r['limit'] > 0 ? min(100, $r['pct']) : 100 }}%;background:{{ $r['limit'] <= 0 ? 'var(--chart-2)' : (($r['pct'] >= 100) ? 'var(--bad)' : ($r['pct'] >= 80 ? 'var(--warn)' : 'var(--ok)')) }}"></i></div></div>
    @empty<div class="empty">{!! ic('gauge') !!}<div>{{ __('Расходов из касс в этом месяце ещё нет') }}</div></div>@endforelse
  </div>
  @endisset

  @isset($w['debts'])
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('scale') !!}{{ __($W['debts'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/contracts">{!! ic('chevron-right') !!}</a></div>
    <div class="mini-stats" style="margin-bottom:12px"><div><span>{{ __('Мы должны') }}</span><b class="out-c">{{ money_s($w['debts']['we'], $cur) }}</b></div><div><span>{{ __('Нам должны') }}</span><b class="in-c">{{ money_s($w['debts']['us'], $cur) }}</b></div></div>
    @foreach ($w['debts']['top'] as $r)
      <a class="m-row" href="/company/contracts/{{ $r['c']->id }}"><span class="avatar">{{ initials($r['c']->counterparty?->name) }}</span><span class="m-row-t"><b>{{ $r['c']->counterparty?->name }}</b><small>№{{ $r['c']->number }}</small></span><span class="m-row-v {{ $r['c']->direction === 'out' ? 'out-c' : 'in-c' }}">{{ money_s($r['left'], $cur) }}</span></a>
    @endforeach
    @if ($w['debts']['inter'])<div class="mt"><b style="font-size:13px">{{ __('Взаиморасчёты объектов') }}</b>@foreach ($w['debts']['inter'] as $r)<div class="list-row"><small>{{ $r['to'] }} → {{ $r['from'] }}</small><b>{{ money_s($r['amount'], $cur) }}</b></div>@endforeach</div>@endif
  </div>
  @endisset

  @if (isset($w['stock']) || isset($w['supply']))
  @php
    $SL = ['new' => ['новых', 'c4'], 'approved' => ['согласовано', 'c2'], 'ordered' => ['заказано', 'c1'], 'partial' => ['частично', 'c6']];
    $sup = collect($SL)->map(fn ($x, $k) => (int) ($w['supply'][$k] ?? 0));
  @endphp
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('truck') !!}{{ __('Снабжение и склад') }}</h3><a class="btn btn-s btn-ghost" href="/company/supply">{!! ic('chevron-right') !!}</a></div>
    @isset($w['supply'])
      @if ($sup->sum())
        <div class="stack-bar">@foreach ($SL as $k => [$l, $c])@if ($sup[$k])<i style="--c:var(--{{ $c }});flex:{{ $sup[$k] }}" title="{{ __($l) }}: {{ $sup[$k] }}"></i>@endif @endforeach</div>
      @endif
      <div class="leg-list" style="grid-template-columns:1fr 1fr;margin-bottom:14px">@foreach ($SL as $k => [$l, $c])<div style="--c:var(--{{ $c }})"><i></i><span>{{ __($l) }}</span><b>{{ $sup[$k] }}</b></div>@endforeach</div>
    @endisset
    @isset($w['stock'])
      <b style="font-size:13px;display:block;margin-bottom:4px">{{ __($W['stock'][0]) }}</b>
      @forelse ($w['stock'] as $r)<div class="list-row"><a href="/company/stock/items/{{ $r['item']->id }}">{{ $r['item']->name }}</a><b class="out-c">{{ \App\Services\Erp\Stock::n($r['qty']) }} / {{ \App\Services\Erp\Stock::n($r['item']->min_qty) }} {{ __($r['item']->unit ?? '') }}</b></div>
      @empty<div class="muted" style="font-size:13.5px">{{ __('Всего хватает') }}</div>@endforelse
    @endisset
  </div>
  @endif

  @isset($w['photos'])
  <div class="card w12">
    <div class="card-h"><h3>{!! ic('camera') !!}{{ __($W['photos'][0]) }}</h3><a class="btn btn-s btn-ghost" href="/company/journal">{{ __('Журнал') }}{!! ic('chevron-right') !!}</a></div>
    @if ($w['photos']->count())
    <div class="gallery" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">@foreach ($w['photos'] as $l)<a href="/company/journal?date={{ $l->date->toDateString() }}" title="{{ $projects[$l->project_id] ?? '' }} · {{ $l->user?->name }} · {{ $l->date->format('d.m') }}"><img src="{{ $l->photoUrls()[0] ?? '' }}" loading="lazy" alt=""></a>@endforeach</div>
    @else<div class="empty">{!! ic('camera') !!}<div>{{ __('Фотоотчётов пока нет') }}</div></div>@endif
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
