@extends('layouts.panel')
@section('title', __('Настройки компании'))
@section('content')
<form method="post" action="/company/settings">
@csrf
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h3>{!! ic('building-2') !!}{{ __('Компания') }}</h3></div>
    <div class="form">
      <label class="field wide"><span>{{ __('Название компании') }}</span><input name="name" value="{{ old('name', $ws->name) }}" required></label>
      <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" value="{{ old('phone', $ws->phone) }}"></label>
      <label class="field"><span>{{ __('Язык отчётов') }}</span><select name="locale">@foreach (config('oftob.locales') as $k => [$n]) <option value="{{ $k }}" @selected(old('locale', $ws->locale) === $k)>{{ $n }}</option>@endforeach</select></label>
      <label class="field wide"><span>{{ __('Часовой пояс') }}</span><select name="timezone">@foreach (config('oftob.timezones') as $k => $n) <option value="{{ $k }}" @selected(old('timezone', $ws->timezone) === $k)>{{ __($n) }}</option>@endforeach</select></label>
    </div>
  </div>

  <div class="card" id="report">
    <div class="card-h"><h3>{!! ic('bell') !!}{{ __('Ежедневный отчёт руководителю') }}</h3></div>
    <div class="form">
      <label class="switch wide"><input type="checkbox" name="report_enabled" value="1" @checked($ws->report_enabled)> {{ __('Отправлять каждый день') }}</label>
      <label class="field"><span>{{ __('Время отправки') }}</span><input type="time" name="report_time" value="{{ old('report_time', $ws->report_time) }}" required></label>
      <label class="field"><span>{{ __('Email руководителя') }}</span><input type="email" name="report_email" value="{{ old('report_email', $ws->report_email) }}" placeholder="boss@company.uz"></label>
      <label class="field wide"><span>{{ __('Telegram chat_id (через запятую)') }}</span><input name="report_chats" value="{{ old('report_chats', $ws->report_chats) }}" placeholder="{{ __('подключите кнопкой ниже') }}"><small>{{ __('Можно отправлять нескольким людям: директору, учредителю, главбуху.') }}</small></label>
      <label class="switch wide"><input type="checkbox" name="report_ai" value="1" @checked($ws->report_ai)> {{ __('Добавлять в отчёт вывод ИИ-аналитика') }}</label>
    </div>
  </div>
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('sparkles') !!}{{ __('ИИ-консультант') }}</h3>
    <span class="muted">{{ __('Тариф') }}: {{ $ws->plan?->title() }} · {{ $ws->aiLimit() ? __('лимит :n запросов в месяц', ['n' => $ws->aiLimit()]) : __('без лимита') }}</span></div>
  @if ($providers->count())
  <div class="form">
    <label class="field"><span>{{ __('Какой ИИ использовать') }}</span><select name="ai_provider_id"><option value="">{{ __('По умолчанию платформы') }}</option>@foreach ($providers as $p)<option value="{{ $p->id }}" @selected((int) $ws->ai_provider_id === $p->id)>{{ $p->name }} ({{ $p->model }})</option>@endforeach</select></label>
    <div class="field"><span>{{ __('Использовано в этом месяце') }}</span>
      <div class="flex" style="min-height:40px">
        @forelse ($aiMonth as $row)
          <span class="badge b-brand">{{ ['chat' => __('чат'), 'report' => __('отчёты')][$row->kind] ?? $row->kind }}: {{ $row->n }}</span>
        @empty
          <span class="muted">{{ __('ещё не использовался') }}</span>
        @endforelse
      </div>
    </div>
  </div>
  @else
    <div class="alert alert-warn" style="margin:0">{!! ic('triangle-alert') !!}<div>{{ __('ИИ ещё не подключён администратором платформы. Автопроверка в программе работает и без него.') }}</div></div>
  @endif
</div>
<div class="mt flex"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить настройки') }}</button></div>
</form>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('send') !!}{{ __('Подключить Telegram руководителя') }}</h3>
    @if ($ws->report_chats)<span class="badge b-ok">{{ __('подключено чатов') }}: {{ count(array_filter(explode(',', $ws->report_chats))) }}</span>@endif</div>
  @if ($tgEnabled && $bot)
    <ol class="steps-list">
      <li>{!! __('Откройте бота :b в Telegram (с телефона руководителя).', ['b' => '<a href="https://t.me/'.e($bot).'?start='.e($ws->report_link_code).'" target="_blank" rel="noopener"><b>@'.e($bot).'</b></a>']) !!}</li>
      <li>{{ __('Нажмите «Start» или отправьте боту этот код:') }} <span class="big-code">{{ $ws->report_link_code }}</span></li>
      <li>{{ __('Вернитесь сюда и нажмите кнопку:') }}</li>
    </ol>
    <form method="post" action="/company/telegram/link">@csrf<button class="btn btn-dark">{!! ic('link') !!}{{ __('Проверить и подключить') }}</button></form>
  @else
    <div class="alert alert-warn" style="margin:0">{!! ic('triangle-alert') !!}<div>{{ __('Telegram-бот ещё не настроен администратором платформы. Пока отчёт можно получать на email.') }}</div></div>
  @endif
</div>

