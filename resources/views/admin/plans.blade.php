@extends('layouts.panel')
@section('title', __('Тарифы'))
@section('content')
<div class="plans">
  @foreach ($plans as $p)
  <div class="plan {{ $p->is_featured ? 'feat' : '' }}" style="{{ $p->is_active ? '' : 'opacity:.6' }}">
    @if ($p->is_featured)<span class="p-tag">{{ __('Популярный') }}</span>@endif
    <div class="flex between"><h2>{{ $p->title() }}</h2><span class="code">{{ $p->slug }}</span></div>
    <div class="muted">{{ $p->about() }}</div>
    <div class="p-price">{{ money_s($p->price_month, $p->currency) }} <small class="muted">/ {{ __('мес') }}</small></div>
    <ul>
      <li>{{ __('Объектов') }}: <b>{{ $p->limitText($p->max_objects) }}</b></li>
      <li>{{ __('Пользователей') }}: <b>{{ $p->limitText($p->max_users) }}</b></li>
      <li>{{ __('ИИ') }}: <b>{{ $p->ai_enabled ? ($p->ai_requests ?: '∞').' / '.__('мес') : '—' }}</b></li>
      <li>{{ __('Компаний на тарифе') }}: <b>{{ $p->workspaces_count }}</b></li>
    </ul>
    @unless ($p->is_active)<span class="badge b-warn">{{ __('не продаётся') }}</span>@endunless
  </div>
  @endforeach
</div>

@foreach ($plans as $p)
<details class="card mt">
  <summary style="cursor:pointer;font-weight:650">{!! ic('pencil') !!}{{ __('Изменить тариф «:p»', ['p' => $p->title()]) }}</summary>
  <form method="post" action="/admin/plans/{{ $p->id }}" class="mt">@csrf @method('PUT')
    @include('admin.plan-form', ['p' => $p])
    <div class="flex mt"><button class="btn btn-p">{{ __('Сохранить') }}</button></div>
  </form>
  <form method="post" action="/admin/plans/{{ $p->id }}" class="mt" onsubmit="return confirm(@js(__('Удалить тариф?')))">@csrf @method('DELETE')<button class="btn btn-s btn-d">{{ __('Удалить тариф') }}</button></form>
</details>
@endforeach

<div class="card mt">
  <div class="card-h"><h3>{!! ic('plus') !!}{{ __('Новый тариф') }}</h3></div>
  <form method="post" action="/admin/plans">@csrf
    @include('admin.plan-form')
    <div class="flex mt"><button class="btn btn-p">{{ __('Добавить тариф') }}</button></div>
  </form>
</div>
@endsection
