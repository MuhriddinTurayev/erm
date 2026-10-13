@extends('layouts.panel')
@section('title', __('Тариф и оплата'))
@php $plan = $ws->plan; $isAdmin = auth()->user()->isAdmin(); $req = $ws->plan_request ? explode(':', $ws->plan_request) : null; @endphp
@section('content')
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('gem') !!}{{ __('Ваш тариф') }}</div><div class="k-v">{{ $plan?->title() ?? '—' }}</div>
    <div class="k-s">{{ $ws->stateLabel() }}@if ($ws->accessUntil()) · {{ __('до') }} {{ $ws->accessUntil()->format('d.m.Y') }} ({{ __(':n дн.', ['n' => $ws->daysLeft()]) }})@elseif ($ws->hasAccess()) · {{ __('без срока') }}@endif</div></div>
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Объекты') }}</div><div class="k-v">{{ $objects }} / {{ $ws->maxObjects() ?: '∞' }}</div><div class="k-s">{{ __('демо-объект не считается') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('users') !!}{{ __('Пользователи') }}</div><div class="k-v">{{ $users }} / {{ $ws->maxUsers() ?: '∞' }}</div><div class="k-s">{{ __('с доступом в программу') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('sparkles') !!}{{ __('Запросы к ИИ') }}</div><div class="k-v">{{ $aiUsed }} / {{ $ws->aiAllowed() ? ($ws->aiLimit() ?: '∞') : '—' }}</div><div class="k-s">{{ __('в этом месяце') }}</div></div>
</div>

@if ($req)
<div class="alert alert-info">{!! ic('clock') !!}<div>{{ __('Ваша заявка на тариф «:p» на :m мес. принята. Мы свяжемся с вами.', ['p' => optional($plans->firstWhere('slug', $req[0]))->title() ?? $req[0], 'm' => $req[1] ?? 1]) }}</div></div>
@endif

<div class="plans">
  @foreach ($plans as $p)
  <div class="plan {{ $p->is_featured ? 'feat' : '' }} {{ $plan && $plan->id === $p->id ? 'cur' : '' }}">
    @if ($plan && $plan->id === $p->id)<span class="p-tag">{{ __('Ваш тариф') }}</span>@elseif ($p->is_featured)<span class="p-tag">{{ __('Популярный') }}</span>@endif
    <h2>{{ $p->title() }}</h2>
    <div class="muted">{{ $p->about() }}</div>
    <div class="p-price">{{ money_s($p->price_month, $p->currency) }} <small class="muted">/ {{ __('мес') }}</small></div>
    @if ($p->price_year > 0)<div class="muted" style="margin-top:-8px;font-size:14px">{{ __('или :s в год', ['s' => money_s($p->price_year, $p->currency)]) }}</div>@endif
    <ul>
      <li>{!! ic('check') !!}<span>{{ __('Объектов') }}: <b>{{ $p->limitText($p->max_objects) }}</b></span></li>
      <li>{!! ic('check') !!}<span>{{ __('Пользователей') }}: <b>{{ $p->limitText($p->max_users) }}</b></span></li>
      <li>{!! ic('check') !!}<span>{{ __('ИИ') }}: <b>{{ $p->ai_enabled ? ($p->ai_requests ? __(':n запросов/мес', ['n' => $p->ai_requests]) : __('без лимита')) : '—' }}</b></span></li>
      @foreach ($p->featureList() as $f)<li>{!! ic('check') !!}<span>{{ $f }}</span></li>@endforeach
    </ul>
    @if ($isAdmin)
    <form method="post" action="/company/billing" class="flex">
      @csrf<input type="hidden" name="plan" value="{{ $p->slug }}">
      <select name="months" class="input" style="width:auto"><option value="1">1 {{ __('мес') }}</option><option value="3">3 {{ __('мес') }}</option><option value="6">6 {{ __('мес') }}</option><option value="12">12 {{ __('мес') }}</option></select>
      <button class="btn {{ $p->is_featured ? 'btn-p' : '' }} grow">{{ $plan && $plan->id === $p->id ? __('Продлить') : __('Выбрать') }}</button>
    </form>
    @endif
  </div>
  @endforeach
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('headset') !!}{{ __('Как оплатить') }}</h3></div>
    <p class="muted">{{ __('Выберите тариф и срок — мы получим заявку и пришлём реквизиты (перечисление, Click, Payme, наличные). После оплаты доступ продлевается сразу.') }}</p>
    <dl class="kv">
      @if ($support['phone'])<dt>{{ __('Телефон') }}</dt><dd><a href="tel:{{ preg_replace('/[^+\d]/', '', $support['phone']) }}">{{ $support['phone'] }}</a></dd>@endif
      @if ($support['telegram'])<dt>Telegram</dt><dd><a href="https://t.me/{{ ltrim($support['telegram'], '@') }}" target="_blank" rel="noopener">{{ '@'.ltrim($support['telegram'], '@') }}</a></dd>@endif
      @if ($support['email'])<dt>Email</dt><dd><a href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></dd>@endif
    </dl>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('receipt') !!}{{ __('История оплат') }}</h3></div>
    @if ($payments->count())
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Срок') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
      @foreach ($payments as $pay)<tr><td>{{ $pay->paid_at->format('d.m.Y') }}</td><td>{{ $pay->months }} {{ __('мес') }}</td><td class="n"><b>{{ money_f($pay->amount, $pay->currency) }}</b></td></tr>@endforeach
    </tbody></table></div>
    @else
      <div class="empty">{{ __('Оплат пока не было') }}</div>
    @endif
  </div>
</div>
@endsection
