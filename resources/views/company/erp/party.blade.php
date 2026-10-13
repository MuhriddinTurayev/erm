@extends('layouts.panel')
@section('title', $cp->name)
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c);
  $kinds = \App\Http\Controllers\Company\PartyController::KINDS; $sts = \App\Http\Controllers\Company\PartyController::STATUSES;
  $paidOut = $ops->where('type', 'out')->sum('amount_base'); $paidIn = $ops->where('type', 'in')->sum('amount_base');
  $debt = $contracts->where('status', 'active')->sum(fn ($c) => ($c->direction === 'out' ? 1 : -1) * $c->debt);
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/parties">{!! ic('arrow-left') !!}{{ __('Все контрагенты') }}</a>
@if ($u->canEdit())<button class="btn btn-s" data-open="dlg-cp">{!! ic('pencil') !!}{{ __('Изменить') }}</button>@endif
@endsection
@section('content')
@if (in_array($cp->status, ['blocked', 'suspicious'], true))
<div class="alert alert-bad">{!! ic('shield-alert') !!}<div><b>{{ __($sts[$cp->status]) }}.</b> {{ $cp->status_reason }}</div></div>
@elseif ($cp->status === 'check')
<div class="alert alert-warn">{!! ic('scan-line') !!}<div>{{ __('Контрагент на проверке. Проверьте ИНН, реквизиты и репутацию, затем отметьте результат.') }}</div></div>
@endif
@if ($same->count())
<div class="alert alert-warn">{!! ic('copy') !!}<div>{{ __('С таким же ИНН в базе есть') }}: @foreach ($same as $s)<a href="/company/parties/{{ $s->id }}">{{ $s->name }}</a>{{ $loop->last ? '' : ', ' }}@endforeach</div></div>
@endif

<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('arrow-up-right') !!}{{ __('Мы заплатили') }}</div><div class="k-v">{{ money_s($paidOut, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('arrow-down-left') !!}{{ __('Мы получили') }}</div><div class="k-v">{{ money_s($paidIn, $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('scale') !!}{{ __('Сальдо по договорам') }}</div><div class="k-v {{ $debt > 0 ? 'out-c' : ($debt < 0 ? 'in-c' : '') }}">{{ money_s(abs($debt), $cur) }}</div><div class="k-s">{{ $debt > 0 ? __('мы должны') : ($debt < 0 ? __('нам должны') : __('расчёты закрыты')) }}</div></div>
</div>

<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('contact') !!}{{ __('Реквизиты') }}</h3><span class="st st-{{ $cp->status }}">{{ __($sts[$cp->status] ?? $cp->status) }}</span></div>
    <dl class="kv">
      <dt>{{ __('Тип') }}</dt><dd>{{ __($kinds[$cp->kind] ?? $cp->kind) }}@if ($cp->folder) · {{ __($cp->folder ?? '') }}@endif</dd>
      <dt>{{ __('ИНН (СТИР)') }}</dt><dd>{{ $cp->inn ?: '—' }}</dd>
      <dt>{{ __('Телефон') }}</dt><dd>@if ($cp->phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $cp->phone) }}">{{ $cp->phone }}</a>@else — @endif</dd>
      <dt>{{ __('Контактное лицо') }}</dt><dd>{{ $cp->contact ?: '—' }}</dd>
      <dt>{{ __('Адрес') }}</dt><dd>{{ $cp->address ?: '—' }}</dd>
      <dt>{{ __('Банк') }}</dt><dd>{{ $cp->bank ?: '—' }}</dd>
      @if ($cp->note)<dt>{{ __('Заметки') }}</dt><dd style="white-space:pre-wrap">{{ $cp->note }}</dd>@endif
    </dl>
  </div>
  @if ($u->isAdmin() || $u->role === 'accountant')
  <div class="card">
    <div class="card-h"><h3>{!! ic('shield-check') !!}{{ __('Проверка контрагента') }}</h3></div>
    <form method="post" action="/company/parties/{{ $cp->id }}/status" style="display:grid;gap:10px">@csrf
      <div class="chips">@foreach ($sts as $k => $n)<label class="chip" style="cursor:pointer"><input type="radio" name="status" value="{{ $k }}" @checked($cp->status === $k)> {{ __($n) }}</label>@endforeach</div>
      <input class="input" name="reason" value="{{ $cp->status_reason }}" maxlength="300" placeholder="{{ __('Причина (обязательно для «подозрительный» и «заблокирован»)') }}">
      <div><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
      <small class="muted">{{ __('Заблокированному контрагенту нельзя оформить договор и оплату; по подозрительному согласующие видят предупреждение.') }}</small>
    </form>
  </div>
  @endif
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('file-pen-line') !!}{{ __('Договоры') }}</h3>@if ($u->canModule('contracts'))<a class="btn btn-s" href="/company/contracts?q={{ urlencode($cp->name) }}">{{ __('Все договоры') }}</a>@endif</div>
  @if ($contracts->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>№</th><th>{{ __('Предмет') }}</th><th class="n">{{ __('Сумма') }}</th><th class="n">{{ __('Остаток') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($contracts as $c)
    <tr class="row-link" data-href="/company/contracts/{{ $c->id }}"><td><b>{{ $c->number }}</b><br><small>{{ $c->date->format('d.m.Y') }}</small></td><td>{{ $c->subject }}</td>
      <td class="n">{{ $M($c->amount, $c->currency) }}</td><td class="n">{{ money_s(max(0, $c->debt), $cur) }}</td><td><span class="st st-{{ $c->status }}">{{ __(['draft' => 'черновик', 'active' => 'действует', 'done' => 'исполнен', 'cancelled' => 'расторгнут'][$c->status] ?? $c->status) }}</span></td></tr>
  @endforeach
  </tbody></table></div>
  @else<div class="empty">{{ __('Договоров нет') }}</div>@endif
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('wallet') !!}{{ __('Платежи') }}</h3></div>
  @if ($ops->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Касса') }}</th><th>{{ __('Описание') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
  @foreach ($ops as $o)
    <tr><td>{{ $o->date->format('d.m.Y') }}</td><td>{{ __($o->desk?->name ?? '') }}</td><td>{{ sys_tr($o->note) }}</td><td class="n"><b class="{{ $o->type === 'in' ? 'in-c' : 'out-c' }}">{{ $o->type === 'in' ? '+' : '−' }}{{ $M($o->amount, $o->desk?->currency ?? 'UZS') }}</b></td></tr>
  @endforeach
  </tbody></table></div>
  @else<div class="empty">{{ __('Платежей нет') }}</div>@endif
</div>

@include('company.erp._thread')
@include('company.erp._party_form', ['cp' => $cp])
@endsection
