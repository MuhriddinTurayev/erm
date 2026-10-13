@extends('layouts.panel')
@section('title', __('Заявки на оплату'))
@php
  $u = auth()->user(); $M = fn ($v, $c) => \App\Services\Erp\Money::fmt($v, $c);
  $stl = ['pending' => __('на согласовании'), 'approved' => __('утверждена'), 'rejected' => __('отклонена'), 'paid' => __('оплачена'), 'cancelled' => __('отменена')];
  $roles = config('oftob.roles');
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-new">{!! ic('plus') !!}{{ __('Новая заявка') }}</button>@endif
@endsection
@section('content')
<div class="card" style="margin-bottom:16px">
  <div class="flex" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="chain">
      <span class="muted" style="font-size:13px">{{ __('Порядок согласования') }}:</span>
      <span class="c-s">{!! ic('user', '', 14) !!}{{ __('Сотрудник') }}</span>
      @foreach ($chain as $r)<span class="arr">{!! ic('chevron-right', '', 14) !!}</span><span class="c-s">{{ __($roles[$r] ?? $r) }}</span>@endforeach
      <span class="arr">{!! ic('chevron-right', '', 14) !!}</span><span class="c-s">{!! ic('wallet', '', 14) !!}{{ __('Оплата из кассы') }}</span>
    </div>
    @if ($u->isAdmin())<button class="btn btn-s btn-ghost" data-open="dlg-chain">{!! ic('settings') !!}{{ __('Настроить') }}</button>@endif
  </div>
</div>

<div class="chips" style="margin-bottom:14px">
  <a class="chip {{ $tab === 'mine' ? 'on' : '' }}" href="?tab=mine">{{ __('Ждут моего решения') }}<span class="n">{{ $forMe }}</span></a>
  <a class="chip {{ $tab === 'approved' ? 'on' : '' }}" href="?tab=approved">{{ __('К оплате') }}<span class="n">{{ $counts['approved'] ?? 0 }}</span></a>
  <a class="chip {{ $tab === 'paid' ? 'on' : '' }}" href="?tab=paid">{{ __('Оплачены') }}<span class="n">{{ $counts['paid'] ?? 0 }}</span></a>
  <a class="chip {{ $tab === 'rejected' ? 'on' : '' }}" href="?tab=rejected">{{ __('Отклонены') }}</a>
  <a class="chip {{ $tab === 'all' ? 'on' : '' }}" href="?tab=all">{{ __('Все') }}</a>
</div>

<form method="post" action="/company/payments/bulk" class="card">
  @csrf
  @if ($list->count())
  @if ($tab === 'mine' && $forMe)
  <div class="toolbar"><span class="grow muted">{{ __('Отметьте заявки и решите разом') }}</span>
    <button class="btn btn-s btn-p" name="action" value="approve">{!! ic('check') !!}{{ __('Согласовать отмеченные') }}</button>
    <button class="btn btn-s btn-d" name="action" value="reject" data-confirm="{{ __('Отклонить отмеченные заявки?') }}">{!! ic('x') !!}{{ __('Отклонить') }}</button></div>
  @endif
  <div class="tbl-wrap"><table class="tbl"><thead><tr>
    @if ($tab === 'mine' && $forMe)<th style="width:30px"><input type="checkbox" data-all=".pr-ck"></th>@endif
    <th>№</th><th>{{ __('Назначение') }}</th><th>{{ __('Объект') }}</th><th>{{ __('Получатель') }}</th><th class="n">{{ __('Сумма') }}</th><th>{{ __('Срок') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($list as $r)
    <tr class="row-link" data-href="/company/payments/{{ $r->id }}">
      @if ($tab === 'mine' && $forMe)<td>@if (\App\Services\Erp\Payments::canDecide($u, $r))<input type="checkbox" class="pr-ck" name="ids[]" value="{{ $r->id }}">@endif</td>@endif
      <td><b>{{ $r->number }}</b></td>
      <td>{{ sys_tr($r->purpose) }}<br><small>{{ $r->user?->name }} · {{ $r->created_at->format('d.m H:i') }}@if ($r->category) · {{ __($r->category->name) }}@endif</small></td>
      <td>{{ $r->project_id ? ($projects[$r->project_id] ?? '—') : '—' }}</td>
      <td>{{ $r->counterparty?->name ?? '—' }}@if (in_array($r->counterparty?->status, ['blocked', 'suspicious'], true)) <span class="st st-blocked">!</span>@endif</td>
      <td class="n"><b>{{ $M($r->amount, $r->currency) }}</b></td>
      <td class="nowrap {{ $r->due_date && $r->due_date->isPast() && in_array($r->status, ['pending', 'approved']) ? 'out-c' : '' }}">{{ $r->due_date?->format('d.m.Y') ?? '—' }}</td>
      <td><span class="st st-{{ $r->status }}">{{ $stl[$r->status] ?? $r->status }}</span>@if ($r->status === 'pending')<br><small>{{ __($roles[\App\Services\Erp\Payments::currentRole($r)] ?? '') }}</small>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
  @else
  <div class="empty">{!! ic('file-check') !!}<div>{{ $tab === 'mine' ? __('Нет заявок, ожидающих вашего решения') : __('Заявок нет') }}</div></div>
  @endif
</form>

<dialog class="modal" id="dlg-new"><form method="post" action="/company/payments">@csrf
  <div class="modal-h"><h3>{!! ic('file-check') !!}{{ __('Заявка на оплату') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Сумма') }}</span><input name="amount" type="number" step="0.01" min="0.01" required></label>
    <label class="field"><span>{{ __('Валюта') }}</span><select name="currency">@foreach ($currencies as $c)<option>{{ $c }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Назначение платежа') }}</span><input name="purpose" required maxlength="300" placeholder="{{ __('Например: оплата арматуры А500, 12 т') }}"></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">{{ __('Общие расходы компании') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Статья') }}</span><select name="category_id"><option value="">—</option>@foreach ($cats as $c)<option value="{{ $c->id }}">{{ __($c->name) }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Получатель') }}</span><select name="counterparty_id"><option value="">—</option>@foreach ($counterparties as $c)<option value="{{ $c->id }}" @disabled($c->status === 'blocked')>{{ $c->name }}{{ $c->status === 'blocked' ? ' — '.__('заблокирован') : ($c->status === 'suspicious' ? ' — '.__('подозрительный') : '') }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('По договору') }}</span><select name="contract_id"><option value="">—</option>@foreach ($contracts as $c)<option value="{{ $c->id }}">№{{ $c->number }} · {{ $c->counterparty?->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Оплатить до') }}</span><input name="due_date" type="date"></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('send') !!}{{ __('Отправить на согласование') }}</button></div>
</form></dialog>

@if ($u->isAdmin())
<dialog class="modal" id="dlg-chain"><form method="post" action="/company/payments/chain">@csrf
  <div class="modal-h"><h3>{!! ic('settings') !!}{{ __('Цепочка согласования') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b">
    <p class="muted" style="margin-top:0">{{ __('Выберите, кто и в каком порядке согласует заявку. Руководитель может утвердить заявку на любом шаге.') }}</p>
    @for ($i = 0; $i < 4; $i++)
    <label class="field" style="margin-bottom:10px"><span>{{ __('Шаг') }} {{ $i + 1 }}</span><select name="chain[]"><option value="">—</option>
      @foreach ($roles as $k => $n) @if ($k !== 'viewer')<option value="{{ $k }}" @selected(($chain[$i] ?? '') === $k)>{{ __($n) }}</option>@endif @endforeach</select></label>
    @endfor
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@endif
@endsection
