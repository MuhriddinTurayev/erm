@extends('layouts.panel')
@section('title', __('Настройки платформы'))
@section('content')
<form method="post" action="/admin/settings">
@csrf
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h3>{!! ic('globe') !!}{{ __('Платформа') }}</h3></div>
    <div class="form">
      <label class="field wide"><span>{{ __('Название платформы') }}</span><input name="brand" value="{{ old('brand', $v('brand')) }}" required></label>
      <label class="field"><span>{{ __('Телефон поддержки') }}</span><input name="support_phone" value="{{ old('support_phone', $v('support_phone')) }}"></label>
      <label class="field"><span>{{ __('Telegram поддержки') }}</span><input name="support_telegram" value="{{ old('support_telegram', $v('support_telegram')) }}" placeholder="@oftob_support"></label>
      <label class="field"><span>{{ __('Email поддержки') }}</span><input type="email" name="support_email" value="{{ old('support_email', $v('support_email')) }}"></label>
      <label class="field"><span>{{ __('Тариф по умолчанию при регистрации') }}</span><select name="default_plan">@foreach ($plans as $p)<option value="{{ $p->slug }}" @selected($v('default_plan') === $p->slug)>{{ $p->title() }}</option>@endforeach</select></label>
      <label class="switch wide"><input type="checkbox" name="registration_open" value="1" @checked($v('registration_open') === '1')> {{ __('Открыта регистрация новых компаний на сайте') }}</label>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('send') !!}{{ __('Telegram-бот для отчётов') }}</h3>@if ($hasToken)<span class="badge b-ok">{{ '@'.$v('telegram_bot') }}</span>@endif</div>
    <ol style="margin:0 0 14px;padding-left:20px;display:grid;gap:6px;font-size:14px">
      <li>{!! __('Откройте :b в Telegram и создайте бота командой /newbot.', ['b' => '<a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>']) !!}</li>
      <li>{{ __('Скопируйте токен и вставьте сюда.') }}</li>
      <li>{{ __('Руководители компаний подключают отчёты в своих настройках по коду.') }}</li>
    </ol>
    <div class="form">
      <label class="field wide"><span>{{ __('Токен бота') }}</span><input type="password" name="telegram_token" autocomplete="off" placeholder="{{ $hasToken ? __('сохранён — оставьте пустым, чтобы не менять') : '123456:ABC-DEF…' }}"></label>
      @if ($hasToken)<label class="check wide"><input type="checkbox" name="telegram_clear" value="1"> {{ __('Отключить бота') }}</label>@endif
    </div>
    @if ($hasToken && $tgPoll)
      @if ($tgShared)
      <div class="alert alert-warn mt" style="margin-bottom:0">{!! ic('triangle-alert') !!}<div>
        <b>{{ __('Этот бот работает ещё на одном сайте.') }}</b>
        {{ __('Telegram отдаёт каждое сообщение только одному сайту, поэтому коды привязки и сообщения сотрудников теряются между ними. Каждому сайту нужен свой бот: для копии на компьютере создайте отдельного тестового бота у @BotFather и вставьте его токен здесь, на компьютере.') }}
      </div></div>
      @elseif ($tgListening)
      <div class="alert alert-ok mt" style="margin-bottom:0">{!! ic('circle-check') !!}<div>{{ __('Бот на связи: этот сайт получает сообщения боту.') }}</div></div>
      @else
      <div class="alert alert-warn mt" style="margin-bottom:0">{!! ic('triangle-alert') !!}<div>{{ __('Бот сейчас не получает сообщения: не запущен планировщик. На сервере он работает сам; на компьютере (Open Server) запустите bot.bat из папки проекта.') }}</div></div>
      @endif
    @endif
  </div>
</div>
<div class="card mt">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Вход через Google') }}</h3>@if ($v('google_client_id'))<span class="badge b-ok">{{ __('включён') }}</span>@endif</div>
  <ol style="margin:0 0 14px;padding-left:20px;display:grid;gap:6px;font-size:14px">
    <li>{!! __('Откройте :l → «Создать учётные данные» → «Идентификатор клиента OAuth» → «Веб-приложение».', ['l' => '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>']) !!}</li>
    <li>{{ __('В «Разрешённые URI перенаправления» добавьте:') }} <span class="code">{{ url('/auth/google/callback') }}</span></li>
    <li>{{ __('Скопируйте Client ID и Client secret сюда.') }}</li>
  </ol>
  <div class="form">
    <label class="field"><span>Client ID</span><input name="google_client_id" value="{{ old('google_client_id', $v('google_client_id')) }}" autocomplete="off" placeholder="123….apps.googleusercontent.com"></label>
    <label class="field"><span>Client secret</span><input type="password" name="google_client_secret" autocomplete="off" placeholder="{{ $v('google_client_secret') ? __('сохранён — оставьте пустым, чтобы не менять') : 'GOCSPX-…' }}"></label>
  </div>
  <p class="muted" style="font-size:13px;margin-bottom:0">{{ __('Вход через Telegram включается автоматически, когда подключён бот. Для кнопки на сайте укажите домен сайта у @BotFather командой /setdomain.') }}</p>
</div>
<div class="mt"><button class="btn btn-p">{{ __('Сохранить') }}</button></div>
</form>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('alarm-clock') !!}{{ __('Автоматическая отправка отчётов') }}</h3></div>
  <p style="margin-top:0">{{ __('Отчёты отправляет планировщик Laravel. На сервере должна работать одна из команд:') }}</p>
  <p><span class="code">php artisan schedule:work</span> {{ __('— на компьютере (start.bat запускает её сам)') }}</p>
  <p><span class="code">* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1</span> {{ __('— на хостинге (cron)') }}</p>
</div>
@endsection
