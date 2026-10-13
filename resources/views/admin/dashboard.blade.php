@extends('layouts.panel')
@section('title', __('Обзор платформы'))
@php $maxReg = max(1, ...array_values($reg)); @endphp
@section('content')
@if (! $aiProviders)
<div class="alert alert-warn">{!! ic('bot') !!}<div>{{ __('ИИ ещё не подключён — компании не смогут пользоваться ИИ-консультантом.') }} <a href="/admin/ai"><b>{{ __('Подключить Gemini, ChatGPT, DeepSeek или Claude') }}</b></a></div></div>
@endif
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('gem') !!}{{ __('Ежемесячный доход (MRR)') }}</div><div class="k-v">{{ money_s($mrr) }}</div><div class="k-s">{{ __('по активным подпискам') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Компании') }}</div><div class="k-v">{{ $total }}</div><div class="k-s"><span class="ok">{{ __('активны') }} {{ $active }}</span> · {{ __('пробные') }} {{ $trial }} · <span class="bad">{{ __('истекли') }} {{ $expired }}</span></div></div>
  <div class="kpi"><div class="k-l">{!! ic('users') !!}{{ __('Пользователи') }}</div><div class="k-v">{{ $users }}</div><div class="k-s">{{ __('во всех компаниях') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('credit-card') !!}{{ __('Оплачено в этом месяце') }}</div><div class="k-v ok">{{ money_s($paidMonth) }}</div><div class="k-s"><a href="/admin/payments">{{ __('все оплаты') }} →</a></div></div>
  <div class="kpi"><div class="k-l">{!! ic('bot') !!}{{ __('Запросы к ИИ за месяц') }}</div><div class="k-v">{{ $aiMonth }}</div><div class="k-s">{{ __('ошибок') }}: {{ $aiErrors }} · {{ __('подключений') }}: {{ $aiProviders }}</div></div>
</div>

<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('trending-up') !!}{{ __('Регистрации за 30 дней') }}</h3><span class="muted">{{ array_sum($reg) }}</span></div>
    <div class="bars">@foreach ($reg as $d => $v)<div class="b" title="{{ dmy($d) }}: {{ $v }}"><i style="height:{{ $v / $maxReg * 100 }}%"></i><span>{{ (int) substr($d, 8) % 5 === 0 ? substr($d, 8, 2) : '' }}</span></div>@endforeach</div>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('gem') !!}{{ __('Компании по тарифам') }}</h3></div>
    @forelse ($planSplit as $name => $n)
      <div class="hbar"><div class="hb-t"><span>{{ $name }}</span><b>{{ $n }}</b></div><div class="hb-r"><i style="width:{{ $n / max(1, $total) * 100 }}%"></i></div></div>
    @empty <div class="empty">—</div> @endforelse
  </div>
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('hand') !!}{{ __('Заявки на тариф') }}</h3></div>
    @forelse ($requests as $w)
      @php [$slug, $months] = array_pad(explode(':', $w->plan_request), 2, 1); @endphp
      <div class="flex between" style="padding:9px 0;border-bottom:1px solid var(--line2)"><div><b>{{ $w->name }}</b><br><small>{{ $w->phone }} · {{ __('хочет') }}: <b>{{ $slug }}</b>, {{ $months }} {{ __('мес') }}</small></div><a class="btn btn-s btn-p" href="/admin/companies/{{ $w->id }}">{{ __('Оформить') }}</a></div>
    @empty <div class="empty">{!! ic('inbox') !!}{{ __('Новых заявок нет') }}</div> @endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('hourglass') !!}{{ __('Скоро заканчивается доступ') }}</h3></div>
    @forelse ($endingSoon as $w)
      <div class="flex between" style="padding:9px 0;border-bottom:1px solid var(--line2)"><div><b>{{ $w->name }}</b><br><small>{{ $w->plan?->title() }} · {{ $w->stateLabel() }} · {{ __('до') }} {{ $w->accessUntil()?->format('d.m.Y') }}</small></div><span class="badge {{ $w->daysLeft() <= 1 ? 'b-bad' : 'b-warn' }}">{{ __(':n дн.', ['n' => $w->daysLeft()]) }}</span></div>
    @empty <div class="empty">{!! ic('circle-check', 'ok') !!}{{ __('Ни у кого не заканчивается в ближайшие 5 дней') }}</div> @endforelse
  </div>
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('sparkles') !!}{{ __('Новые компании') }}</h3><a class="btn btn-s" href="/admin/companies">{{ __('Все компании') }} →</a></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Компания') }}</th><th>{{ __('Тариф') }}</th><th>{{ __('Статус') }}</th><th>{{ __('Зарегистрирована') }}</th></tr></thead><tbody>
    @foreach ($latest as $w)
    <tr><td><a href="/admin/companies/{{ $w->id }}"><b>{{ $w->name }}</b></a><br><small>{{ $w->phone }}</small></td><td>{{ $w->plan?->title() ?? '—' }}</td><td>@include('admin.state', ['w' => $w])</td><td>{{ $w->created_at->format('d.m.Y H:i') }}</td></tr>
    @endforeach
  </tbody></table></div>
</div>
@endsection
