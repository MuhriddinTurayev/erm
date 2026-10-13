@extends('layouts.panel')
@section('title', __('Тендеры'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws);
  $stl = ['open' => 'приём предложений', 'review' => 'на утверждении', 'awarded' => 'победитель выбран', 'cancelled' => 'отменён'];
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-t">{!! ic('plus') !!}{{ __('Новый тендер') }}</button>@endif
@endsection
@section('content')
<div class="alert alert-info">{!! ic('gavel') !!}<div>{{ __('Соберите предложения нескольких поставщиков или подрядчиков, сравните цену и сроки — победителя утверждает руководитель.') }}</div></div>
<div class="card">
  @if ($list->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Тендер') }}</th><th>{{ __('Объект') }}</th><th class="n">{{ __('Предложений') }}</th><th class="n">{{ __('Лучшая цена') }}</th><th>{{ __('Срок приёма') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($list as $t)
    @php $best = $t->bids->sortBy('amount')->first(); @endphp
    <tr class="row-link" data-href="/company/tenders/{{ $t->id }}"><td><b>{{ $t->title }}</b><br><small>{{ $t->user?->name }} · {{ $t->created_at->format('d.m.Y') }}</small></td>
      <td>{{ $t->project_id ? ($projects[$t->project_id] ?? '—') : '—' }}</td><td class="n">{{ $t->bids_count }}</td>
      <td class="n">{{ $best ? money_s($best->amount, $cur) : '—' }}@if ($best)<br><small>{{ $best->counterparty?->name }}</small>@endif</td>
      <td>{{ $t->deadline?->format('d.m.Y') ?? '—' }}</td><td><span class="st st-{{ $t->status }}">{{ __($stl[$t->status] ?? $t->status) }}</span></td></tr>
  @endforeach
  </tbody></table></div>
  <div class="mt">{{ $list->links() }}</div>
  @else
  <div class="empty">{!! ic('gavel') !!}<div>{{ __('Тендеров пока нет') }}</div></div>
  @endif
</div>

<dialog class="modal" id="dlg-t"><form method="post" action="/company/tenders">@csrf
  <div class="modal-h"><h3>{!! ic('gavel') !!}{{ __('Новый тендер') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Что закупаем / какие работы') }}</span><input name="title" required maxlength="200" placeholder="{{ __('Поставка бетона М300, 400 м³') }}"></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Приём предложений до') }}</span><input type="date" name="deadline"></label>
    <label class="field full"><span>{{ __('По заявке на снабжение') }}</span><select name="supply_request_id"><option value="">—</option>@foreach ($supply as $s)<option value="{{ $s->id }}" @selected((int) request('sr') === $s->id)>№{{ $s->number }} · {{ $projects[$s->project_id] ?? '' }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Условия и требования') }}</span><textarea name="description" rows="4"></textarea></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Создать') }}</button></div>
</form></dialog>
@if (request('sr'))@push('scripts')<script>document.getElementById('dlg-t').showModal()</script>@endpush @endif
@endsection
