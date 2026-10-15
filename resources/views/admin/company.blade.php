@extends('layouts.panel')
@section('title', $w->name)
@php $T = $s['totals']; $cur = $s['currency']; $req = $w->plan_request ? array_pad(explode(':', $w->plan_request), 2, 1) : null; $reqPlan = $req ? $plans->firstWhere('slug', $req[0]) : null; @endphp
@section('actions')
<a class="btn btn-s" href="/admin/companies">← {{ __('Все компании') }}</a>
<form method="post" action="/admin/companies/{{ $w->id }}/impersonate">@csrf<button class="btn btn-s btn-dark">{!! ic('eye') !!}{{ __('Войти как руководитель') }}</button></form>
@endsection
@section('content')
@if ($req)
<div class="alert alert-info">{!! ic('hand') !!}<div>{{ __('Заявка: тариф «:p» на :m мес.', ['p' => $reqPlan?->title() ?? $req[0], 'm' => $req[1]]) }} {{ __('Запишите оплату ниже — доступ продлится автоматически.') }}</div></div>
@endif
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('gem') !!}{{ __('Тариф') }}</div><div class="k-v">{{ $w->plan?->title() ?? '—' }}</div><div class="k-s">{{ $w->stateLabel() }} · {{ $w->accessUntil() ? __('до').' '.$w->accessUntil()->format('d.m.Y') : __('без срока') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('wallet') !!}{{ __('Касса компании') }}</div><div class="k-v">{{ money_s($T['balance'], $cur) }}</div><div class="k-s">{{ __('за месяц') }} −{{ money_s($T['out_month'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('construction') !!}{{ __('Объекты') }}</div><div class="k-v">{{ count($s['objects']) }}</div><div class="k-s">{{ __('лимит') }} {{ $w->maxObjects() ?: '∞' }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('users') !!}{{ __('Пользователи') }}</div><div class="k-v">{{ $users->count() }}</div><div class="k-s">{{ __('лимит') }} {{ $w->maxUsers() ?: '∞' }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('bot') !!}{{ __('ИИ за месяц') }}</div><div class="k-v">{{ $ai }}</div><div class="k-s">{{ __('лимит') }} {{ $w->aiLimit() ?: '∞' }}</div></div>
  @if (auth()->user()->adminCan('api'))@php $apiKeys = \App\Models\ApiToken::of($w->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(); @endphp
  <div class="kpi"><div class="k-l">{!! ic('plug') !!}{{ __('Ключи API') }}</div><div class="k-v">{{ $apiKeys }}</div><div class="k-s"><a href="/admin/api?company={{ $w->id }}">{{ $apiKeys ? __('Открыть') : __('Выдать ключ') }} →</a>@if (is_array($w->setting('api_request'))) · <span class="bad">{{ __('просят ключ') }}</span>@endif</div></div>@endif
</div>

<div class="grid g2">
  <form method="post" action="/admin/companies/{{ $w->id }}" class="card">
    @csrf @method('PUT')
    <div class="card-h"><h3>{!! ic('settings') !!}{{ __('Подписка и данные') }}</h3></div>
    <div class="form" style="grid-template-columns:1fr 1fr">
      <label class="field wide"><span>{{ __('Название') }}</span><input name="name" value="{{ $w->name }}" required></label>
      <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" value="{{ $w->phone }}"></label>
      <label class="field"><span>{{ __('Тариф') }}</span><select name="plan_id"><option value="">—</option>@foreach ($plans as $p)<option value="{{ $p->id }}" @selected($w->plan_id === $p->id)>{{ $p->title() }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Статус') }}</span><select name="status">@foreach (['trial' => __('Пробный период'), 'active' => __('Активна'), 'suspended' => __('Заблокирована')] as $k => $n)<option value="{{ $k }}" @selected($w->status === $k)>{{ $n }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Пробный до') }}</span><input type="date" name="trial_ends_at" value="{{ $w->trial_ends_at?->toDateString() }}"></label>
      <label class="field"><span>{{ __('Оплачено до') }}</span><input type="date" name="paid_until" value="{{ $w->paid_until?->toDateString() }}"><small>{{ __('пусто — без срока') }}</small></label>
      <label class="field wide"><span>{{ __('Заметка (видите только вы)') }}</span><textarea name="note" rows="2">{{ $w->note }}</textarea></label>
      @if ($req)<label class="check wide"><input type="checkbox" name="clear_request" value="1"> {{ __('Закрыть заявку на тариф') }}</label>@endif
    </div>
    <button class="btn btn-p mt">{{ __('Сохранить') }}</button>
  </form>

  <form method="post" action="/admin/companies/{{ $w->id }}/pay" class="card" onsubmit="this.querySelector('[type=submit]').disabled = true">
    @csrf
    <input type="hidden" name="idem" value="{{ \Illuminate\Support\Str::uuid() }}">{{-- повторная отправка формы не запишет оплату дважды --}}
    <div class="card-h"><h3>{!! ic('credit-card') !!}{{ __('Записать оплату и продлить') }}</h3></div>
    @php $pp = $reqPlan ?? $w->plan; $mm = (int) ($req[1] ?? 1); @endphp
    <div class="form" style="grid-template-columns:1fr 1fr">
      <label class="field"><span>{{ __('Тариф') }}</span><select name="plan_id" id="payPlan">@foreach ($plans as $p)<option value="{{ $p->id }}" data-m="{{ $p->price_month }}" data-y="{{ $p->price_year }}" @selected($pp?->id === $p->id)>{{ $p->title() }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Месяцев') }}</span><input type="number" name="months" id="payMonths" value="{{ $mm }}" min="1" max="36" required></label>
      <label class="field"><span>{{ __('Сумма') }}</span><input type="number" name="amount" id="payAmount" value="{{ (int) (($pp?->price_month ?? 0) * $mm) }}" min="0" step="any" required></label>
      <label class="field"><span>{{ __('Способ') }}</span><select name="method">@foreach (\App\Models\SubscriptionPayment::METHODS as $k => $n)<option value="{{ $k }}">{{ __($n) }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Дата оплаты') }}</span><input type="date" name="paid_at" value="{{ now()->toDateString() }}" required></label>
      <label class="field"><span>{{ __('Комментарий') }}</span><input name="note" placeholder="{{ __('номер платежа…') }}"></label>
    </div>
    <button class="btn btn-p mt">{{ __('Записать оплату') }}</button>
    @if ($payments->count())
    <div class="tbl-wrap mt"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Тариф') }}</th><th>{{ __('Срок') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
      @foreach ($payments as $pay)<tr><td>{{ $pay->paid_at->format('d.m.Y') }}</td><td>{{ $pay->plan?->title() }}</td><td>{{ $pay->months }} {{ __('мес') }}</td><td class="n"><b>{{ money_f($pay->amount, $pay->currency) }}</b></td></tr>@endforeach
    </tbody></table></div>
    @endif
  </form>
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('users') !!}{{ __('Пользователи компании') }}</h3></div>
    <div class="tbl-wrap"><table class="tbl"><tbody>
      @foreach ($users as $u)<tr><td><div class="who"><span class="avatar">{{ initials($u->name) }}</span><div><b>{{ $u->name }}</b><br><small>{{ $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }}</small></div></div></td>
        <td><form method="post" action="/admin/companies/{{ $w->id }}/users/{{ $u->id }}/role" class="flex" style="gap:6px">@csrf
          <select class="input" name="role" style="width:auto;height:34px" onchange="this.form.submit()">@foreach (config('oftob.roles') as $rk => $rn)<option value="{{ $rk }}" @selected($u->role === $rk)>{{ __($rn) }}</option>@endforeach</select></form></td>
        <td><small>{{ $u->last_login_at?->format('d.m.Y H:i') ?? '—' }}</small></td></tr>@endforeach
    </tbody></table></div>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('construction') !!}{{ __('Объекты') }}</h3></div>
    @forelse ($s['objects'] as $o)
      <div class="flex between" style="padding:8px 0;border-bottom:1px solid var(--line2)"><div><b>{{ $o['name'] }}</b>@if ($o['demo']) <span class="badge plain">{{ __('демо') }}</span>@endif<br><small>{{ __('касса') }} {{ money_s($o['balance'], $cur) }} · {{ __('сотрудников') }} {{ $o['staff'] }}</small></div><div class="flex" style="gap:8px;width:140px"><div class="prog grow"><i style="width:{{ $o['progress'] }}%"></i></div><b>{{ $o['progress'] }}%</b></div></div>
    @empty <div class="empty">{{ __('Объектов нет') }}</div> @endforelse
  </div>
</div>

<details class="card mt">
  <summary class="bad" style="cursor:pointer;font-weight:650">{!! ic('trash-2') !!}{{ __('Удалить компанию навсегда') }}</summary>
  <form method="post" action="/admin/companies/{{ $w->id }}" class="flex mt">
    @csrf @method('DELETE')
    <input class="input grow" name="confirm" placeholder="{{ __('Введите название: :n', ['n' => $w->name]) }}" required>
    <button class="btn btn-d">{{ __('Удалить вместе со всеми данными') }}</button>
  </form>
</details>
@push('scripts')
<script>
(() => { const p = document.getElementById('payPlan'), m = document.getElementById('payMonths'), a = document.getElementById('payAmount');
  const calc = () => { const o = p.selectedOptions[0], n = +m.value || 1, y = +o.dataset.y; a.value = n === 12 && y > 0 ? y : Math.round(+o.dataset.m * n); };
  p.addEventListener('change', calc); m.addEventListener('input', calc); })();
</script>
@endpush
@endsection
