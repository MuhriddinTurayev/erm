@extends('layouts.panel')
@section('title', __('Касса и сводка'))
@php
    $T = $s['totals']; $cur = $s['currency'];
    $m = fn ($v) => money_s($v, $cur);
    $isToday = $s['date'] === $ws->localNow()->toDateString();
    $maxDay = max(1, ...array_values($s['days14']));
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
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('wallet') !!}{{ __('Деньги на счету (касса)') }}</div><div class="k-v" title="{{ money_f($T['balance'], $cur) }}">{{ $m($T['balance']) }}</div><div class="k-s">{{ __('по всем объектам') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('trending-down') !!}{{ $isToday ? __('Потрачено сегодня') : __('Потрачено за день') }}</div><div class="k-v" title="{{ money_f($T['out_day'], $cur) }}">{{ $m($T['out_day']) }}</div>
    <div class="k-s">@if ($s['diff_pct'] !== null)<span class="delta {{ $s['diff_pct'] > 0 ? 'up' : 'down' }}">{{ $s['diff_pct'] > 0 ? '+' : '−' }}{{ abs($s['diff_pct']) }}%</span> {{ __('к вчера') }} · @endif{{ __('операций') }}: {{ $T['ops_day'] }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('clock') !!}{{ __('Потрачено вчера') }}</div><div class="k-v" title="{{ money_f($T['out_yest'], $cur) }}">{{ $m($T['out_yest']) }}</div><div class="k-s">{{ dmy($s['yesterday']) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('trending-up') !!}{{ __('Поступило за день') }}</div><div class="k-v ok">{{ $m($T['in_day']) }}</div><div class="k-s">{{ __('продажи, инвестиции, кредиты') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('calendar') !!}{{ __('Расходы за 7 дней') }}</div><div class="k-v">{{ $m($T['out_week']) }}</div><div class="k-s">{{ __('в среднем') }} {{ $m($T['out_week'] / 7) }} {{ __('в день') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('calendar-days') !!}{{ __('За месяц') }}</div><div class="k-v">{{ $m($T['out_month']) }}</div><div class="k-s">{{ __('поступило') }} {{ $m($T['in_month']) }}</div></div>
</div>

<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('chart-column') !!}{{ __('Расходы по дням (14 дней)') }}</h3><span class="muted num">{{ __('итого') }} {{ $m(array_sum($s['days14'])) }}</span></div>
    <div class="bars">
      @foreach ($s['days14'] as $d => $v)
        <div class="b {{ $d === $s['date'] ? 'today' : '' }}" title="{{ dmy($d) }}: {{ money_f($v, $cur) }}"><i style="height:{{ round($v / $maxDay * 100, 1) }}%"></i><span>{{ substr($d, 8, 2) }}.{{ substr($d, 5, 2) }}</span></div>
      @endforeach
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('chart-pie') !!}{{ __('На что ушли деньги') }}</h3><span class="muted">{{ $isToday ? __('сегодня') : dmy($s['date']) }}</span></div>
    @forelse (array_slice($s['cats_day'], 0, 7, true) as $cat => $v)
      <div class="hbar"><div class="hb-t"><span>{{ __($cat) }}</span><b>{{ $m($v) }} · {{ round($v / max(1, $T['out_day']) * 100) }}%</b></div><div class="hb-r"><i style="width:{{ round($v / max(1, $T['out_day']) * 100, 1) }}%"></i></div></div>
    @empty
      <div class="empty">{!! ic('receipt') !!}<div>{{ __('За этот день расходов нет') }}</div></div>
    @endforelse
  </div>
</div>

<div class="grid g2 mt">
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
    <div class="card-h"><h3>{!! ic('calendar-days') !!}{{ __('Расходы месяца по статьям') }}</h3><span class="muted num">{{ $m($T['out_month']) }}</span></div>
    @forelse (array_slice($s['cats_month'], 0, 8, true) as $cat => $v)
      <div class="hbar alt"><div class="hb-t"><span>{{ __($cat) }}</span><b>{{ $m($v) }} · {{ round($v / max(1, $T['out_month']) * 100) }}%</b></div><div class="hb-r"><i style="width:{{ round($v / max(1, $T['out_month']) * 100, 1) }}%"></i></div></div>
    @empty
      <div class="empty">{{ __('В этом месяце расходов нет') }}</div>
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
