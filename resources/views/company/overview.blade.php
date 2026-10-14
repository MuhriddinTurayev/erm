@extends('layouts.panel')
@section('title', __('Касса и сводка'))
@php
    $T = $s['totals']; $cur = $s['currency'];
    $m = fn ($v) => money_s($v, $cur);
    $isToday = $s['date'] === $ws->localNow()->toDateString();
@endphp
@section('actions')
<form method="get" class="flex" style="gap:6px">
  <input class="input" type="date" name="date" value="{{ $s['date'] }}" max="{{ $ws->localNow()->toDateString() }}" onchange="this.form.submit()" style="width:auto;height:36px">
  @unless ($isToday)<a class="btn btn-s" href="/company/overview">{{ __('Сегодня') }}</a>@endunless
</form>
@if (auth()->user()->isAdmin())
<form method="post" action="/company/reports/run">@csrf<input type="hidden" name="send" value="1"><button class="btn btn-p btn-s">{!! ic('send') !!}{{ __('Отправить отчёт сейчас') }}</button></form>
@endif
@endsection
@section('content')
@php
  $cats = $s['cats_month']; $catTop = array_slice($cats, 0, 5, true); $rest = array_sum(array_slice($cats, 5));
  if ($rest > 0) { $catTop[__('Прочее')] = $rest; }
  $catCols = ['c1', 'c2', 'c3', 'c4', 'c6', 'c8'];
  $objs = array_values(array_filter($s['objects'], fn ($o) => ! $o['demo']));
  if (count($objs) < 2) { $objs = $s['objects']; }
@endphp
<div class="kpis">
  <div class="kpi hero"><span class="k-ic">{!! ic('wallet') !!}</span><div class="k-l">{{ __('Деньги на счету (касса)') }}</div><div class="k-v" title="{{ money_f($T['balance'], $cur) }}">{{ $m($T['balance']) }}</div><div class="k-s">{{ __('по всем объектам') }}</div></div>
  <div class="kpi tone-bad"><span class="k-ic">{!! ic('trending-down') !!}</span><div class="k-l">{{ $isToday ? __('Потрачено сегодня') : __('Потрачено за день') }}</div><div class="k-v" title="{{ money_f($T['out_day'], $cur) }}">{{ $m($T['out_day']) }}</div>
    <div class="k-s">{!! trend($T['out_day'], $T['out_yest'], false, __('к вчера')) !!}{{ __('вчера') }}: {{ $m($T['out_yest']) }} · {{ __('операций') }}: {{ $T['ops_day'] }}</div></div>
  <div class="kpi tone-info"><span class="k-ic">{!! ic('calendar') !!}</span><div class="k-l">{{ __('Расходы за 7 дней') }}</div><div class="k-v">{{ $m($T['out_week']) }}</div><div class="k-s">{{ __('в среднем') }} {{ $m($T['out_week'] / 7) }} {{ __('в день') }}</div></div>
  <div class="kpi tone-ok"><span class="k-ic">{!! ic('trending-up') !!}</span><div class="k-l">{{ __('Поступило за месяц') }}</div><div class="k-v">{{ $m($T['in_month']) }}</div><div class="k-s">{{ __('сегодня') }}: {{ $m($T['in_day']) }} · {{ __('потрачено за месяц') }}: {{ $m($T['out_month']) }}</div></div>
</div>

<div class="wgrid">
  <div class="card w8">
    <div class="card-h"><h3>{!! ic('chart-column') !!}{{ __('Деньги по дням (14 дней)') }}</h3><span class="muted num">{{ __('расходы') }} {{ $m(array_sum($s['days14'])) }} · {{ __('поступления') }} {{ $m(array_sum($s['in14'])) }}</span></div>
    <div class="chart-box" data-chart="{{ chart_json(['type' => 'bar', 'money' => $cur, 'toggle' => true, 'labels' => array_map(fn ($d) => substr($d, 8, 2).'.'.substr($d, 5, 2), array_keys($s['days14'])),
      'series' => [['name' => __('Расходы'), 'data' => array_values(array_map('round', $s['days14'])), 'color' => 'c1', 'highlightLast' => true], ['name' => __('Поступления'), 'data' => array_values(array_map('round', $s['in14'])), 'color' => 'c3', 'highlightLast' => true]]]) }}"></div>
  </div>
  <div class="card w4">
    <div class="card-h"><h3>{!! ic('chart-pie') !!}{{ __('На что ушли деньги') }}</h3><span class="muted">{{ __('за месяц') }}</span></div>
    @if ($catTop)
      <div class="chart-box sm" style="height:180px" data-chart="{{ chart_json(['type' => 'donut', 'money' => $cur, 'labels' => array_map(fn ($c) => __($c), array_keys($catTop)), 'series' => [['data' => array_values(array_map('round', $catTop)), 'colors' => array_slice($catCols, 0, count($catTop))]], 'center' => ['value' => \App\Services\CompanyStats::short($T['out_month']), 'label' => __('за месяц')]]) }}"></div>
      <div class="leg-list mt">@foreach (array_values($catTop) as $i => $v)<div style="--c:var(--{{ $catCols[$i] }})"><i></i><span>{{ __(array_keys($catTop)[$i]) }}</span><b>{{ \App\Services\CompanyStats::short($v) }}</b><em>{{ round($v / max(1, $T['out_month']) * 100) }}%</em></div>@endforeach</div>
    @else
      <div class="empty">{!! ic('receipt') !!}<div>{{ __('В этом месяце расходов нет') }}</div></div>
    @endif
  </div>
  @if (count($objs) > 1)
  <div class="card" style="grid-column:span 6">
    <div class="card-h"><h3>{!! ic('building-2') !!}{{ __('Объекты: расходы и поступления за месяц') }}</h3></div>
    <div class="chart-box" style="height:{{ max(220, count($objs) * 64) }}px" data-chart="{{ chart_json(['type' => 'hbar', 'money' => $cur, 'labels' => array_map(fn ($o) => $o['name'], $objs),
      'series' => [['name' => __('Расходы'), 'data' => array_map(fn ($o) => round($o['out_month']), $objs), 'color' => 'c1'], ['name' => __('Поступления'), 'data' => array_map(fn ($o) => round($o['in_month']), $objs), 'color' => 'c3']]]) }}"></div>
  </div>
  @endif
  @include('partials.insights', ['topic' => 'objects', 'class' => count($objs) > 1 ? '' : 'w12', 'title' => __('ИИ-анализ объектов')])
