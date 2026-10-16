@extends('layouts.panel')
@section('title', __('Заявки с сайта'))
@section('content')
@php $S = \App\Models\SiteLead::STATUSES; @endphp
<div class="tabs mb">
  <a class="{{ $st === '' ? 'on' : '' }}" href="/admin/leads">{{ __('Все') }} <span class="badge">{{ $counts->except('spam')->sum() }}</span></a>
  @foreach ($S as $k => $n)<a class="{{ $st === $k ? 'on' : '' }}" href="/admin/leads?status={{ $k }}">{{ __($n) }} <span class="badge">{{ $counts[$k] ?? 0 }}</span></a>@endforeach
</div>
<div class="card">
  @if ($list->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Когда') }}</th><th>{{ __('Клиент') }}</th><th>{{ __('Что строит') }}</th><th>{{ __('Расчёт потерь') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
    @foreach ($list as $l)
    <tr>
      <td class="nowrap">{{ $l->created_at->format('d.m.Y H:i') }}<br><small class="muted">{{ strtoupper((string) $l->locale) }}{{ $l->source ? ' · '.$l->source : '' }}</small></td>
      <td><b>{{ $l->name }}</b><br><a class="nowrap" href="tel:{{ preg_replace('/[^+\d]/', '', $l->phone) }}">{{ $l->phone }}</a>@if ($l->company)<br><small>{{ $l->company }}</small>@endif
        @if ($l->role)<br><small class="muted">{{ __(\App\Models\SiteLead::ROLES[$l->role] ?? $l->role) }}</small>@endif</td>
      <td>{{ $l->kind ? __(\App\Models\SiteLead::KINDS[$l->kind] ?? $l->kind) : '—' }}@if ($l->objects)<br><small class="muted">{{ __(\App\Models\SiteLead::OBJECTS[$l->objects] ?? $l->objects) }}</small>@endif</td>
      <td class="small">{{ $l->budget ?: '—' }}</td>
      <td class="lead-st-td">
        <form method="post" action="/admin/leads/{{ $l->id }}" class="lead-st">@csrf
          <select name="status" class="input st-{{ $l->status }}" aria-label="{{ __('Статус') }}" onchange="this.form.requestSubmit()">@foreach ($S as $k => $n)<option value="{{ $k }}" @selected($l->status === $k)>{{ __($n) }}</option>@endforeach</select>
          <input name="note" class="input" value="{{ $l->note }}" maxlength="500" placeholder="{{ __('Заметка') }}" aria-label="{{ __('Заметка') }}">
          <button class="btn btn-s" title="{{ __('Сохранить') }}" aria-label="{{ __('Сохранить') }}">{!! ic('check', '', 15) !!}</button>
        </form>
      </td>
    </tr>
    @endforeach
  </tbody></table></div>
  <div class="mt">{{ $list->links() }}</div>
  @else
    <div class="empty">{!! ic('inbox') !!}<div>{{ __('Заявок пока нет. Они приходят из формы «Бесплатный разбор» на сайте.') }}</div></div>
  @endif
</div>
@endsection
