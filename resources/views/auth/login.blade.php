@extends('layouts.auth')
@section('title', __('Вход'))
@section('content')
<h1>{{ __('Вход в систему') }}</h1>
<p class="lead">{{ __('Введите email или телефон и пароль, которые выдал руководитель компании.') }}</p>
<form method="post" action="/login">
  @csrf
  <label class="field"><span>{{ __('Email или телефон') }}</span><input name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="name@company.uz / +998 90 123 45 67"></label>
  <label class="field"><span>{{ __('Пароль') }}</span><span class="pw"><input type="password" name="password" required autocomplete="current-password">@include('partials.pw')</span></label>
  <label class="check"><input type="checkbox" name="remember" value="1" checked> {{ __('Запомнить меня') }}</label>
  <button class="btn btn-p btn-l btn-block" type="submit">{{ __('Войти') }}</button>
</form>
@include('partials.social')
@if (\App\Models\PlatformSetting::get('registration_open') === '1')
<p class="alt">{{ __('Ещё нет аккаунта?') }} <a href="/register"><b>{{ __('Зарегистрировать компанию') }}</b></a></p>
@endif
@endsection
