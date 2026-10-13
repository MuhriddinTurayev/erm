@extends('layouts.panel')
@section('title', __('Договор').' №'.$c->number)
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $M = fn ($v, $x) => \App\Services\Erp\Money::fmt($v, $x);
  $stl = ['draft' => 'черновик', 'active' => 'действует', 'done' => 'исполнен', 'cancelled' => 'расторгнут'];
  $total = \App\Services\Erp\Money::toBase($ws, $c->amount, $c->currency); $paid = $total - $c->debt; $pct = $total > 0 ? min(100, round($paid / $total * 100)) : 0;
  $prl = ['pending' => __('на согласовании'), 'approved' => __('утверждена'), 'rejected' => __('отклонена'), 'paid' => __('оплачена'), 'cancelled' => __('отменена')];
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/contracts">{!! ic('arrow-left') !!}{{ __('Все договоры') }}</a>
@if ($u->canEdit())<button class="btn btn-s" data-open="dlg-ct">{!! ic('pencil') !!}{{ __('Изменить') }}</button>
@if ($c->direction === 'out' && $c->status === 'active' && $u->canModule('payments'))<button class="btn btn-p btn-s" data-open="dlg-pr">{!! ic('file-check') !!}{{ __('Заявка на оплату') }}</button>@endif @endif
@endsection
@section('content')
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('file-pen-line') !!}{{ __('Сумма договора') }}</div><div class="k-v">{{ $M($c->amount, $c->currency) }}</div><div class="k-s">{{ $c->date->format('d.m.Y') }} · <span class="st st-{{ $c->status }}">{{ __($stl[$c->status] ?? $c->status) }}</span></div></div>
  <div class="kpi"><div class="k-l">{!! ic('circle-check') !!}{{ $c->direction === 'out' ? __('Мы оплатили') : __('Нам оплатили') }}</div><div class="k-v ok">{{ money_s($paid, $cur) }}</div><div class="prog"><i style="width:{{ $pct }}%"></i></div><div class="k-s">{{ $pct }}%</div></div>
  <div class="kpi"><div class="k-l">{!! ic('scale') !!}{{ __('Остаток') }}</div><div class="k-v {{ $c->debt > 0 && $c->due_date?->isPast() ? 'out-c' : '' }}">{{ money_s(max(0, $c->debt), $cur) }}</div><div class="k-s">{{ __('срок') }}: {{ $c->due_date?->format('d.m.Y') ?? '—' }}</div></div>
</div>
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{{ $c->subject }}</h3></div>
    <dl class="kv">
      <dt>{{ __('Контрагент') }}</dt><dd><a href="/company/parties/{{ $c->counterparty_id }}">{{ $c->counterparty?->name }}</a>@if (in_array($c->counterparty?->status, ['blocked', 'suspicious'], true)) <span class="st st-blocked">{{ __('проблемный') }}</span>@endif</dd>
      <dt>{{ __('Направление') }}</dt><dd>{{ $c->direction === 'out' ? __('Мы платим') : __('Нам платят') }}</dd>
      <dt>{{ __('Объект') }}</dt><dd>{{ $c->project_id ? ($projects[$c->project_id] ?? '—') : '—' }}</dd>
      <dt>{{ __('Валюта') }}</dt><dd>{{ $c->currency }}</dd>
    </dl>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('globe-lock') !!}Didox / {{ __('ЭДО') }}</h3></div>
    @if ($didox)
      <p class="muted">{{ __('Подписание через Didox подключено.') }}</p>
    @else
      <p class="muted" style="margin:0">{{ __('Электронное подписание договоров и счетов-фактур через Didox. Чтобы включить, руководитель указывает API-ключ Didox в настройках компании.') }}</p>
    @endif
  </div>
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('wallet') !!}{{ __('Оплаты по договору') }}</h3></div>
    @forelse ($ops as $o)
      <div class="list-row"><div><b>{{ $o->date->format('d.m.Y') }}</b> · {{ __($o->desk?->name ?? '') }}<br><small>{{ sys_tr($o->note) }} · {{ $o->user?->name }}</small></div><b class="{{ $o->type === 'in' ? 'in-c' : 'out-c' }}">{{ $M($o->amount, $o->desk?->currency ?? 'UZS') }}</b></div>
    @empty
      <div class="empty">{{ __('Оплат ещё не было') }}</div>
    @endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('history') !!}{{ __('История договора') }}</h3></div>
    @foreach ($requests as $r)
      <div class="list-row"><div><a href="/company/payments/{{ $r->id }}">{{ __('Заявка') }} №{{ $r->number }}</a> · {{ $M($r->amount, $r->currency) }}<br><small>{{ $r->user?->name }} · {{ $r->created_at->format('d.m.Y') }}</small></div><span class="st st-{{ $r->status }}">{{ $prl[$r->status] ?? $r->status }}</span></div>
    @endforeach
    @foreach ($history as $h)
      <div class="list-row"><div>{{ $h->summary }}<br><small>{{ $h->user?->name }} · {{ $h->created_at?->format('d.m.Y H:i') }}</small></div></div>
    @endforeach
    @if (! $requests->count() && ! $history->count())<div class="empty">{{ __('Пока пусто') }}</div>@endif
  </div>
</div>
@include('company.erp._thread')
@include('company.erp._contract_form', ['c' => $c, 'counterparties' => null])

@if ($c->direction === 'out')
<dialog class="modal" id="dlg-pr"><form method="post" action="/company/payments">@csrf
  <input type="hidden" name="contract_id" value="{{ $c->id }}"><input type="hidden" name="counterparty_id" value="{{ $c->counterparty_id }}"><input type="hidden" name="project_id" value="{{ $c->project_id }}"><input type="hidden" name="currency" value="{{ $c->currency }}">
  <div class="modal-h"><h3>{!! ic('file-check') !!}{{ __('Заявка на оплату по договору') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Сумма') }}, {{ $c->currency }}</span><input type="number" step="0.01" min="0.01" name="amount" required value="{{ $c->debt > 0 ? round($c->debt / \App\Services\Erp\Money::rate($ws, $c->currency), 2) : '' }}"></label>
    <label class="field"><span>{{ __('Оплатить до') }}</span><input type="date" name="due_date" value="{{ $c->due_date?->toDateString() }}"></label>
    <label class="field full"><span>{{ __('Назначение платежа') }}</span><input name="purpose" required maxlength="300" value="{{ __('Оплата по договору') }} №{{ $c->number }}"></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('send') !!}{{ __('Отправить') }}</button></div>
</form></dialog>
@endif
@endsection
