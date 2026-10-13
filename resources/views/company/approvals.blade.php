@extends('layouts.panel')
@section('title', __('Согласование расходов'))
@section('content')
@php $cur = \App\Services\CompanyStats::currency($ws); $admin = auth()->user()->isAdmin(); @endphp
<div class="alert alert-info">{!! ic('shield-check') !!}<div>
  @if ($ws->approval_limit > 0)
    {{ __('Расходы от :s требуют подтверждения руководителя.', ['s' => money_s($ws->approval_limit, $cur)]) }}
  @else
    {{ __('Согласование выключено: все расходы проводятся сразу.') }}
  @endif
  @if ($admin) <a href="/company/settings#ops">{{ __('Настроить') }}</a>@endif
</div></div>

<div class="card">
  <div class="card-h"><h3>{!! ic('hourglass') !!}{{ __('Ждут решения') }}</h3><span class="badge {{ $pending->count() ? 'b-warn' : '' }}">{{ $pending->count() }}</span></div>
  @forelse ($pending as $e)
  <div class="list-row appr">
    <div class="appr-main">
      <div class="appr-sum">{{ money_s($e->amount, $cur) }}</div>
      <div><b>{{ __($e->cat) }}</b> · {{ $projects[$e->project_id] ?? $e->project_id }}</div>
      <small>{{ $e->desc }} @if ($e->who) · {{ $e->who }} @endif</small>
      <small>{{ $e->date?->format('d.m.Y') }} · {{ $e->user?->name ?? '—' }} · {{ $e->source === 'telegram' ? 'Telegram' : __('Программа') }}
        @if ($e->receipt) · <a href="{{ $e->receiptUrl() }}" target="_blank">{{ __('чек') }}</a>@endif</small>
    </div>
    @if ($admin)
    <div class="flex">
      <form method="post" action="/company/approvals/{{ $e->id }}">@csrf<input type="hidden" name="action" value="approve"><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Подтвердить') }}</button></form>
      <form method="post" action="/company/approvals/{{ $e->id }}" data-q="{{ __('Причина отказа') }}" onsubmit="var r=prompt(this.dataset.q);if(r===null)return false;this.reason.value=r;">@csrf<input type="hidden" name="action" value="reject"><input type="hidden" name="reason"><button class="btn btn-s btn-d">{!! ic('x') !!}{{ __('Отклонить') }}</button></form>
    </div>
    @endif
  </div>
  @empty
  <div class="empty">{!! ic('circle-check') !!}<div>{{ __('Нет расходов, ожидающих решения') }}</div></div>
  @endforelse
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('history') !!}{{ __('Решённые') }}</h3></div>
  @if ($done->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Объект') }}</th><th>{{ __('Статья') }}</th><th class="n">{{ __('Сумма') }}</th><th>{{ __('Кто внёс') }}</th><th>{{ __('Решение') }}</th></tr></thead><tbody>
  @foreach ($done as $e)
    <tr><td>{{ $e->date?->format('d.m.Y') }}</td><td>{{ $projects[$e->project_id] ?? $e->project_id }}</td><td>{{ __($e->cat) }}<br><small>{{ $e->desc }}</small></td>
      <td class="n"><b>{{ money_s($e->amount, $cur) }}</b></td><td>{{ $e->user?->name ?? '—' }}</td>
      <td>@if ($e->status === 'approved')<span class="badge b-ok">{{ __('проведён') }}</span>@else<span class="badge b-bad" title="{{ $e->reason }}">{{ __('отклонён') }}</span>@endif
        <br><small>{{ $e->decider?->name }} · {{ $e->decided_at?->format('d.m H:i') }}</small></td></tr>
  @endforeach
  </tbody></table></div>
  @else
  <div class="empty">{{ __('Пока пусто') }}</div>
  @endif
</div>
@endsection
