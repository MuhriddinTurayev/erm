@extends('layouts.panel')
@section('title', __('Оплаты'))
@section('content')
<div class="kpis">
  <div class="kpi hero"><div class="k-l">{!! ic('credit-card') !!}{{ __('В этом месяце') }}</div><div class="k-v">{{ money_s($total) }}</div><div class="k-s">{{ now()->translatedFormat('F Y') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('calendar') !!}{{ __('С начала года') }}</div><div class="k-v ok">{{ money_s($year) }}</div><div class="k-s">{{ now()->year }}</div></div>
</div>
<div class="card">
  @if ($payments->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Дата') }}</th><th>{{ __('Компания') }}</th><th>{{ __('Тариф') }}</th><th>{{ __('Срок') }}</th><th>{{ __('Способ') }}</th><th class="n">{{ __('Сумма') }}</th></tr></thead><tbody>
    @foreach ($payments as $p)
    <tr><td>{{ $p->paid_at->format('d.m.Y') }}</td><td><a href="/admin/companies/{{ $p->workspace_id }}"><b>{{ $p->workspace?->name }}</b></a>@if ($p->note)<br><small>{{ $p->note }}</small>@endif</td><td>{{ $p->plan?->title() }}</td><td>{{ $p->months }} {{ __('мес') }}</td><td>{{ __(\App\Models\SubscriptionPayment::METHODS[$p->method] ?? $p->method) }}</td><td class="n"><b>{{ money_f($p->amount, $p->currency) }}</b></td></tr>
    @endforeach
  </tbody></table></div>
  <div class="mt">{{ $payments->links() }}</div>
  @else
    <div class="empty">{!! ic('credit-card') !!}{{ __('Оплат ещё нет. Оплату записывают на странице компании.') }}</div>
  @endif
</div>
@endsection
