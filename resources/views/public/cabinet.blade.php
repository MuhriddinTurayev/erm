@extends('layouts.public')
@section('title', __('Кабинет покупателя').' — '.$ws->name)
@section('content')
@php $left = max(0, $a['price'] - $a['paid']); $pct = $a['price'] ? min(100, round($a['paid'] / $a['price'] * 100)) : 0; @endphp
<section class="pub-hero">
  <div>
    <small class="muted">{{ __('Кабинет покупателя') }}</small>
    <h1>{{ $p['name'] ?? '' }} · {{ $a['rooms'] ? __('Кв.').' '.apt_num($a['num']) : apt_num($a['num']) }}</h1>
    <p class="muted">{{ $a['buyer'] }} · {{ __('Подъезд') }} {{ $a['section'] }}, {{ __('этаж') }} {{ $a['floor'] }} · {{ $a['rooms'] ? $a['rooms'].__('-комн.') : __('Коммерция') }} · {{ $a['area'] }} {{ __('м²') }}</p>
  </div>
  @if (\App\Models\ObjectMedia::of($ws->id)->where('project_id', $bl->project_id)->where('is_public', true)->whereIn('kind', ['exterior', 'interior', 'pano'])->exists())
  @php $locM = \App\Services\Locate::model($ws->id, $bl->project_id, null, true); @endphp
  <div class="flex">
    @if ($locM && ! empty($locM->meta['locate']['blocks']))<a class="btn btn-p btn-l" href="/b/{{ $bl->token }}/locate" target="_blank">{!! ic('target') !!}{{ __('Где моя квартира в доме') }}</a>@endif
    <a class="btn btn-l" href="/b/{{ $bl->token }}/3d" target="_blank">{!! ic('box') !!}{{ __('3D-тур по дому и квартире') }}</a>
  </div>
  @endif
</section>

@if ($overdue > 0)
<div class="alert alert-bad">{!! ic('triangle-alert') !!}<div>{{ __('Просрочено к оплате: :s. Пожалуйста, свяжитесь с отделом продаж.', ['s' => money_s($overdue, $currency)]) }}</div></div>
@endif

<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('house') !!}{{ __('Стоимость') }}</div><div class="k-v">{{ money_s($a['price'], $currency) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('circle-check') !!}{{ __('Оплачено') }}</div><div class="k-v ok">{{ money_s($a['paid'], $currency) }}</div><div class="prog"><i style="width:{{ $pct }}%"></i></div><div class="k-s">{{ $pct }}%</div></div>
  <div class="kpi"><div class="k-l">{!! ic('wallet') !!}{{ __('Осталось') }}</div><div class="k-v">{{ money_s($left, $currency) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('calendar') !!}{{ __('Следующий платёж') }}</div><div class="k-v">{{ $next ? money_s($next['amount'], $currency) : '—' }}</div><div class="k-s">{{ $next ? \Carbon\Carbon::parse($next['date'])->format('d.m.Y') : __('всё оплачено') }}</div></div>
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('list') !!}{{ __('График платежей') }}</h3></div>
    @if ($schedule)
    <div class="tbl-wrap" style="max-height:420px;overflow:auto"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th class="n">{{ __('Сумма') }}</th><th></th></tr></thead><tbody>
    @foreach ($schedule as $r)
      <tr><td>{{ \Carbon\Carbon::parse($r['date'])->format('d.m.Y') }}</td><td class="n">{{ money_s($r['amount'], $currency) }}</td>
        <td>@if ($r['paid'])<span class="badge b-ok">{{ __('оплачен') }}</span>@elseif ($r['date'] < now()->toDateString())<span class="badge b-bad">{{ __('просрочен') }}</span>@else<span class="badge">{{ __('ожидается') }}</span>@endif</td></tr>
    @endforeach
    </tbody></table></div>
    @else
    <div class="empty">{{ __('Оплата без рассрочки') }}</div>
    @endif
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('hard-hat') !!}{{ __('Ход строительства') }}</h3><b>{{ $progress }}%</b></div>
    <div class="prog" style="margin-bottom:14px"><i style="width:{{ $progress }}%"></i></div>
    @foreach ($stages as $s)
      <div class="hbar"><div class="hb-t"><span>{{ __($s['name'] ?? '') }}</span><b>{{ (int) ($s['progress'] ?? 0) }}%</b></div><div class="hb-r"><i style="width:{{ (float) ($s['progress'] ?? 0) }}%;background:var(--ok)"></i></div></div>
    @endforeach
  </div>
</div>

@if ($photos->count())
<div class="card mt">
  <div class="card-h"><h3>{!! ic('camera') !!}{{ __('Фото со стройки') }}</h3></div>
  @foreach ($photos as $l)
    <div class="log-item"><small class="muted">{{ $l->date->format('d.m.Y') }}</small>@if ($l->text)<p class="log-text">{{ $l->text }}</p>@endif
      <div class="gallery">@foreach (array_keys($l->photos) as $i)<a href="/b/{{ $bl->token }}/photo/{{ $l->id }}/{{ $i }}" target="_blank"><img src="/b/{{ $bl->token }}/photo/{{ $l->id }}/{{ $i }}" loading="lazy" alt=""></a>@endforeach</div></div>
  @endforeach
</div>
@endif

@if ($bot)
<div class="card mt flex" style="justify-content:space-between;flex-wrap:wrap">
  <div><b>{{ __('Напоминания об оплате в Telegram') }}</b><br><small class="muted">{{ $bl->tg_chat_id ? __('Подключено — напомним за 3 дня до платежа.') : __('Напомним за 3 дня до платежа и пришлём новые фото стройки.') }}</small></div>
  @unless ($bl->tg_chat_id)<a class="btn btn-p" href="https://t.me/{{ $bot }}?start=b_{{ $bl->token }}" target="_blank" rel="noopener">{!! ic('send') !!}{{ __('Подключить') }}</a>@endunless
</div>
@endif
@endsection
