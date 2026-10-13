@extends('layouts.auth')
@section('title', __('Первый запуск'))
@section('content')
<h1>{{ __('Первый запуск') }}</h1>
@if (! ($allowed ?? false))
  {{-- защита: чужой человек не может первым открыть сайт и стать владельцем платформы --}}
  <p class="lead">{{ __('Владельца платформы может создать только администратор сервера.') }}</p>
  <ol class="steps-list" style="font-size:14px">
    <li>{{ __('Откройте эту страницу на самом сервере (http://localhost/setup)') }}</li>
    <li>{{ __('или впишите ключ в файл .env: SETUP_KEY=любой_секретный_ключ и откройте /setup?key=этот_ключ') }}</li>
    <li>{{ __('или выполните на сервере команду:') }} <code>php artisan oftob:user email пароль --role=superadmin</code></li>
  </ol>
@else
<p class="lead">{{ __('Создайте учётную запись администратора платформы. Он добавляет тарифы, подключает ИИ и управляет компаниями-клиентами.') }}</p>
<form method="post" action="/setup">
  @csrf
  <input type="hidden" name="key" value="{{ $key ?? '' }}">
  <label class="field"><span>{{ __('Ваше имя') }}</span><input name="name" value="{{ old('name') }}" required></label>
  <label class="field"><span>{{ __('Email (логин)') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></label>
  <label class="field"><span>{{ __('Пароль') }}</span><span class="pw"><input type="password" name="password" required minlength="8" autocomplete="new-password">@include('partials.pw')</span><small>{{ __('не меньше 8 символов') }}</small></label>
  <label class="field"><span>{{ __('Пароль ещё раз') }}</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
  <button class="btn btn-p btn-l btn-block" type="submit">{{ __('Создать и войти') }}</button>
</form>
@endif
@endsection
