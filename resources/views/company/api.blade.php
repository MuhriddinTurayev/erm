@extends('layouts.panel')
@section('title', __('API и интеграции'))
@section('subtitle', __('Доступ к данным компании для другой программы (сайт, CRM, 1С, свой сервис). Ключи выдаёт администратор платформы — с ролью: ключ может ровно то же, что сотрудник с этой ролью.'))
@use('App\Services\Api\Scopes')
@php
  $proj = collect($ws->projects())->first(fn ($p) => is_array($p) && ! empty($p['id']));
  $apt = $proj ? (\App\Services\Apartments::list($proj)[0] ?? null) : null;
  $sampleApt = $apt ? \App\Services\Api\Present::apartment($apt, (string) $proj['id'], true) : null;
  $names = \App\Services\StateWriter::projects($ws);
  $roles = config('oftob.roles');
@endphp
@section('actions')
<button class="btn btn-p btn-s" data-open="dlg-req">{!! ic('plug') !!}{{ __('Попросить ключ') }}</button>
<a class="btn btn-s" href="#docs">{!! ic('book-open') !!}{{ __('Документация') }}</a>
@endsection
@section('content')
@unless (Scopes::enabled())<div class="alert alert-warn">{!! ic('triangle-alert') !!}<div>{{ __('API временно выключен на платформе: ключи сохранены и заработают, когда его включат.') }}</div></div>@endunless
@if (is_array($request))
<div class="alert alert-info">{!! ic('hourglass') !!}<div>{{ __('Заявка на ключ отправлена :d: «:w», роль — :r. Администратор платформы выдаст ключ и сообщит вам.', ['d' => \Illuminate\Support\Carbon::parse($request['at'])->format('d.m.Y H:i'), 'w' => $request['who'], 'r' => __($roles[$request['role']] ?? $request['role'])]) }}</div></div>
@endif

<div class="card api-steps">
  <div class="api-step"><span>1</span><div><b>{{ __('Попросите ключ') }}</b><small>{{ __('Напишите, кому и зачем нужен доступ и с какой ролью — например, сайту: «Менеджер по продажам».') }}</small></div></div>
  <div class="api-step"><span>2</span><div><b>{{ __('Платформа выдаёт ключ') }}</b><small>{{ __('Ключ получает тот, кому он нужен, вместе с документацией. Права — как у выбранной роли в вашей компании.') }}</small></div></div>
  <div class="api-step"><span>3</span><div><b>{{ __('Вы всё видите и можете остановить') }}</b><small>{{ __('Здесь видно, кто и когда пользовался ключом; каждое изменение — в «Истории изменений». Ключ можно отозвать в любой момент.') }}</small></div></div>
  <div class="api-base"><span class="muted">{{ __('Адрес API') }}</span><code class="code">{{ $base }}</code><button type="button" class="btn btn-s" data-copy="{{ $base }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button>
    <a class="btn btn-s" href="/developers" target="_blank" rel="noopener">{!! ic('external-link') !!}{{ __('Страница для разработчика') }}</a></div>
</div>

