@extends('layouts.panel')
@section('title', $t->title)
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws);
  $stl = ['open' => 'приём предложений', 'review' => 'на утверждении', 'awarded' => 'победитель выбран', 'cancelled' => 'отменён'];
  $bids = $t->bids->sortBy('amount')->values(); $min = $bids->min('amount'); $minDays = $bids->whereNotNull('days')->min('days');
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/tenders">{!! ic('arrow-left') !!}{{ __('Все тендеры') }}</a>
@endsection
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('gavel') !!}{{ $t->title }}</h3><span class="st st-{{ $t->status }}">{{ __($stl[$t->status] ?? $t->status) }}</span></div>
    <dl class="kv">
      <dt>{{ __('Объект') }}</dt><dd>{{ $t->project_id ? ($projects[$t->project_id] ?? '—') : '—' }}</dd>
      <dt>{{ __('Приём до') }}</dt><dd>{{ $t->deadline?->format('d.m.Y') ?? '—' }}</dd>
      <dt>{{ __('Создал') }}</dt><dd>{{ $t->user?->name }}</dd>
      @if ($t->approver)<dt>{{ __('Утвердил') }}</dt><dd>{{ $t->approver->name }}</dd>@endif
    </dl>
    @if ($t->description)<p style="white-space:pre-wrap" class="mt">{{ $t->description }}</p>@endif
    @if ($sr)
      <div class="mt"><b>{{ __('Заявка на снабжение') }} №{{ $sr->number }}:</b>
        @foreach ($sr->lines as $l)<div class="muted">· {{ $l->item?->name }} — {{ \App\Services\Erp\Stock::n($l->qty) }} {{ __($l->item?->unit ?? '') }}</div>@endforeach</div>
    @endif
  </div>
  @if ($u->canEdit() && in_array($t->status, ['open', 'review']))
  <div class="card">
    <div class="card-h"><h3>{!! ic('plus') !!}{{ __('Добавить предложение') }}</h3></div>
    <form method="post" action="/company/tenders/{{ $t->id }}/bid" class="form" style="grid-template-columns:1fr 1fr">@csrf
      <label class="field full"><span>{{ __('Участник') }}</span><select name="counterparty_id" required><option value="">—</option>@foreach ($counterparties as $c)<option value="{{ $c->id }}" @disabled($c->status === 'blocked')>{{ $c->name }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Цена') }}, {{ __($cur) }}</span><input type="number" step="0.01" min="0" name="amount" required></label>
      <label class="field"><span>{{ __('Срок, дней') }}</span><input type="number" min="0" name="days"></label>
      <label class="field full"><span>{{ __('Условия') }}</span><input name="note" maxlength="300" placeholder="{{ __('Предоплата 30%, доставка включена…') }}"></label>
      <div class="full"><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Добавить') }}</button></div>
    </form>
  </div>
  @endif
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('scale') !!}{{ __('Сравнение предложений') }}</h3><span class="badge">{{ $bids->count() }}</span></div>
  @if ($bids->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>#</th><th>{{ __('Участник') }}</th><th class="n">{{ __('Цена') }}</th><th class="n">{{ __('Разница с лучшей') }}</th><th class="n">{{ __('Срок') }}</th><th>{{ __('Условия') }}</th><th></th></tr></thead><tbody>
  @foreach ($bids as $i => $b)
    <tr style="{{ $t->winner_bid_id === $b->id ? 'background:var(--ok-soft)' : '' }}">
      <td>{{ $i + 1 }}</td>
      <td><a href="/company/parties/{{ $b->counterparty_id }}">{{ $b->counterparty?->name }}</a>@if (in_array($b->counterparty?->status, ['blocked', 'suspicious'], true)) <span class="st st-blocked">{{ __('проблемный') }}</span>@endif
        @if ($t->winner_bid_id === $b->id)<br><span class="st st-{{ $t->status === 'awarded' ? 'done' : 'review' }}">{!! ic('award', '', 13) !!}{{ $t->status === 'awarded' ? __('победитель') : __('предложен победителем') }}</span>@endif</td>
      <td class="n"><b class="{{ $b->amount == $min ? 'in-c' : '' }}">{{ money_s($b->amount, $cur) }}</b></td>
      <td class="n">{{ $b->amount == $min ? __('лучшая') : '+'.money_s($b->amount - $min, $cur).' ('.round(($b->amount - $min) / max(1, $min) * 100, 1).'%)' }}</td>
      <td class="n {{ $b->days !== null && $b->days == $minDays ? 'in-c' : '' }}">{{ $b->days !== null ? $b->days.' '.__('дн.') : '—' }}</td>
      <td>{{ $b->note }}</td>
      <td class="right">@if (in_array($t->status, ['open', 'review']) && $u->canEdit() && ($u->isAdmin() || $t->winner_bid_id !== $b->id))
        <form method="post" action="/company/tenders/{{ $t->id }}/award">@csrf<input type="hidden" name="bid_id" value="{{ $b->id }}"><button class="btn btn-s {{ $u->isAdmin() ? 'btn-p' : '' }}">{!! ic('award') !!}{{ $u->isAdmin() ? __('Утвердить победителем') : __('Предложить победителем') }}</button></form>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
  @else<div class="empty">{{ __('Предложений пока нет') }}</div>@endif
  @if ($u->isAdmin() && in_array($t->status, ['open', 'review']) && $bids->count())
  <form method="post" action="/company/tenders/{{ $t->id }}/award" class="mt" data-confirm="{{ __('Отменить тендер?') }}">@csrf<input type="hidden" name="bid_id" value="{{ $bids->first()->id }}"><button class="btn btn-s btn-ghost" name="action" value="cancel">{{ __('Отменить тендер') }}</button></form>
  @endif
</div>
@include('company.erp._thread')
@endsection
