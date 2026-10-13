@extends('layouts.panel')
@section('title', __('Склад'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $n = fn ($v) => \App\Services\Erp\Stock::n($v);
  $ops = ['in' => __('Приход'), 'out' => __('Выдача на объект'), 'transfer' => __('Перемещение'), 'adjust' => __('Инвентаризация')];
  $value = 0; foreach ($items as $it) { $value += array_sum($bal[$it->id] ?? []) * $it->price; }
@endphp
@section('actions')
@if ($u->canEdit())
<button class="btn btn-s" data-open="dlg-mv" data-fill='{"op":"in"}'>{!! ic('package-plus') !!}{{ __('Приход') }}</button>
<button class="btn btn-s" data-open="dlg-mv" data-fill='{"op":"out"}'>{!! ic('package-minus') !!}{{ __('Выдать') }}</button>
<button class="btn btn-s" data-open="dlg-mv" data-fill='{"op":"transfer"}'>{!! ic('arrow-left-right') !!}{{ __('Переместить') }}</button>
<button class="btn btn-p btn-s" data-open="dlg-item">{!! ic('plus') !!}{{ __('Товар') }}</button>
@endif
@endsection
@section('content')
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('package') !!}{{ __('Позиций') }}</div><div class="k-v">{{ $items->count() }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('coins') !!}{{ __('Стоимость остатков') }}</div><div class="k-v">{{ money_s($value, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('triangle-alert') !!}{{ __('Заканчивается') }}</div><div class="k-v {{ count($low) ? 'out-c' : '' }}">{{ count($low) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Выдано на объекты') }}</div><div class="k-v">{{ money_s(array_sum($costs), $cur) }}</div></div>
</div>

<div class="chips" style="margin-bottom:14px">
  <a class="chip {{ ! $wid ? 'on' : '' }}" href="/company/stock">{!! ic('warehouse', '', 14) !!}{{ __('Все склады') }}</a>
  @foreach ($whs as $w)<a class="chip {{ $wid === $w->id ? 'on' : '' }}" href="?wh={{ $w->id }}">{{ __($w->name) }}@if ($w->project_id) · {{ $projects[$w->project_id] ?? '' }}@endif</a>@endforeach
  @if ($u->isAdmin())<button class="chip" data-open="dlg-wh">{!! ic('plus', '', 14) !!}{{ __('Склад') }}</button>@endif
</div>

<div class="card">
  <form method="get" class="toolbar">
    @if ($wid)<input type="hidden" name="wh" value="{{ $wid }}">@endif
    <input class="input grow" name="q" value="{{ $q }}" placeholder="{{ __('Название, артикул или группа') }}">
    <button class="btn btn-s">{!! ic('search') !!}</button>
    <a class="btn btn-s btn-ghost" href="/company/stock/labels" target="_blank">{!! ic('qr-code') !!}{{ __('Этикетки QR') }}</a>
  </form>
  @if ($items->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Товар') }}</th><th>{{ __('Группа') }}</th>
    @if ($wid)<th class="n">{{ __('Остаток') }}</th>@else @foreach ($whs as $w)<th class="n">{{ __($w->name) }}</th>@endforeach<th class="n">{{ __('Всего') }}</th>@endif
    <th class="n">{{ __('Цена') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
  @foreach ($items as $it)
    @php $b = array_intersect_key($bal[$it->id] ?? [], array_flip($whs->pluck('id')->all())); $tot = $wid ? ($b[$wid] ?? 0) : array_sum($b); @endphp
    @continue(! $wid && ! $b && auth()->user()->allowedObjects() !== null)
    @continue($wid && ! $tot)
    <tr class="row-link" data-href="/company/stock/items/{{ $it->id }}">
      <td><b>{{ $it->name }}</b>@if ($it->sku)<br><small>{{ $it->sku }}</small>@endif</td><td>{{ $it->group ? __($it->group) : '—' }}</td>
      @if ($wid)<td class="n"><b>{{ $n($tot) }}</b> {{ __($it->unit ?? '') }}</td>@else
        @foreach ($whs as $w)<td class="n">{{ isset($b[$w->id]) && $b[$w->id] != 0 ? $n($b[$w->id]) : '·' }}</td>@endforeach
        <td class="n"><b class="{{ $it->min_qty > 0 && $tot < $it->min_qty ? 'out-c' : '' }}">{{ $n($tot) }}</b> {{ __($it->unit ?? '') }}</td>@endif
      <td class="n">{{ $it->price ? money_s($it->price, $cur) : '—' }}</td><td class="n">{{ $it->price ? money_s($tot * $it->price, $cur) : '—' }}</td>
    </tr>
  @endforeach
  </tbody></table></div>
  @else
  <div class="empty">{!! ic('package') !!}<div>{{ __('Товаров пока нет. Нажмите «Товар», чтобы добавить материал с начальным остатком.') }}</div></div>
  @endif
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('history') !!}{{ __('Последние движения') }}</h3></div>
  @if ($moves->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Операция') }}</th><th>{{ __('Товар') }}</th><th>{{ __('Склад') }}</th><th class="n">{{ __('Кол-во') }}</th><th>{{ __('Партия') }}</th><th>{{ __('Куда / от кого') }}</th></tr></thead><tbody>
  @foreach ($moves as $m)
    <tr><td class="nowrap">{{ $m->date->format('d.m.Y') }}<br><small>{{ $m->user?->name }}</small></td><td><span class="st st-{{ ['in' => 'done', 'out' => 'pending', 'transfer' => 'approved', 'adjust' => 'draft'][$m->type] }}">{{ $ops[$m->type] ?? $m->type }}</span></td>
      <td>{{ $m->item?->name }}</td><td>{{ __($m->warehouse?->name ?? '') }}</td><td class="n"><b class="{{ $m->qty > 0 ? 'in-c' : 'out-c' }}">{{ $m->qty > 0 ? '+' : '' }}{{ $n($m->qty) }}</b> {{ __($m->item?->unit ?? '') }}</td>
      <td>{{ $m->batch ?: '—' }}</td><td>{{ $m->project_id ? ($projects[$m->project_id] ?? '') : ($m->counterparty?->name ?? '') }} <small>{{ sys_tr($m->note) }}</small></td></tr>
  @endforeach
  </tbody></table></div>
  @else<div class="empty">{{ __('Движений пока нет') }}</div>@endif
</div>

@include('company.erp._stock_forms')
@endsection
