@extends('layouts.panel')
@section('title', __('Договоры'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c);
  $stl = ['draft' => 'черновик', 'active' => 'действует', 'done' => 'исполнен', 'cancelled' => 'расторгнут'];
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-ct">{!! ic('plus') !!}{{ __('Новый договор') }}</button>@endif
@endsection
@section('content')
<div class="card">
  <form method="get" class="toolbar">
    <input class="input grow" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('Номер, предмет или контрагент') }}">
    <select class="input" name="dir" onchange="this.form.submit()"><option value="">{{ __('Все') }}</option><option value="out" @selected(($f['dir'] ?? '') === 'out')>{{ __('Мы платим (поставщики, подрядчики)') }}</option><option value="in" @selected(($f['dir'] ?? '') === 'in')>{{ __('Нам платят (заказчики)') }}</option></select>
    <select class="input" name="status" onchange="this.form.submit()"><option value="">{{ __('Любой статус') }}</option>@foreach ($stl as $k => $n)<option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ __($n) }}</option>@endforeach</select>
    <select class="input" name="project" onchange="this.form.submit()"><option value="">{{ __('Все объекты') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected(($f['project'] ?? '') === $pid)>{{ $pn }}</option>@endforeach</select>
    <button class="btn btn-s">{!! ic('search') !!}</button>
  </form>
  @if ($list->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>№ / {{ __('дата') }}</th><th>{{ __('Контрагент') }}</th><th>{{ __('Предмет') }}</th><th>{{ __('Объект') }}</th><th class="n">{{ __('Сумма') }}</th><th class="n">{{ __('Оплачено') }}</th><th>{{ __('Срок') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($list as $c)
    @php $total = \App\Services\Erp\Money::toBase($ws, $c->amount, $c->currency); $paid = $total - $c->debt; $pct = $total > 0 ? min(100, round($paid / $total * 100)) : 0; @endphp
    <tr class="row-link" data-href="/company/contracts/{{ $c->id }}">
      <td><b>{{ $c->number }}</b><br><small>{{ $c->date->format('d.m.Y') }} · {!! ic($c->direction === 'out' ? 'arrow-up-right' : 'arrow-down-left', '', 12) !!}</small></td>
      <td>{{ $c->counterparty?->name }}</td><td>{{ \Illuminate\Support\Str::limit($c->subject, 60) }}</td>
      <td>{{ $c->project_id ? ($projects[$c->project_id] ?? '—') : '—' }}</td>
      <td class="n"><b>{{ $M($c->amount, $c->currency) }}</b></td>
      <td class="n" style="min-width:120px">{{ $pct }}%<div class="prog"><i style="width:{{ $pct }}%"></i></div></td>
      <td class="nowrap {{ $c->due_date && $c->due_date->isPast() && $c->status === 'active' && $c->debt > 0 ? 'out-c' : '' }}">{{ $c->due_date?->format('d.m.Y') ?? '—' }}</td>
      <td><span class="st st-{{ $c->status }}">{{ __($stl[$c->status] ?? $c->status) }}</span></td>
    </tr>
  @endforeach
  </tbody></table></div>
  <div class="mt">{{ $list->links() }}</div>
  @else
  <div class="empty">{!! ic('file-pen-line') !!}<div>{{ __('Договоров пока нет') }}</div></div>
  @endif
</div>
@include('company.erp._contract_form', ['c' => null])
@endsection
