@extends('layouts.panel')
@section('title', __('Пользователи'))
@php $max = $ws->maxUsers(); $realObjects = array_filter($objects, fn ($o) => ! $o['demo']) ?: $objects; @endphp
@section('actions')
<span class="badge {{ $max && $users->count() >= $max ? 'b-bad' : 'b-info' }}">{{ $users->count() }} / {{ $max ?: '∞' }}</span>
@endsection
@section('content')
@include('partials.creds')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('users') !!}{{ __('Кто работает в программе') }}</h3></div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Сотрудник') }}</th><th>{{ __('Роль') }}</th><th>{{ __('Объекты') }}</th><th>{{ __('Последний вход') }}</th><th></th></tr></thead><tbody>
    @foreach ($users as $u)
      @php $self = (int) $u->id === (int) auth()->id(); $ids = $u->object_ids ?? []; @endphp
      <tr>
        <td><div class="who"><span class="avatar" @unless ($u->is_active) style="filter:grayscale(1);opacity:.6" @endunless>{{ initials($u->name) }}</span><div><b>{{ $u->name }}</b>@if ($self) <span class="badge plain b-brand">{{ __('это вы') }}</span>@endif<br><small>{{ str_ends_with($u->email, '.oftob.local') ? __('вход по телефону') : $u->email }}{{ $u->phone ? ' · '.$u->phone : '' }}</small></div></div></td>
        <td><span class="badge {{ $u->role === 'admin' ? 'b-brand' : 'b-info' }}">{{ $u->roleName() }}</span>@unless ($u->is_active) <span class="badge b-bad">{{ __('отключён') }}</span>@endunless</td>
        <td><small>{{ $u->role === 'admin' || ! $ids ? __('все') : collect($objects)->whereIn('id', $ids)->pluck('name')->implode(', ') }}</small></td>
        <td><small class="num">{{ $u->last_login_at ? $u->last_login_at->timezone($ws->timezone)->format('d.m.Y H:i') : '—' }}</small></td>
        <td class="right nowrap">
          <details class="more" style="display:inline-block"><summary>{!! ic('pencil') !!}{{ __('Изменить') }}</summary>
            <form method="post" action="/company/users/{{ $u->id }}" class="card popover">
              @csrf @method('PUT')
              <div class="form" style="grid-template-columns:1fr 1fr">
                <label class="field"><span>{{ __('Имя') }}</span><input name="name" value="{{ $u->name }}" required></label>
                <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" value="{{ $u->phone }}"></label>
                <label class="field wide"><span>{{ __('Email (логин)') }}</span><input type="email" name="email" value="{{ str_ends_with($u->email, '.oftob.local') ? '' : $u->email }}"></label>
                <label class="field"><span>{{ __('Роль') }}</span><select name="role">@foreach ($roles as $k => $r)<option value="{{ $k }}" @selected($u->role === $k)>{{ __($r) }}</option>@endforeach</select></label>
                <label class="field"><span>{{ __('Новый пароль') }}</span><input type="password" name="password" placeholder="{{ __('не менять') }}" autocomplete="new-password"></label>
                @if (count($realObjects))
                <div class="field wide"><span>{{ __('Доступ к объектам (пусто — все)') }}</span>
                  @foreach ($realObjects as $o)<label class="check"><input type="checkbox" name="objects[]" value="{{ $o['id'] }}" @checked(in_array($o['id'], $ids, true))> {{ $o['name'] }}</label>@endforeach
                </div>
                @endif
                @unless ($self)<label class="switch wide"><input type="checkbox" name="is_active" value="1" @checked($u->is_active)> {{ __('Доступ включён') }}</label>@endunless
              </div>
              <div class="flex mt"><button class="btn btn-p btn-s">{{ __('Сохранить') }}</button></div>
            </form>
          </details>
          @unless ($self)
          <form method="post" action="/company/users/{{ $u->id }}" data-confirm="{{ __('Удалить пользователя :e?', ['e' => $u->email]) }}" style="display:inline">@csrf @method('DELETE')<button class="icon-btn btn-d" style="width:32px;height:32px" title="{{ __('Удалить') }}">{!! ic('trash-2', '', 16) !!}</button></form>
          @endunless
        </td>
      </tr>
    @endforeach
    </tbody></table></div>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h3>{!! ic('user-plus') !!}{{ __('Добавить пользователя') }}</h3></div>
      <form method="post" action="/company/users">
        @csrf
        <div class="form" style="grid-template-columns:1fr 1fr">
          <label class="field wide"><span>{{ __('Имя') }}</span><input name="name" value="{{ old('name') }}" required></label>
          <label class="field"><span>{{ __('Телефон (логин)') }}</span><input name="phone" value="{{ old('phone') }}" placeholder="+998"></label>
          <label class="field"><span>{{ __('Email (необязательно)') }}</span><input type="email" name="email" value="{{ old('email') }}"></label>
          <label class="field"><span>{{ __('Роль') }}</span><select name="role">@foreach ($roles as $k => $r)<option value="{{ $k }}" @selected(old('role', 'foreman') === $k)>{{ __($r) }}</option>@endforeach</select></label>
          <label class="field"><span>{{ __('Пароль') }}</span><input name="password" minlength="8" autocomplete="new-password" placeholder="{{ __('создастся сам') }}"></label>
          @if (count($realObjects))
          <div class="field wide"><span>{{ __('Доступ к объектам (пусто — все)') }}</span>
            @foreach ($realObjects as $o)<label class="check"><input type="checkbox" name="objects[]" value="{{ $o['id'] }}"> {{ $o['name'] }}</label>@endforeach
          </div>
          @endif
        </div>
        <button class="btn btn-p btn-block mt" @disabled($max && $users->count() >= $max)>{!! ic('plus') !!}{{ __('Добавить') }}</button>
      </form>
    </div>
    <div class="card">
      <div class="card-h"><h3>{!! ic('shield') !!}{{ __('Что может каждая роль') }}</h3></div>
      <dl class="kv">
        <dt>{{ __('Руководитель (всё)') }}</dt><dd>{{ __('всё, включая пользователей и настройки') }}</dd>
        <dt>{{ __('Бухгалтер') }}</dt><dd>{{ __('касса, бухгалтерия, смета, зарплата, отчёты') }}</dd>
        <dt>{{ __('Кассир') }}</dt><dd>{{ __('приходы и расходы, касса') }}</dd>
        <dt>{{ __('Менеджер по продажам') }}</dt><dd>{{ __('лиды, шахматка, продажи, клиенты') }}</dd>
        <dt>{{ __('Прораб') }}</dt><dd>{{ __('объект, график, бригады, табель, техника, материалы, ТБ') }}</dd>
        <dt>{{ __('Только просмотр') }}</dt><dd>{{ __('всё видит, ничего не меняет') }}</dd>
      </dl>
    </div>
  </div>
</div>
@endsection
