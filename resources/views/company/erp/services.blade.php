@extends('layouts.panel')
@section('title', __('Сервисные заявки'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws);
  $stl = ['new' => 'новая', 'work' => 'в работе', 'done' => 'выполнена', 'cancelled' => 'отменена'];
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-so">{!! ic('plus') !!}{{ __('Новая заявка') }}</button>@endif
@endsection
@section('content')
<div class="chips" style="margin-bottom:14px">
  <a class="chip {{ ! $st ? 'on' : '' }}" href="/company/services">{{ __('Все') }}</a>
  @foreach ($stl as $k => $v)<a class="chip {{ $st === $k ? 'on' : '' }}" href="?status={{ $k }}">{{ __($v) }}<span class="n">{{ $counts[$k] ?? 0 }}</span></a>@endforeach
</div>
<div class="card">
  @if ($list->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>№</th><th>{{ __('Заявка') }}</th><th>{{ __('Объект') }}</th><th>{{ __('Исполнитель') }}</th><th class="n">{{ __('Сумма') }}</th><th>{{ __('Срок') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($list as $s)
    <tr class="row-link" data-href="/company/services/{{ $s->id }}"><td><b>{{ $s->number }}</b></td><td>{{ $s->title }}<br><small>{{ $s->created_at->format('d.m.Y') }}</small></td>
      <td>{{ $s->project_id ? ($projects[$s->project_id] ?? '') : '—' }}</td><td>{{ $s->counterparty?->name ?? $s->assignee?->name ?? '—' }}</td>
      <td class="n">{{ $s->amount ? money_s($s->amount, $cur) : '—' }}</td><td class="{{ $s->due_date?->isPast() && in_array($s->status, ['new', 'work']) ? 'out-c' : '' }}">{{ $s->due_date?->format('d.m.Y') ?? '—' }}</td>
      <td><span class="st st-{{ $s->status }}">{{ __($stl[$s->status] ?? $s->status) }}</span></td></tr>
  @endforeach
  </tbody></table></div>
  <div class="mt">{{ $list->links() }}</div>
  @else
  <div class="empty">{!! ic('wrench') !!}<div>{{ __('Заявок нет. Здесь — ремонт техники, гарантийные обращения покупателей, работы подрядчиков.') }}</div></div>
  @endif
</div>
@include('company.erp._service_form', ['so' => null])
@endsection
