@extends('layouts.panel')
@section('title', __('Контрагенты'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws);
  $kinds = \App\Http\Controllers\Company\PartyController::KINDS; $sts = \App\Http\Controllers\Company\PartyController::STATUSES;
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-cp">{!! ic('user-plus') !!}{{ __('Новый контрагент') }}</button>@endif
@endsection
@section('content')
<div class="grid" style="grid-template-columns:240px minmax(0,1fr);align-items:start" id="cp-grid">
  <div class="card" style="padding:12px">
    <div class="sb-sec" style="color:var(--muted);padding:4px 8px 8px">{{ __('Папки') }}</div>
    <nav class="files">
      <a class="chip {{ ! array_key_exists('folder', $f) ? 'on' : '' }}" href="/company/parties">{!! ic('users', '', 14) !!}{{ __('Все') }}</a>
      @foreach ($folders as $fo => $n)
        <a class="chip {{ ($f['folder'] ?? null) === $fo ? 'on' : '' }}" href="?folder={{ urlencode($fo) }}">{!! ic('folder', '', 14) !!}{{ __($fo) }}<span class="n">{{ $n }}</span></a>
      @endforeach
      <a class="chip {{ array_key_exists('folder', $f) && $f['folder'] === null ? '' : '' }}" href="?folder=">{!! ic('folder', '', 14) !!}{{ __('Без папки') }}</a>
    </nav>
    <div class="sb-sec" style="color:var(--muted);padding:16px 8px 8px">{{ __('Статус') }}</div>
    <div class="files">
      @foreach ($sts as $k => $n)
        <a class="chip {{ ($f['status'] ?? '') === $k ? 'on' : '' }}" href="?status={{ $k }}"><span class="st st-{{ $k }}" style="padding:0;background:none"></span>{{ __($n) }}<span class="n">{{ $stats[$k] ?? 0 }}</span></a>
      @endforeach
    </div>
  </div>
  <div class="card">
    <form method="get" class="toolbar">
      @foreach (['folder', 'status'] as $k) @if (isset($f[$k]))<input type="hidden" name="{{ $k }}" value="{{ $f[$k] }}">@endif @endforeach
      <input class="input grow" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('Название, ИНН или телефон') }}">
      <select class="input" name="kind" onchange="this.form.submit()"><option value="">{{ __('Все типы') }}</option>@foreach ($kinds as $k => $n)<option value="{{ $k }}" @selected(($f['kind'] ?? '') === $k)>{{ __($n) }}</option>@endforeach</select>
      <button class="btn btn-s">{!! ic('search') !!}</button>
    </form>
    @if ($list->count())
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Контрагент') }}</th><th>{{ __('Тип') }}</th><th>{{ __('ИНН') }}</th><th class="n">{{ __('Оплачено им') }}</th><th class="n">{{ __('Долг по договорам') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
    @foreach ($list as $c)
      @php $debt = $debts[$c->id] ?? 0; $t = $turn[$c->id] ?? null; @endphp
      <tr class="row-link" data-href="/company/parties/{{ $c->id }}">
        <td><b>{{ $c->name }}</b><br><small>{{ $c->phone }}@if ($c->folder) · {!! ic('folder', '', 12) !!} {{ __($c->folder ?? '') }}@endif · {{ __('договоров') }}: {{ $c->contracts_count }}</small></td>
        <td>{{ __($kinds[$c->kind] ?? $c->kind) }}</td>
        <td class="nowrap">{{ $c->inn ?: '—' }}</td>
        <td class="n">{{ $t ? money_s($t->o, $cur) : '—' }}</td>
        <td class="n">@if (abs($debt) >= 1)<b class="{{ $debt > 0 ? 'out-c' : 'in-c' }}">{{ $debt > 0 ? __('мы должны') : __('нам должны') }} {{ money_s(abs($debt), $cur) }}</b>@else — @endif</td>
        <td><span class="st st-{{ $c->status }}" @if ($c->status_reason) title="{{ $c->status_reason }}" @endif>{{ __($sts[$c->status] ?? $c->status) }}</span></td>
      </tr>
    @endforeach
    </tbody></table></div>
    <div class="mt">{{ $list->links() }}</div>
    @else
    <div class="empty">{!! ic('contact') !!}<div>{{ __('Контрагентов пока нет') }}</div></div>
    @endif
  </div>
</div>

@include('company.erp._party_form', ['cp' => null])
@endsection
@push('head')<style>@media (max-width:900px){#cp-grid{grid-template-columns:1fr!important}}</style>@endpush
