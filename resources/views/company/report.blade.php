@extends('layouts.panel')
@section('title', __('Отчёт за :d', ['d' => $r->date->format('d.m.Y')]))
@section('actions')
<a class="btn btn-s" href="/company/reports">{!! ic('arrow-left') !!}{{ __('Все отчёты') }}</a>
<a class="btn btn-s" href="/company/overview?date={{ $r->date->toDateString() }}">{!! ic('chart-column') !!}{{ __('Подробно') }}</a>
@endsection
@section('content')
@php $T = $r->stats['totals']; $cur = $r->stats['currency'] ?? 'сум'; @endphp
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('wallet') !!}{{ __('Касса') }}</div><div class="k-v">{{ money_s($T['balance'], $cur) }}</div><div class="k-s">{{ $ws->name }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('trending-down') !!}{{ __('Потрачено') }}</div><div class="k-v">{{ money_s($T['out_day'], $cur) }}</div><div class="k-s">{{ __('вчера') }} {{ money_s($T['out_yest'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('trending-up') !!}{{ __('Поступило') }}</div><div class="k-v ok">{{ money_s($T['in_day'], $cur) }}</div><div class="k-s">{{ __('за месяц') }} {{ money_s($T['in_month'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('calendar-days') !!}{{ __('Расходы месяца') }}</div><div class="k-v">{{ money_s($T['out_month'], $cur) }}</div><div class="k-s">{{ __('за 7 дней') }} {{ money_s($T['out_week'], $cur) }}</div></div>
</div>
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h3>{!! ic('send') !!}{{ __('Так выглядит сообщение в Telegram') }}</h3>
      @if ($r->sent_at)<span class="muted">{{ __('отправлен') }} {{ $r->sent_at->timezone($ws->timezone)->format('d.m.Y H:i') }}</span>@endif</div>
    @if ($r->error)<div class="alert alert-bad">{!! ic('circle-alert') !!}<div>{{ $r->error }}</div></div>@endif
    <div class="tg-preview"><div class="tg-msg">{!! strip_tags($text, '<b><i>') !!}</div></div>
  </div>
  <div>
    @if ($r->ai_summary)
    <div class="card"><div class="card-h"><h3>{!! ic('sparkles') !!}{{ __('Вывод ИИ') }}</h3></div><div style="white-space:pre-wrap;color:var(--text-2)">{{ $r->ai_summary }}</div></div>
    @endif
    <div class="card">
      <div class="card-h"><h3>{!! ic('chart-pie') !!}{{ __('На что ушли деньги') }}</h3></div>
      @forelse ($r->stats['cats_day'] as $cat => $v)
        <div class="hbar"><div class="hb-t"><span>{{ __($cat) }}</span><b>{{ money_s($v, $cur) }}</b></div><div class="hb-r"><i style="width:{{ round($v / max(1, $T['out_day']) * 100, 1) }}%"></i></div></div>
      @empty
        <div class="empty">{{ __('Расходов не было') }}</div>
      @endforelse
    </div>
    @if (auth()->user()->isAdmin())
    <form method="post" action="/company/reports/run" class="card flex between">@csrf<input type="hidden" name="send" value="1">
      <span class="muted">{{ __('Пересобрать отчёт за сегодня и отправить ещё раз') }}</span><button class="btn btn-p btn-s">{!! ic('send') !!}{{ __('Отправить') }}</button></form>
    @endif
  </div>
</div>
@endsection
