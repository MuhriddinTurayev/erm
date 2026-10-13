@extends('layouts.auth')
@section('title', __('Первый запуск'))
@section('content')
<h1>{{ __('Первый запуск') }}</h1>
<p class="lead">{{ __('Создайте учётную запись администратора платформы. Он добавляет тарифы, подключает ИИ и управляет компаниями-клиентами.') }}</p>
<form method="post" action="/setup">
  @csrf
  <label class="field"><span>{{ __('Ваше имя') }}</span><input name="name" value="{{ old('name') }}" required></label>
  <label class="field"><span>{{ __('Email (логин)') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></label>
  <label class="field"><span>{{ __('Пароль') }}</span><span class="pw"><input type="password" name="password" required minlength="8" autocomplete="new-password">@include('partials.pw')</span><small>{{ __('не меньше 8 символов') }}</small></label>
  <label class="field"><span>{{ __('Пароль ещё раз') }}</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
  <button class="btn btn-p btn-l btn-block" type="submit">{{ __('Создать и войти') }}</button>
</form>
@endsection