<form method="post" action="/company/settings/ops" class="card mt" id="ops">@csrf
  <div class="card-h"><h3>{!! ic('shield-check') !!}{{ __('Согласование, шахматка для сайта, Didox') }}</h3></div>
  <div class="form">
    <label class="field"><span>{{ __('Расходы от этой суммы — только с согласия руководителя') }}</span><input type="number" min="0" step="1" name="approval_limit" value="{{ $ws->approval_limit > 0 ? (int) $ws->approval_limit : '' }}" placeholder="{{ __('0 — без согласования') }}"><small>{{ __('Касается расходов из Telegram и касс. Крупные платежи оформляются заявкой на оплату.') }}</small></label>
    <div class="field"><span>{{ __('Шахматка с ценами для вашего сайта') }}</span>
      <label class="switch"><input type="checkbox" name="public_enabled" value="1" @checked($ws->public_enabled)> {{ __('Показывать свободные квартиры и принимать заявки') }}</label>
      @if ($ws->public_enabled && $ws->public_key)<small><a href="{{ url('/s/'.$ws->public_key) }}" target="_blank">{{ url('/s/'.$ws->public_key) }}</a> — {{ __('вставьте ссылку на сайт или в Instagram') }}</small>@endif</div>
    <label class="field"><span>{{ __('API-ключ Didox (электронные договоры и счета-фактуры)') }}</span><input type="password" name="didox_token" autocomplete="off" placeholder="{{ $ws->setting('didox.token') ? __('сохранён — оставьте пустым, чтобы не менять') : __('не подключено') }}"></label>
  </div>
  <div class="mt"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form>

<div class="card mt" id="lists">
  <div class="card-h"><h3>{!! ic('list') !!}{{ __('Справочники') }}</h3><span class="muted" style="font-size:13px">{{ __('Списки для выбора в формах. В самих формах можно вписать и своё значение.') }}</span></div>
  <div class="grid g2">
  @foreach (\App\Services\Lists::KEYS as $lk => [$lt, $lh])
    <form method="post" action="/company/settings/lists" class="lst">@csrf<input type="hidden" name="key" value="{{ $lk }}">
      <b>{{ __($lt) }}</b> @unless (\App\Services\Lists::own($ws, $lk))<span class="badge plain">{{ __('стандартный') }}</span>@endunless
      <small class="muted">{{ __($lh) }}</small>
      <div class="chips-ed">
        @foreach (\App\Services\Lists::get($ws, $lk) as $v)<span class="chip">{{ __($v) }}<input type="hidden" name="items[]" value="{{ $v }}"><button type="button" class="chip-x" aria-label="{{ __('Убрать') }}">×</button></span>@endforeach
        <input class="chip-in" maxlength="60" placeholder="{{ __('Добавить: впишите и нажмите Enter') }}">
      </div>
      <div class="flex" style="gap:8px"><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Сохранить') }}</button>
        @if (\App\Services\Lists::own($ws, $lk))<button class="btn btn-s btn-ghost" name="reset" value="1" data-confirm="{{ __('Вернуть стандартный список?') }}">{!! ic('rotate-ccw') !!}{{ __('Стандартный') }}</button>@endif</div>
    </form>
  @endforeach
  </div>
</div>
@push('scripts')
<script>
// справочник: Enter или запятая — добавить, × — убрать; при сохранении недописанное тоже попадает в список
(() => {
  const add = (box, v) => { v = v.trim(); if (!v) return; if ([...box.querySelectorAll('input[name="items[]"]')].some(i => i.value.toLowerCase() === v.toLowerCase())) return;
    const s = document.createElement('span'); s.className = 'chip'; s.textContent = v; const h = document.createElement('input'); h.type = 'hidden'; h.name = 'items[]'; h.value = v;
    const x = document.createElement('button'); x.type = 'button'; x.className = 'chip-x'; x.textContent = '×'; s.append(h, x); box.insertBefore(s, box.querySelector('.chip-in')); };
  document.querySelectorAll('.chips-ed').forEach(box => {
    const inp = box.querySelector('.chip-in');
    inp.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(box, inp.value); inp.value = ''; } });
    box.addEventListener('click', e => { if (e.target.closest('.chip-x')) e.target.closest('.chip').remove(); else inp.focus(); });
    box.closest('form').addEventListener('submit', () => { add(box, inp.value); inp.value = ''; });
  });
})();
</script>
@endpush

@php $mods = config('oftob.modules'); $roleList = array_diff_key(config('oftob.roles'), ['admin' => 1]); @endphp
<form method="post" action="/company/roles" class="card mt" id="roles">@csrf
  <div class="card-h"><h3>{!! ic('user-cog') !!}{{ __('Права ролей на разделы') }}</h3><span class="muted" style="font-size:13px">{{ __('Руководитель видит всё. Доступ к объектам задаётся у каждого сотрудника.') }}</span></div>
  <div class="tbl-wrap"><table class="tbl perm-tbl"><thead><tr><th>{{ __('Раздел') }}</th>@foreach ($roleList as $r => $rn)<th>{{ __($rn) }}</th>@endforeach</tr></thead><tbody>
  @foreach ($mods as $mk => [$mn, $mi])
    <tr><td>{!! ic($mi, '', 15) !!} {{ __($mn) }}</td>@foreach ($roleList as $r => $rn)<td><input type="checkbox" name="m[{{ $r }}][]" value="{{ $mk }}" @checked(in_array($mk, $ws->roleModules($r), true))></td>@endforeach</tr>
  @endforeach
  </tbody></table></div>
  <div class="mt"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить права') }}</button> <small class="muted">{{ __('Роль «Только просмотр» видит разделы, но ничего не меняет.') }}</small></div>
</form>
@endsection
