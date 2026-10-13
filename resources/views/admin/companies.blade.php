@extends('layouts.panel')
@section('title', __('Компании'))
@section('content')
<form method="get" class="card flex" style="padding:14px">
  <input class="input grow" name="q" value="{{ $q }}" placeholder="{{ __('Поиск: название, телефон, email') }}" style="min-width:200px">
  <select class="input" name="state" style="width:auto"><option value="">{{ __('Все статусы') }}</option>@foreach (['trial' => __('Пробный период'), 'active' => __('Активна'), 'expired' => __('Срок истёк'), 'suspended' => __('Заблокирована')] as $k => $n)<option value="{{ $k }}" @selected($state === $k)>{{ $n }}</option>@endforeach</select>
  <button class="btn btn-dark">{{ __('Найти') }}</button>
</form>
<div class="card mt">
  <div class="card-h"><h3>{!! ic('building-2') !!}{{ __('Найдено') }}: {{ $list->count() }}</h3></div>
  @if ($list->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Компания') }}</th><th>{{ __('Тариф') }}</th><th>{{ __('Статус') }}</th><th>{{ __('Доступ до') }}</th><th class="n">{{ __('Объекты') }}</th><th class="n">{{ __('Польз.') }}</th><th></th></tr></thead><tbody>
    @foreach ($list as $w)
    <tr>
      <td><a href="/admin/companies/{{ $w->id }}"><b>{{ $w->name }}</b></a>@if ($w->plan_request) <span class="badge b-brand">{{ __('заявка') }}</span>@endif<br><small>{{ $w->phone ?: '—' }} · {{ __('с') }} {{ $w->created_at->format('d.m.Y') }}</small></td>
      <td>{{ $w->plan?->title() ?? '—' }}</td>
      <td>@include('admin.state', ['w' => $w])</td>
      <td>{{ $w->accessUntil()?->format('d.m.Y') ?? __('без срока') }}</td>
      <td class="n">{{ \App\Models\Workspace::countObjects($w->projects()) }}</td>
      <td class="n">{{ $w->users_count }}</td>
      <td class="right"><a class="btn btn-s" href="/admin/companies/{{ $w->id }}">{{ __('Открыть') }}</a></td>
    </tr>
    @endforeach
  </tbody></table></div>
  @else
    <div class="empty">{!! ic('search') !!}{{ __('Ничего не найдено') }}</div>
  @endif
</div>
@endsection
