@extends('layouts.auth')
@section('title', __('Регистрация компании'))
@section('content')
<h1>{{ __('Регистрация компании') }}</h1>
<p class="lead">{{ __(':n дней бесплатно, без карты. Потом — по выбранному тарифу.', ['n' => $plans->firstWhere('slug', $selected)?->trial_days ?? 14]) }}</p>
<form method="post" action="/register">
  @csrf
  @if ($plans->count())
  <div class="field"><span>{{ __('Тариф') }}</span>
    <div class="plan-pick">
      @foreach ($plans as $p)
      <label><input type="radio" name="plan" value="{{ $p->slug }}" @checked(old('plan', $selected) === $p->slug)><b>{{ $p->title() }}</b><span class="muted">{{ money_s($p->price_month, $p->currency) }}/{{ __('мес') }}</span></label>
      @endforeach
    </div>
  </div>
  @endif
  <div class="form" style="grid-template-columns:1fr 1fr">
    <label class="field"><span>{{ __('Название компании') }}</span><input name="company" value="{{ old('company') }}" required placeholder="{{ __('ООО «Строй Инвест»') }}"></label>
    <label class="field"><span>{{ __('Ваш первый объект') }}</span><input name="first_object" value="{{ old('first_object') }}" maxlength="120" placeholder="{{ __('ЖК «Навруз»') }}"></label>
  </div>
  <p class="muted" style="font-size:13px;margin:-4px 0 14px">{{ __('Компания начинает с чистого листа: данные видят только ваши сотрудники. Остальные объекты добавите в программе.') }}</p>
  <div class="form" style="grid-template-columns:1fr 1fr">
    <label class="field"><span>{{ __('Ваше имя') }}</span><input name="name" value="{{ old('name', session('google_profile.name')) }}" required autocomplete="name"></label>
    <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" value="{{ old('phone') }}" required placeholder="+998 90 123 45 67" autocomplete="tel"></label>
  </div>
  @php $gp = session('google_profile'); @endphp
  @if ($gp)
  <div class="alert alert-ok">{!! ic('badge-check') !!}<div>{{ __('Google: :e — вход без пароля', ['e' => $gp['email']]) }}</div></div>
  <input type="hidden" name="email" value="{{ $gp['email'] }}">
  @else
  <label class="field"><span>{{ __('Email (логин)') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></label>
  <div class="form" style="grid-template-columns:1fr 1fr">
    <label class="field"><span>{{ __('Пароль') }}</span><span class="pw"><input type="password" name="password" required minlength="8" autocomplete="new-password">@include('partials.pw')</span><small>{{ __('не меньше 8 символов') }}</small></label>
    <label class="field"><span>{{ __('Пароль ещё раз') }}</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
  </div>
  @endif
  <button class="btn btn-p btn-l btn-block" type="submit">{{ __('Создать компанию и войти') }}</button>
</form>
@unless ($gp) @include('partials.social') @endunless
<p class="alt">{{ __('Уже есть аккаунт?') }} <a href="/login"><b>{{ __('Войти') }}</b></a></p>
@endsection
