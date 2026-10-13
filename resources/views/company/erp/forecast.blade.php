@extends('layouts.panel')
@section('title', __('Прогноз денег'))
@php $fx = max(1, max(array_map(fn ($r) => max(abs($r['close']), $r['in'], $r['out']), $rows))); $neg = collect($rows)->first(fn ($r) => $r['close'] < 0); @endphp
@section('content')
<div class="alert alert-info">{!! ic('trending-up') !!}<div>{{ __('Прогноз на 12 недель: остаток во всех кассах + платежи покупателей по графикам рассрочки − утверждённые заявки на оплату − остатки по договорам с поставщиками (по сроку).') }}</div></div>
@if ($neg)
<div class="alert alert-bad">{!! ic('triangle-alert') !!}<div><b>{{ __('Кассовый разрыв') }}:</b> {{ __('на неделе с :d не хватит :s. Перенесите платежи или ускорьте сбор денег с покупателей.', ['d' => $neg['from']->format('d.m.Y'), 's' => money_s(abs($neg['close']), $cur)]) }}</div></div>
@endif
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('wallet') !!}{{ __('Сейчас в кассах') }}</div><div class="k-v">{{ money_s($total, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('arrow-down-left') !!}{{ __('Ожидается поступлений') }}</div><div class="k-v in-c">{{ money_s(array_sum(array_column($rows, 'in')), $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('arrow-up-right') !!}{{ __('Ожидается платежей') }}</div><div class="k-v out-c">{{ money_s(array_sum(array_column($rows, 'out')), $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('flag') !!}{{ __('Через 12 недель') }}</div><div class="k-v {{ end($rows)['close'] < 0 ? 'out-c' : '' }}">{{ money_s(end($rows)['close'], $cur) }}</div></div>
</div>
<div class="card">
  <div class="card-h"><h3>{!! ic('chart-column') !!}{{ __('Остаток на конец недели') }}</h3></div>
  <div class="fc" style="height:200px">@foreach ($rows as $r)<div class="b" title="{{ money_s($r['close'], $cur) }}"><i class="{{ $r['close'] < 0 ? 'neg' : '' }}" style="height:{{ max(2, abs($r['close']) / $fx * 100) }}%"></i><small>{{ $r['from']->format('d.m') }}</small></div>@endforeach</div>
</div>
<div class="card mt">
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Неделя') }}</th><th class="n">{{ __('На начало') }}</th><th class="n">{{ __('Поступит') }}</th><th class="n">{{ __('Уйдёт') }}</th><th class="n">{{ __('На конец') }}</th><th>{{ __('Из чего') }}</th></tr></thead><tbody>
  @foreach ($rows as $r)
    <tr><td class="nowrap"><b>{{ $r['from']->format('d.m') }} — {{ $r['from']->copy()->addDays(6)->format('d.m') }}</b></td><td class="n">{{ money_s($r['open'], $cur) }}</td>
      <td class="n in-c">{{ $r['in'] ? '+'.money_s($r['in'], $cur) : '' }}</td><td class="n out-c">{{ $r['out'] ? '−'.money_s($r['out'], $cur) : '' }}</td>
      <td class="n"><b class="{{ $r['close'] < 0 ? 'out-c' : '' }}">{{ money_s($r['close'], $cur) }}</b></td>
      <td><small class="muted">@foreach (array_slice($r['items_in'], 0, 3) as $x){{ $x }}<br>@endforeach @if (count($r['items_in']) > 3)+{{ count($r['items_in']) - 3 }}<br>@endif @foreach (array_slice($r['items_out'], 0, 3) as $x)<span class="out-c">{{ $x }}</span><br>@endforeach</small></td></tr>
  @endforeach
  </tbody></table></div>
</div>
<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('arrow-left-right') !!}{{ __('Взаиморасчёты между объектами') }}</h3></div>
    @forelse ($inter as $r)
      <div class="list-row"><div><b>{{ $r['to'] }}</b> {{ __('должен') }} <b>{{ $r['from'] }}</b></div><b>{{ money_s($r['amount'], $cur) }}</b></div>
    @empty<div class="muted">{{ __('Объекты не платили друг за друга. Назначьте кассам объекты в разделе «Кассы», чтобы видеть взаиморасчёты.') }}</div>@endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('gauge') !!}{{ __('Лимиты по статьям в этом месяце') }}</h3></div>
    @foreach ($limits as $r)
      <div class="hbar"><div class="hb-t"><span>{{ __($r['cat']->name) }}</span><b>{{ money_s($r['spent'], $cur) }}@if ($r['limit'] > 0) / {{ money_s($r['limit'], $cur) }}@endif</b></div>
        <div class="hb-r"><i style="width:{{ $r['limit'] > 0 ? min(100, $r['pct']) : ($r['spent'] > 0 ? 100 : 0) }}%;background:{{ $r['limit'] > 0 && $r['pct'] >= 100 ? 'var(--bad)' : 'var(--chart)' }}"></i></div></div>
    @endforeach
  </div>
</div>
@endsection
