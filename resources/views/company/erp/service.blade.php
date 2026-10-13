@extends('layouts.panel')
@section('title', __('Сервисная заявка').' №'.$so->number)
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws);
  $stl = ['new' => 'новая', 'work' => 'в работе', 'done' => 'выполнена', 'cancelled' => 'отменена'];
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/services">{!! ic('arrow-left') !!}{{ __('Все заявки') }}</a>
@if ($u->canEdit())<button class="btn btn-s" data-open="dlg-so">{!! ic('pencil') !!}{{ __('Изменить') }}</button>
@if ($so->status !== 'done')<form method="post" action="/company/services">@csrf<input type="hidden" name="id" value="{{ $so->id }}"><input type="hidden" name="quick" value="1"><button class="btn btn-p btn-s" name="status" value="{{ $so->status === 'new' ? 'work' : 'done' }}">{!! ic($so->status === 'new' ? 'circle-play' : 'check') !!}{{ $so->status === 'new' ? __('Взять в работу') : __('Выполнено') }}</button></form>@endif
@endif
@endsection
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{{ $so->title }}</h3><span class="st st-{{ $so->status }}">{{ __($stl[$so->status] ?? $so->status) }}</span></div>
    @if ($so->description)<p style="white-space:pre-wrap;margin-top:0">{{ $so->description }}</p>@endif
    <dl class="kv">
      <dt>{{ __('Объект') }}</dt><dd>{{ $so->project_id ? ($projects[$so->project_id] ?? '—') : '—' }}</dd>
      <dt>{{ __('Подрядчик') }}</dt><dd>@if ($so->counterparty)<a href="/company/parties/{{ $so->counterparty_id }}">{{ $so->counterparty->name }}</a>@else — @endif</dd>
      <dt>{{ __('Ответственный') }}</dt><dd>{{ $so->assignee?->name ?? '—' }}</dd>
      <dt>{{ __('Срок') }}</dt><dd>{{ $so->due_date?->format('d.m.Y') ?? '—' }}</dd>
      <dt>{{ __('Стоимость') }}</dt><dd>{{ $so->amount ? money_s($so->amount, $cur) : '—' }}</dd>
      <dt>{{ __('Создал') }}</dt><dd>{{ $so->user?->name }} · {{ $so->created_at->format('d.m.Y H:i') }}</dd>
    </dl>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('history') !!}{{ __('История') }}</h3></div>
    @forelse ($history as $h)
      <div class="list-row"><div>{{ \Illuminate\Support\Str::after($h->summary, ': ') }}<br><small>{{ $h->user?->name }} · {{ $h->created_at?->format('d.m.Y H:i') }}</small></div></div>
    @empty<div class="muted">—</div>@endforelse
  </div>
</div>
@include('company.erp._thread')
@include('company.erp._service_form', ['so' => $so])
@endsection
