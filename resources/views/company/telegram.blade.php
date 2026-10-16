@extends('layouts.panel')
@section('title', __('Мой Telegram'))
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('send') !!}{{ __('Подключение') }}</h3>
      @if ($u->telegram_chat_id)<span class="badge b-ok">{{ __('подключён') }}</span>@else<span class="badge b-warn">{{ __('не подключён') }}</span>@endif</div>
    @if ($shared && $enabled && $bot)
      <div class="alert alert-warn">{!! ic('triangle-alert') !!}<div>
        <b>{{ __('Этот бот работает ещё на одном сайте.') }}</b>
        {{ __('Telegram отдаёт каждое сообщение только одному сайту, поэтому код привязки и сообщения могут уходить туда (например, на сервер), а не сюда.') }}
        {{ $u->role === 'superadmin' ? __('Каждому сайту нужен свой бот: для копии на компьютере создайте отдельного бота у @BotFather и укажите его токен в настройках платформы.') : __('Подключайте Telegram на основном сайте компании или сообщите администратору платформы.') }}
      </div></div>
    @endif
    @if (! $enabled || ! $bot)
      <div class="alert alert-warn" style="margin:0">{!! ic('triangle-alert') !!}<div>{{ __('Telegram-бот ещё не настроен администратором платформы.') }}</div></div>
    @elseif ($u->telegram_chat_id)
      <p>{{ __('Ваш Telegram привязан. Бот :b принимает ваши расходы, фото чеков и записи журнала работ.', ['b' => '@'.$bot]) }}</p>
      @if ($deaf && $starting)
      {{-- компьютер: фоновый приём сообщений только что запущен сайтом — через несколько секунд бот на связи --}}
      <div class="alert alert-info">{!! ic('refresh-cw') !!}<div>
        <b>{{ __('Бот запускается.') }}</b> {{ __('Через несколько секунд он начнёт отвечать в Telegram — ничего запускать вручную не нужно.') }}
      </div></div>
      @if (! request()->has('w'))<script>setTimeout(() => location.replace(location.pathname + '?w=1'), 6000)</script>@endif
      @elseif ($deaf)
      <div class="alert alert-warn">{!! ic('triangle-alert') !!}<div>
        <b>{{ __('Бот сейчас не получает сообщения — поэтому не отвечает.') }}</b>
        {{ $u->isAdmin() || $u->role === 'superadmin' ? __('Не запущен планировщик. На компьютере (Open Server) запустите файл bot.bat из папки проекта и не закрывайте его окно; на сервере он работает сам.') : __('Сообщите руководителю компании: не запущен планировщик бота.') }}
        <form method="post" action="/me/telegram/check" style="margin-top:8px">@csrf<input type="hidden" name="pull" value="1"><button class="btn btn-s">{!! ic('refresh-cw', '', 14) !!}{{ __('Получить сообщения сейчас') }}</button></form>
      </div></div>
      @endif
      <div class="flex">
        <a class="btn btn-p" href="https://t.me/{{ $bot }}" target="_blank" rel="noopener">{!! ic('external-link') !!}{{ __('Открыть бота') }}</a>
        <form method="post" action="/me/telegram/unlink" data-confirm="{{ __('Отвязать Telegram?') }}">@csrf<button class="btn">{!! ic('unlink') !!}{{ __('Отвязать') }}</button></form>
      </div>
    @else
      <ol class="steps-list">
        <li>{!! __('Откройте бота :b в Telegram.', ['b' => '<a href="https://t.me/'.e($bot).'?start='.e($u->tg_link_code).'" target="_blank" rel="noopener"><b>@'.e($bot).'</b></a>']) !!}</li>
        <li>{{ __('Нажмите «Start» или отправьте боту этот код:') }} <span class="big-code">{{ $u->tg_link_code }}</span></li>
        <li>{{ __('Вернитесь сюда и нажмите кнопку:') }}</li>
      </ol>
      <form method="post" action="/me/telegram/check">@csrf<button class="btn btn-dark">{!! ic('link') !!}{{ __('Проверить подключение') }}</button></form>
    @endif
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('message-square') !!}{{ __('Что умеет бот') }}</h3></div>
    <div class="tg-preview">
      <div class="tg-msg"><b>{{ __('Расход текстом') }}</b><br><span class="code">{{ __('Бетон 25 млн БетонСтрой') }}</span></div>
      <div class="tg-msg"><b>{{ __('Фото чека') }}</b><br>{{ __('ИИ сам прочитает сумму, продавца и дату') }}</div>
      <div class="tg-msg"><b>{{ __('Журнал работ') }}</b><br>{{ __('Фото с объекта и короткое описание за день') }}</div>
      <div class="tg-msg"><b>{{ __('Команды') }}</b><br><span class="code">/obyekt</span> — {{ __('выбрать объект') }}<br><span class="code">/kassa</span> — {{ __('остаток кассы') }}<br><span class="code">/bekor</span> — {{ __('отменить') }}</div>
    </div>
    <p class="muted mt">{{ __('Крупные расходы (больше лимита компании) уходят руководителю на согласование — он подтверждает их одной кнопкой в Telegram.') }}</p>
  </div>
</div>
@endsection