<div class="card mt" id="keys">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Ключи вашей компании') }}</h3></div>
  @if ($tokens->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Кому выдан') }}</th><th>{{ __('Роль и объекты') }}</th><th>{{ __('Что может') }}</th><th>{{ __('Пользовались') }}</th><th>{{ __('Срок') }}</th><th></th></tr></thead><tbody>
  @foreach ($tokens as $t)
    @php $off = ! $t->isActive(); @endphp
    <tr class="{{ $off ? 'api-off' : '' }}">
      <td><b>{{ $t->name }}</b>@if ($t->contact)<br><small class="muted">{{ $t->contact }}</small>@endif @if ($t->purpose)<br><small class="muted">{{ $t->purpose }}</small>@endif<br><small class="muted"><span class="code">{{ $t->hint }}</span> · {{ $t->created_at?->format('d.m.Y') }}</small></td>
      <td><b>{{ $t->roleName() }}</b>@if ($t->read_only) <span class="badge plain b-info">{{ __('только чтение') }}</span>@endif<br>
        <small class="muted">{{ $t->allowedObjects() ? implode(', ', array_intersect_key($names, array_flip($t->allowedObjects()))) : __('все объекты роли') }}</small></td>
      <td><div class="api-tags">@foreach (Scopes::summary($t->effectiveScopes()) as $m => $a)<span class="badge plain {{ $a === 'write' ? 'b-warn' : 'b-info' }}" title="{{ $a === 'write' ? __('чтение и запись') : __('чтение') }}">{{ __(Scopes::MODULES[$m][0]) }}{{ $a === 'write' ? ' ✎' : '' }}</span>@endforeach</div>
        @if ($t->webhooks->count())<small class="muted">{{ __('вебхуков') }}: {{ $t->webhooks->count() }}</small>@endif</td>
      <td class="nowrap">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : __('ещё нет') }}@if ($t->last_ip)<br><small class="muted">IP {{ $t->last_ip }}</small>@endif</td>
      <td class="nowrap">@if ($t->revoked_at)<span class="badge b-bad">{{ __('отозван') }}</span>@elseif ($t->expires_at && $t->expires_at->isPast())<span class="badge b-bad">{{ __('срок истёк') }}</span>@elseif ($t->expires_at){{ __('до') }} {{ $t->expires_at->format('d.m.Y') }}@else<span class="badge b-ok">{{ __('бессрочный') }}</span>@endif</td>
      <td class="n">@unless ($off)<form method="post" action="/company/api/tokens/{{ $t->id }}/revoke" data-confirm="{{ __('Отозвать ключ «:n»? Программа, которая им пользуется, сразу потеряет доступ. Новый ключ выдаёт только администратор платформы.', ['n' => $t->name]) }}">@csrf<button class="btn btn-s btn-d">{!! ic('ban') !!}{{ __('Отозвать') }}</button></form>@endunless</td>
    </tr>
  @endforeach
  </tbody></table></div>
  <p class="muted" style="font-size:13px;margin-bottom:0">{{ __('Права ключа — права его роли в вашей компании («Настройки → Права ролей на разделы»). Поменяете права роли — изменится и ключ.') }} ✎ — {{ __('чтение и запись') }}.</p>
  @else
  <div class="empty">{!! ic('key-round') !!}<div>{{ __('Ключей пока нет. Если другой программе нужен доступ к вашим данным — нажмите «Попросить ключ».') }}</div></div>
  @endif
</div>

<div class="card mt" id="docs">
  <div class="card-h"><h3>{!! ic('book-open') !!}{{ __('Документация для разработчика') }}</h3><a class="btn btn-s" href="/api/v1/openapi.json" target="_blank" rel="noopener">{!! ic('download') !!}OpenAPI · Postman</a></div>
  @include('partials.api-docs', ['company' => $ws->name])
</div>

<dialog class="modal" id="dlg-req"><form method="post" action="/company/api/request">@csrf
  <div class="modal-h"><h3>{!! ic('plug') !!}{{ __('Заявка на ключ API') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Кому нужен доступ') }}</span><input name="who" maxlength="120" required value="{{ old('who') }}" placeholder="{{ __('Например: наш сайт, amoCRM, бухгалтерия 1С, разработчик Akmal') }}"></label>
    <label class="field full"><span>{{ __('Зачем') }}</span><textarea name="purpose" rows="3" maxlength="500" required placeholder="{{ __('Например: показывать на сайте свободные квартиры и получать заявки') }}">{{ old('purpose') }}</textarea></label>
    <label class="field"><span>{{ __('С какой ролью') }}</span><select name="role">@foreach ($roles as $k => $n)<option value="{{ $k }}" @selected(old('role', 'manager') === $k)>{{ __($n) }}</option>@endforeach</select><small>{{ __('Ключ сможет то же, что сотрудник с этой ролью') }}</small></label>
    <label class="field"><span>{{ __('Контакт для связи') }}</span><input name="contact" maxlength="160" value="{{ old('contact', auth()->user()->phone) }}" placeholder="+998…"></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('send') !!}{{ __('Отправить заявку') }}</button></div>
</form></dialog>
@endsection
