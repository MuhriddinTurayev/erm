@extends('layouts.panel')
@section('title', __('Заявка на оплату').' №'.$pr->number)
@php
  $u = auth()->user(); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c);
  $stl = ['pending' => __('на согласовании'), 'approved' => __('утверждена'), 'rejected' => __('отклонена'), 'paid' => __('оплачена'), 'cancelled' => __('отменена')];
  $roles = config('oftob.roles'); $dec = $pr->approvals->keyBy('step');
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/payments">{!! ic('arrow-left') !!}{{ __('Все заявки') }}</a>
@endsection
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{{ sys_tr($pr->purpose) }}</h3><span class="st st-{{ $pr->status }}">{{ $stl[$pr->status] ?? $pr->status }}</span></div>
    <div class="appr-sum" style="font-size:30px;margin-bottom:14px">{{ $M($pr->amount, $pr->currency) }}</div>
    <dl class="kv">
      <dt>{{ __('Объект') }}</dt><dd>{{ $pr->project_id ? ($projects[$pr->project_id] ?? $pr->project_id) : __('Общие расходы компании') }}</dd>
      <dt>{{ __('Получатель') }}</dt><dd>@if ($pr->counterparty)<a href="/company/parties/{{ $pr->counterparty_id }}">{{ $pr->counterparty->name }}</a>@else — @endif</dd>
      @if ($pr->contract)<dt>{{ __('Договор') }}</dt><dd><a href="/company/contracts/{{ $pr->contract_id }}">№{{ $pr->contract->number }}</a> · {{ $pr->contract->subject }}</dd>@endif
      <dt>{{ __('Статья') }}</dt><dd>{{ $pr->category ? __($pr->category->name) : '—' }}</dd>
      <dt>{{ __('Оплатить до') }}</dt><dd>{{ $pr->due_date?->format('d.m.Y') ?? '—' }}</dd>
      <dt>{{ __('Автор') }}</dt><dd>{{ $pr->user?->name }} · {{ $pr->created_at->format('d.m.Y H:i') }}</dd>
      @if ($pr->cashOp)<dt>{{ __('Оплачено') }}</dt><dd>{{ $pr->cashOp->date->format('d.m.Y') }} · {{ __($pr->cashOp->desk?->name ?? '') }} · {{ $M($pr->cashOp->amount, $pr->cashOp->desk?->currency ?? 'UZS') }}</dd>@endif
    </dl>
    @if (in_array($pr->counterparty?->status, ['blocked', 'suspicious'], true))
      <div class="warn-line mt">{!! ic('shield-alert') !!}<div><b>{{ __('Проблемный контрагент') }}:</b> {{ $pr->counterparty->status_reason }}</div></div>
    @endif
    @if ($over)
      <div class="warn-line mt">{!! ic('triangle-alert') !!}<div>{{ __('Оплата превысит месячный лимит статьи «:c»: лимит :l, уже потрачено :s.', ['c' => __($over[2]), 'l' => number_format($over[0], 0, '.', ' '), 's' => number_format($over[1], 0, '.', ' ')]) }}</div></div>
    @endif
  </div>

  <div class="card">
    <div class="card-h"><h3>{!! ic('list-checks') !!}{{ __('Согласование') }}</h3></div>
    <div style="display:grid;gap:8px">
      <div class="chain"><span class="c-s done">{!! ic('check', '', 14) !!}{{ $pr->user?->name }} — {{ __('создал') }}</span></div>
      @foreach ((array) $pr->chain as $i => $r)
        @php $a = $dec[$i] ?? null; $cls = $a ? ($a->decision === 'approve' ? 'done' : 'bad') : ($pr->status === 'pending' && $pr->step === $i ? 'cur' : ''); @endphp
        <div class="chain"><span class="c-s {{ $cls }}">{!! ic($a ? ($a->decision === 'approve' ? 'check' : 'x') : 'clock', '', 14) !!}{{ __($roles[$r] ?? $r) }}@if ($a) — {{ $a->user?->name }}, {{ $a->created_at->format('d.m H:i') }}@endif</span></div>
        @if ($a?->comment)<small class="muted" style="padding-left:12px">«{{ $a->comment }}»</small>@endif
      @endforeach
      @foreach ($pr->approvals->where('step', '>=', count((array) $pr->chain)) as $a)
        <div class="chain"><span class="c-s {{ $a->decision === 'approve' ? 'done' : 'bad' }}">{{ $a->user?->name }}</span></div>
      @endforeach
    </div>

    @if ($canDecide)
    <form method="post" action="/company/payments/{{ $pr->id }}/decide" class="mt" style="display:grid;gap:10px">@csrf
      <input class="input" name="comment" maxlength="300" placeholder="{{ __('Комментарий (необязательно)') }}">
      <div class="flex"><button class="btn btn-p" name="action" value="approve">{!! ic('check') !!}{{ $u->isAdmin() ? __('Утвердить') : __('Согласовать') }}</button>
        <button class="btn btn-d" name="action" value="reject">{!! ic('x') !!}{{ __('Отклонить') }}</button></div>
    </form>
    @endif

    @if ($pr->status === 'approved' && ($u->isAdmin() || in_array($u->role, ['accountant', 'cashier'], true)))
    <form method="post" action="/company/payments/{{ $pr->id }}/pay" class="mt form" style="grid-template-columns:1fr 1fr">@csrf
      <label class="field full"><span>{{ __('Оплатить из кассы') }}</span><select name="cash_desk_id">@foreach ($desks as $d)<option value="{{ $d->id }}">{{ __($d->name) }} — {{ $M($balances[$d->id] ?? 0, $d->currency) }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Дата') }}</span><input type="date" name="date" value="{{ $ws->localNow()->toDateString() }}"></label>
      <div class="field"><span>&nbsp;</span><button class="btn btn-p">{!! ic('wallet') !!}{{ __('Оплатить') }}</button></div>
    </form>
    @endif

    @if (in_array($pr->status, ['pending', 'approved'], true) && ($u->isAdmin() || $pr->user_id === $u->id))
    <form method="post" action="/company/payments/{{ $pr->id }}/cancel" class="mt" data-confirm="{{ __('Отменить заявку?') }}">@csrf<button class="btn btn-s btn-ghost">{{ __('Отменить заявку') }}</button></form>
    @endif
  </div>
</div>
@include('company.erp._thread')
@endsection
