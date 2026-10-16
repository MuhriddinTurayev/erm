@extends('layouts.panel')
@section('title', __('Безопасность'))
@php $methods = ['password' => __('пароль'), 'google' => 'Google', 'telegram' => 'Telegram']; @endphp
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('monitor-smartphone') !!}{{ __('Активные сеансы') }}</h3>
      @if ($sessions->count() > 1)<form method="post" action="/security/sessions/others" data-confirm="{{ __('Выйти на всех других устройствах?') }}">@csrf<button class="btn btn-s btn-d">{!! ic('log-out') !!}{{ __('Завершить все другие') }}</button></form>@endif</div>
    @foreach ($sessions as $s)
    <div class="sess {{ $s->current ? 'is-cur' : '' }}">
      <span class="s-ic">{!! ic($s->kind, '', 20) !!}</span>
      <div class="s-t"><b>{{ $s->browser }} · {{ $s->os }}</b><small class="muted">IP {{ $s->ip ?: '—' }} · {{ $s->current ? __('это устройство') : __('активность').' '.$s->at->diffForHumans() }}</small></div>
      @if ($s->current)<span class="badge b-ok">{{ __('текущий') }}</span>
      @else<form method="post" action="/security/sessions/{{ $s->id }}">@csrf<button class="btn btn-s">{{ __('Завершить') }}</button></form>@endif
    </div>
    @endforeach
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('bell') !!}{{ __('Оповещения') }}</h3></div>
    <form method="post" action="/security/alerts">@csrf
      <label class="switch"><input type="checkbox" name="login_alerts" value="1" @checked($u->login_alerts) onchange="this.form.submit()"> {{ __('Сообщать о входе с нового устройства') }}</label>
      <p class="muted" style="font-size:13px">{{ $u->telegram_chat_id ? __('Сообщение придёт в Telegram и на email.') : __('Сообщение придёт на email. Подключите Telegram, чтобы получать мгновенно.') }} @unless ($u->telegram_chat_id)<a href="/me/telegram">{{ __('Подключить') }}</a>@endunless</p>
    </form>
    <div class="card-h mt"><h3>{!! ic('key-round') !!}{{ __('Способы входа') }}</h3></div>
    <div class="list-row"><div>{!! ic('lock', '', 16) !!} {{ __('Email и пароль') }}<br><small class="muted">{{ $u->email }}</small></div><span class="badge b-ok">{{ __('вкл.') }}</span></div>
    @if ($google)
    <div class="list-row"><div>Google<br><small class="muted">{{ $u->google_id ? __('привязан') : __('не привязан') }}</small></div>
      @if ($u->google_id)<form method="post" action="/security/google/unlink">@csrf<button class="btn btn-s">{{ __('Отвязать') }}</button></form>
      @else<a class="btn btn-s" href="/auth/google?link=1">{{ __('Привязать') }}</a>@endif</div>
    @endif
    <div class="list-row"><div>Telegram<br><small class="muted">{{ $u->telegram_chat_id ? __('привязан — можно входить кнопкой «Войти через Telegram»') : __('не привязан') }}</small></div>@unless ($u->telegram_chat_id)<a class="btn btn-s" href="/me/telegram">{{ __('Привязать') }}</a>@endunless</div>
  </div>
</div>

<div class="grid g32 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('history') !!}{{ __('История входов') }}</h3></div>
    <p class="muted" style="font-size:13px;margin:-4px 0 10px">{{ __('Каждое устройство — одна строка. Повторный вход с того же устройства только обновляет время; новая строка появляется, когда входят с другого устройства.') }}</p>
    @if ($events->count())
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Последний вход') }}</th><th>{{ __('Устройство') }}</th><th>IP</th><th>{{ __('Способ') }}</th></tr></thead><tbody>
    @foreach ($events as $e)
      <tr><td class="nowrap">{{ ($e->last_at ?? $e->created_at)?->format('d.m.Y H:i') }}<br><small class="muted">{{ __('входов') }}: {{ $e->logins ?: 1 }}</small></td>
        <td>{!! ic($e->kind, '', 15) !!} {{ $e->browser }} · {{ $e->os }}
          @if ($thisDevice && $e->device === $thisDevice)<span class="badge b-ok">{{ __('это устройство') }}</span>@elseif ($e->new_device && $e->created_at?->gt(now()->subDays(14)))<span class="badge b-warn">{{ __('новое устройство') }}</span>@endif
          <br><small class="muted">{{ __('впервые') }}: {{ $e->created_at?->format('d.m.Y H:i') }}</small></td>
        <td>{{ $e->ip }}@php $other = array_values(array_filter((array) $e->ips, fn ($x) => ($x[0] ?? null) !== $e->ip)); @endphp
          @if ($other)<br><small class="muted" title="@foreach ($other as $x){{ $x[0] }} — {{ \Illuminate\Support\Carbon::parse($x[1])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}&#10;@endforeach">{{ __('ещё с IP') }}: {{ implode(', ', array_slice(array_column($other, 0), 0, 3)) }}{{ count($other) > 3 ? ' +'.(count($other) - 3) : '' }}</small>@endif</td><td>{{ $methods[$e->method] ?? $e->method }}</td></tr>
    @endforeach
    </tbody></table></div>
    @else<div class="empty">{{ __('История появится после следующего входа') }}</div>@endif
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('lock-keyhole') !!}{{ __('Сменить пароль') }}</h3></div>
    <form method="post" action="/security/password" style="display:grid;gap:12px">@csrf
      @unless (session()->has('via_social'))<label class="field"><span>{{ __('Текущий пароль') }}</span><span class="pw"><input type="password" name="current" required autocomplete="current-password">@include('partials.pw')</span></label>@endunless
      <label class="field"><span>{{ __('Новый пароль') }}</span><span class="pw"><input type="password" name="password" required minlength="8" autocomplete="new-password">@include('partials.pw')</span><small>{{ __('не меньше 8 символов, буквы и цифры') }}</small></label>
      <label class="field"><span>{{ __('Новый пароль ещё раз') }}</span><input class="input" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
      <div><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить пароль') }}</button></div>
      <small class="muted">{{ __('После смены пароля все другие устройства будут отключены.') }}</small>
    </form>
  </div>
</div>
<div class="card mt">
  <div class="card-h"><h3>{!! ic('shield-check') !!}{{ __('Как мы защищаем данные') }}</h3></div>
  <div class="mini-stats">
    <div><b style="font-size:15px">{!! ic('lock') !!} HTTPS / TLS</b><span>{{ __('Всё соединение с сервером зашифровано') }}</span></div>
    <div><b style="font-size:15px">{!! ic('key-round') !!} bcrypt</b><span>{{ __('Пароли хранятся только в виде хеша') }}</span></div>
    <div><b style="font-size:15px">{!! ic('database') !!} AES-256</b><span>{{ __('Ключи API и токены зашифрованы') }}</span></div>
    <div><b style="font-size:15px">{!! ic('history') !!} {{ __('Аудит') }}</b><span>{{ __('Каждое изменение записывается: кто, когда, что') }}</span></div>
    <div><b style="font-size:15px">{!! ic('users') !!} {{ __('Роли') }}</b><span>{{ __('Сотрудник видит только свои объекты и разделы') }}</span></div>
  </div>
</div>
@endsection
