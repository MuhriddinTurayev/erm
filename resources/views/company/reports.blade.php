@extends('layouts.panel')
@section('title', __('Ежедневные отчёты'))
@section('actions')
@if (auth()->user()->isAdmin())
<form method="post" action="/company/reports/run">@csrf<button class="btn btn-s">{!! ic('refresh-cw') !!}{{ __('Собрать за сегодня') }}</button></form>
<form method="post" action="/company/reports/run">@csrf<input type="hidden" name="send" value="1"><button class="btn btn-p btn-s">{!! ic('send') !!}{{ __('Отправить сейчас') }}</button></form>
@endif
@endsection
@section('content')
<div class="alert alert-info">{!! ic('bell') !!}<div>
  @if ($ws->report_enabled)
    {{ __('Отчёт уходит руководителю каждый день в :t (:z).', ['t' => $ws->report_time, 'z' => __(config('oftob.timezones.'.$ws->timezone, $ws->timezone))]) }}
    {{ $ws->report_chats ? __('Telegram подключён.') : __('Telegram не подключён.') }}
    @if ($ws->report_email) {{ __('Email: :e.', ['e' => $ws->report_email]) }} @endif
  @else
    {{ __('Ежедневная отправка выключена.') }}
  @endif
  @if (auth()->user()->isAdmin()) <a href="/company/settings">{{ __('Настроить') }}</a>@endif
</div></div>
<div class="card">
  @if ($reports->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th class="n">{{ __('Касса') }}</th><th class="n">{{ __('Потрачено') }}</th><th class="n">{{ __('Поступило') }}</th><th>{{ __('Главная статья') }}</th><th>{{ __('Отправлен') }}</th><th></th></tr></thead><tbody>
    @foreach ($reports as $r)
    @php $T = $r->stats['totals']; $cur = $r->stats['currency'] ?? 'сум'; $c = array_key_first($r->stats['cats_day'] ?? []); @endphp
    <tr>
      <td><b>{{ $r->date->format('d.m.Y') }}</b><br><small>{{ weekday($r->date) }}</small></td>
      <td class="n"><b>{{ money_s($T['balance'], $cur) }}</b></td>
      <td class="n">{{ money_s($T['out_day'], $cur) }}</td>
      <td class="n ok">{{ money_s($T['in_day'], $cur) }}</td>
      <td>{{ $c ? __($c) : '—' }}</td>
      <td><div class="flex" style="gap:6px">
        @if ($r->sent_telegram)<span class="badge b-ok">Telegram</span>@endif
        @if ($r->sent_email)<span class="badge b-info">Email</span>@endif
        @if ($r->error)<span class="badge b-bad" title="{{ $r->error }}">{{ __('ошибка') }}</span>@endif
        @if (! $r->sent_at)<span class="badge">{{ __('не отправлялся') }}</span>@endif
      </div></td>
      <td class="right"><a class="btn btn-s" href="/company/reports/{{ $r->id }}">{{ __('Открыть') }}</a></td>
    </tr>
    @endforeach
  </tbody></table></div>
  <div class="mt">{{ $reports->links() }}</div>
  @else
  <div class="empty">{!! ic('inbox') !!}<div>{{ __('Отчётов ещё нет. Первый придёт сегодня вечером — или нажмите «Собрать за сегодня».') }}</div></div>
  @endif
</div>
@endsection