</div>

<div class="grid g2" style="margin-top:20px">
  <div class="card">
    <div class="card-h"><h3>{!! ic('arrow-up-right') !!}{{ __('Крупные расходы за день') }}</h3></div>
    @if ($s['top'])
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Расход') }}</th><th>{{ __('Объект') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
      @foreach ($s['top'] as $t)
      <tr><td><b>{{ $t['desc'] ?: __($t['cat']) }}</b><br><small>{{ __($t['cat']) }}{{ $t['who'] ? ' · '.$t['who'] : '' }}</small></td><td>{{ $t['obj'] }}</td><td class="n"><b>{{ money_f($t['amount'], $cur) }}</b></td></tr>
      @endforeach
    </tbody></table></div>
    @else
      <div class="empty">{!! ic('circle-check') !!}<div>{{ __('Расходов не было') }}</div></div>
    @endif
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('receipt') !!}{{ __('На что ушли деньги') }}</h3><span class="muted">{{ $isToday ? __('сегодня') : dmy($s['date']) }} · {{ $m($T['out_day']) }}</span></div>
    @forelse (array_slice($s['cats_day'], 0, 8, true) as $cat => $v)
      <div class="hbar"><div class="hb-t"><span>{{ __($cat) }}</span><b>{{ $m($v) }} · {{ round($v / max(1, $T['out_day']) * 100) }}%</b></div><div class="hb-r"><i style="width:{{ round($v / max(1, $T['out_day']) * 100, 1) }}%"></i></div></div>
    @empty
      <div class="empty">{!! ic('receipt') !!}<div>{{ __('За этот день расходов нет') }}</div></div>
    @endforelse
  </div>
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('building-2') !!}{{ __('Объекты компании') }}</h3><a class="btn btn-s" href="/crm#objects">{{ __('Открыть в программе') }}{!! ic('arrow-right') !!}</a></div>
  @if ($s['objects'])
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Объект') }}</th><th class="n">{{ __('Касса') }}</th><th class="n">{{ __('За день') }}</th><th class="n">{{ __('Вчера') }}</th><th class="n">{{ __('Месяц: расход') }}</th><th class="n">{{ __('Месяц: приход') }}</th><th>{{ __('Готовность') }}</th></tr></thead><tbody>
    @foreach ($s['objects'] as $o)
    <tr>
      <td><b>{{ $o['name'] }}</b>@if ($o['demo']) <span class="badge plain">{{ __('демо') }}</span>@endif<br><small>{{ __($o['status']) }} · {{ __('сотрудников') }}: {{ $o['staff'] }}@if ($o['overdue']) · <span class="bad">{{ __('просрочено этапов') }}: {{ $o['overdue'] }}</span>@endif</small></td>
      <td class="n {{ $o['balance'] < 0 ? 'bad' : '' }}"><b>{{ $m($o['balance']) }}</b></td>
      <td class="n">{{ $o['out_day'] ? $m($o['out_day']) : '—' }}</td>
      <td class="n">{{ $o['out_yest'] ? $m($o['out_yest']) : '—' }}</td>
      <td class="n">{{ $m($o['out_month']) }}</td>
      <td class="n ok">{{ $m($o['in_month']) }}</td>
      <td style="min-width:130px"><div class="flex" style="gap:10px"><div class="prog grow"><i style="width:{{ $o['progress'] }}%"></i></div><span class="num">{{ $o['progress'] }}%</span></div></td>
    </tr>
    @endforeach
  </tbody></table></div>
  @else
    <div class="empty">{!! ic('building-2') !!}<div>{{ __('Объектов пока нет — добавьте первый в программе') }}</div></div>
  @endif
</div>
@endsection
