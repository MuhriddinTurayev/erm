@extends('layouts.panel')
@section('title', $item->name)
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $n = fn ($v) => \App\Services\Erp\Stock::n($v);
  $ops = ['in' => __('Приход'), 'out' => __('Выдача'), 'transfer' => __('Перемещение'), 'adjust' => __('Инвентаризация')];
  $allItems = collect([$item]); $counterparties = \App\Models\Counterparty::of($ws->id)->orderBy('name')->get();
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/stock">{!! ic('arrow-left') !!}{{ __('Склад') }}</a>
<a class="btn btn-s" href="/company/stock/labels?ids={{ $item->id }}&n=6" target="_blank">{!! ic('qr-code') !!}{{ __('Этикетки') }}</a>
@if ($u->canEdit())
<button class="btn btn-s" data-open="dlg-item" data-fill="{{ json_encode(['id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'sku' => $item->sku, 'group' => $item->group, 'min_qty' => $item->min_qty ?: '', 'price' => $item->price ?: '']) }}">{!! ic('pencil') !!}{{ __('Изменить') }}</button>
<button class="btn btn-p btn-s" data-open="dlg-mv" data-fill='{"op":"out"}'>{!! ic('package-minus') !!}{{ __('Выдать') }}</button>
@endif
@endsection
@section('content')
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('package') !!}{{ __('Всего на складах') }}</div><div class="k-v {{ $item->min_qty > 0 && array_sum($bal) < $item->min_qty ? 'out-c' : '' }}">{{ $n(array_sum($bal)) }} {{ __($item->unit ?? '') }}</div>@if ($item->min_qty > 0)<div class="k-s">{{ __('минимум') }}: {{ $n($item->min_qty) }}</div>@endif</div>
  <div class="kpi"><div class="k-l">{!! ic('coins') !!}{{ __('Средняя цена закупки') }}</div><div class="k-v">{{ money_s($avg, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('wallet') !!}{{ __('Стоимость остатка') }}</div><div class="k-v">{{ money_s(array_sum($bal) * $avg, $cur) }}</div></div>
</div>
<div class="grid g2">
  @foreach ($whs as $w)
    @continue(empty($bal[$w->id]) && empty($batches[$w->id]))
    <div class="card"><div class="card-h"><h3>{!! ic('warehouse') !!}{{ __($w->name) }}</h3><b>{{ $n($bal[$w->id] ?? 0) }} {{ __($item->unit ?? '') }}</b></div>
      @forelse ($batches[$w->id] as $b)
        <div class="list-row"><div>{{ __('Партия') }} <b>{{ $b['batch'] }}</b><br><small>{{ \Carbon\Carbon::parse($b['d'])->format('d.m.Y') }} · {{ money_s($b['price'], $cur) }}</small></div><b>{{ $n($b['q']) }} {{ __($item->unit ?? '') }}</b></div>
      @empty<div class="muted">{{ __('Без партий') }}</div>@endforelse
    </div>
  @endforeach
</div>
<div class="card mt">
  <div class="card-h"><h3>{!! ic('history') !!}{{ __('История движения') }}</h3></div>
  <p class="muted" style="font-size:13px;margin:-4px 0 10px">{{ __('«+» — пришло на склад, «−» — ушло со склада (выдано на объект, перемещено или списано). Ниже числа — сколько осталось на этом складе после операции.') }}</p>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Операция') }}</th><th>{{ __('Склад') }}</th><th class="n">{{ __('Кол-во') }}</th><th class="n">{{ __('Цена') }}</th><th>{{ __('Партия') }}</th><th>{{ __('Куда / от кого') }}</th><th>{{ __('Кто') }}</th></tr></thead><tbody>
  @foreach ($moves as $m)
    <tr><td>{{ $m->date->format('d.m.Y') }}</td><td>{{ $ops[$m->type] ?? $m->type }}</td><td>{{ __($m->warehouse?->name ?? '') }}</td><td class="n nowrap"><b class="{{ $m->qty > 0 ? 'in-c' : 'out-c' }}">{{ $m->qty > 0 ? '+' : '−' }}{{ $n(abs($m->qty)) }}</b>@isset($ml['after'][$m->id])<br><small class="muted">{{ __('осталось') }} {{ $n($ml['after'][$m->id]) }}</small>@endisset</td>
      <td class="n">{{ $m->price ? money_s($m->price, $cur) : '' }}</td><td>{{ $m->batch ?: ($ml['batch'][$m->id] ?? '—') }}</td><td>{{ $m->project_id ? ($projects[$m->project_id] ?? '') : ($m->counterparty?->name ?? ($ml['route'][$m->id] ?? '')) }} <small>{{ sys_tr($m->note) }}</small></td><td>{{ $m->user?->name }}</td></tr>
  @endforeach
  </tbody></table></div>
</div>
@include('company.erp._stock_forms')
@endsection
