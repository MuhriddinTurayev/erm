@extends('layouts.panel')
@section('title', __('API и интеграции'))
@section('subtitle', __('Кто-то хочет пользоваться данными или функциями платформы — ключ выдаёте вы: для одной компании и с одной ролью. Ключ может ровно то же, что сотрудник компании с этой ролью, и только на выбранных объектах.'))
@use('App\Services\Api\Scopes')
@use('App\Services\Api\Webhooks')
@php
  $roles = config('oftob.roles');
  $EV = Webhooks::EVENTS;
  $newKey = session('apiKey');
  $names = $companies->mapWithKeys(fn ($w) => [$w->id => collect($objectsOf[$w->id] ?? [])->pluck('name', 'id')->all()]);
  $openHook = session('hook');
  $sendText = $newKey ? __('Доступ к API Oftob_Sohtumon')."\n".__('Компания').': '.$newKey['company']."\n".__('Роль').': '.$newKey['role']."\n".__('Адрес API').': '.$base."\n".__('Ключ').': '.$newKey['key']."\n".__('Документация').': '.url('/developers') : '';
@endphp
@section('actions')
<button class="btn btn-p btn-s" data-open="dlg-key" data-fill='{"id":"","expires":"0"}'>{!! ic('key-round') !!}{{ __('Выдать ключ') }}</button>
<a class="btn btn-s" href="/developers" target="_blank" rel="noopener">{!! ic('book-open') !!}{{ __('Документация') }}</a>
@endsection
@section('content')

@if ($newKey)
<div class="card api-new">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Ключ «:n» выдан', ['n' => $newKey['name']]) }}</h3><span class="badge b-warn">{{ __('показывается один раз') }}</span></div>
  <p class="muted" style="margin-top:0">{{ __(':c · роль «:r». Скопируйте и передайте получателю — мы храним только отпечаток ключа, увидеть его ещё раз нельзя (можно выдать новый).', ['c' => $newKey['company'], 'r' => $newKey['role']]) }}</p>
  <div class="api-secret"><code class="code">{{ $newKey['key'] }}</code><button type="button" class="btn btn-p btn-s" data-copy="{{ $newKey['key'] }}">{!! ic('copy') !!}<span>{{ __('Скопировать ключ') }}</span></button>
    <button type="button" class="btn btn-s" data-copy="{{ $sendText }}">{!! ic('send') !!}<span>{{ __('Скопировать всё для получателя') }}</span></button></div>
  <pre class="api-pre">{{ $sendText }}</pre>
</div>
@endif

@unless ($enabled)<div class="alert alert-warn">{!! ic('triangle-alert') !!}<div>{{ __('API выключен на платформе: ни один ключ не работает, вебхуки не отправляются. Ключи и настройки сохранены.') }}</div></div>@endunless

<div class="card api-steps">
  <div class="api-step"><span>1</span><div><b>{{ __('Компания и роль') }}</b><small>{{ __('Чьи данные и с какими правами: «Менеджер по продажам» — квартиры и заявки, «Бухгалтер» — деньги и склад, «Только просмотр» — без изменений.') }}</small></div></div>
  <div class="api-step"><span>2</span><div><b>{{ __('Отдайте ключ получателю') }}</b><small>{{ __('Партнёру, разработчику или другой программе — вместе со ссылкой на документацию /developers.') }}</small></div></div>
  <div class="api-step"><span>3</span><div><b>{{ __('Вебхук — по желанию') }}</b><small>{{ __('Программа получателя сразу узнаёт о событиях — только тех разделов, что видит роль ключа.') }}</small></div></div>
  <form method="post" action="/admin/api/toggle" class="api-base" data-confirm="{{ $enabled ? __('Выключить API на всей платформе? Все ключи перестанут работать, пока не включите снова.') : __('Включить API на платформе?') }}">@csrf
    <input type="hidden" name="enabled" value="{{ $enabled ? 0 : 1 }}">
    <span class="muted">{{ __('API на платформе') }}:</span> @if ($enabled)<span class="badge b-ok">{{ __('включён') }}</span>@else<span class="badge b-bad">{{ __('выключен') }}</span>@endif
    <button class="btn btn-s {{ $enabled ? 'btn-d' : 'btn-p' }}">{!! ic($enabled ? 'ban' : 'circle-play') !!}{{ $enabled ? __('Выключить для всех') : __('Включить') }}</button>
    <span class="muted" style="margin-left:auto">{{ __('Адрес API') }}</span><code class="code">{{ $base }}</code>
  </form>
</div>

<div class="kpis mt">
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Компаний с ключами') }}</div><div class="k-v">{{ $kpi['companies'] }}</div><div class="k-s">{{ __('действующих ключей') }}: {{ $kpi['keys'] }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('key-round') !!}{{ __('Пользовались за сутки') }}</div><div class="k-v">{{ $kpi['used'] }}</div><div class="k-s">{{ __('ключей') }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('link') !!}{{ __('Вебхуки') }}</div><div class="k-v">{{ $kpi['hooks'] }}</div><div class="k-s">@if ($kpi['hooksOff'])<span class="bad">{{ __('выключено') }}: {{ $kpi['hooksOff'] }}</span>@else{{ __('все работают') }}@endif</div></div>
  <div class="kpi"><div class="k-l">{!! ic('send') !!}{{ __('Событий за сутки') }}</div><div class="k-v">{{ $kpi['sent'] }}</div><div class="k-s">@if ($kpi['failed'])<span class="bad">{{ __('не доставлено') }}: {{ $kpi['failed'] }}</span>@else{{ __('без ошибок') }}@endif</div></div>
</div>

@if ($requests->count())
<div class="card" id="requests">
  <div class="card-h"><h3>{!! ic('inbox') !!}{{ __('Компании просят ключ') }}</h3><span class="badge b-info">{{ $requests->count() }}</span></div>
  @foreach ($requests as $w)
    @php $rq = $w->setting('api_request'); @endphp
    <div class="list-row">
      <div><b>{{ $w->name }}</b> — {{ $rq['who'] ?? '' }} · <span class="badge plain b-brand">{{ __($roles[$rq['role'] ?? ''] ?? ($rq['role'] ?? '')) }}</span><br>
        <small class="muted">{{ $rq['purpose'] ?? '' }}</small><br><small class="muted">{{ $rq['by'] ?? '' }}@if (! empty($rq['contact'])) · {{ $rq['contact'] }}@endif · {{ ! empty($rq['at']) ? \Illuminate\Support\Carbon::parse($rq['at'])->format('d.m.Y H:i') : '' }}</small></div>
      <div class="flex api-act">
        <button type="button" class="btn btn-p btn-s" data-open="dlg-key" data-fill="{{ json_encode(['id' => '', 'workspace_id' => $w->id, 'name' => $rq['who'] ?? '', 'contact' => $rq['contact'] ?? '', 'purpose' => $rq['purpose'] ?? '', 'role' => $rq['role'] ?? 'viewer', 'expires' => '0']) }}">{!! ic('key-round') !!}{{ __('Выдать ключ') }}</button>
        <form method="post" action="/admin/api/requests/{{ $w->id }}/dismiss" data-confirm="{{ __('Закрыть заявку без ключа?') }}">@csrf<button class="btn btn-s">{!! ic('x') !!}{{ __('Отклонить') }}</button></form>
      </div>
    </div>
  @endforeach
</div>
@endif

<form method="get" class="card flex mt" style="gap:10px;flex-wrap:wrap">
  <input class="input" name="q" value="{{ $q }}" placeholder="{{ __('Поиск: кому выдан, контакт, компания') }}" style="flex:1;min-width:220px">
  <select class="input" name="company" style="width:auto"><option value="">{{ __('Все компании') }}</option>@foreach ($companies as $w)<option value="{{ $w->id }}" @selected($cid === $w->id)>{{ $w->name }}</option>@endforeach</select>
  <button class="btn btn-p">{!! ic('search') !!}{{ __('Найти') }}</button>
</form>

<div class="card mt" id="keys">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Выданные ключи') }}</h3><button class="btn btn-s" data-open="dlg-key" data-fill='{"id":"","expires":"0"}'>{!! ic('plus') !!}{{ __('Выдать ключ') }}</button></div>
  @if ($tokens->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Кому выдан') }}</th><th>{{ __('Компания') }}</th><th>{{ __('Роль и объекты') }}</th><th>{{ __('Что может') }}</th><th>{{ __('Пользовались') }}</th><th>{{ __('Срок') }}</th><th></th></tr></thead><tbody>
  @foreach ($tokens as $t)
    @php $off = ! $t->isActive(); $sc = $t->effectiveScopes(); @endphp
    <tr class="{{ $off ? 'api-off' : '' }}">
      <td><b>{{ $t->name }}</b>@if ($t->contact)<br><small class="muted">{{ $t->contact }}</small>@endif @if ($t->purpose)<br><small class="muted">{{ \Illuminate\Support\Str::limit($t->purpose, 80) }}</small>@endif
        <br><small class="muted"><span class="code">{{ $t->hint }}</span> · {{ $t->user?->name ?? '—' }} · {{ $t->created_at?->format('d.m.Y') }}</small></td>
      <td>@if ($t->workspace)<a href="/admin/companies/{{ $t->workspace_id }}">{{ $t->workspace->name }}</a>@else—@endif</td>
      <td><b>{{ $t->roleName() }}</b>@if ($t->read_only) <span class="badge plain b-info">{{ __('только чтение') }}</span>@endif<br>
        <small class="muted">{{ $t->allowedObjects() ? implode(', ', array_intersect_key($names[$t->workspace_id] ?? [], array_flip($t->allowedObjects()))) : __('все объекты роли') }}</small></td>
      <td><div class="api-tags">@foreach (Scopes::summary($sc) as $m => $a)<span class="badge plain {{ $a === 'write' ? 'b-warn' : 'b-info' }}">{{ __(Scopes::MODULES[$m][0]) }}{{ $a === 'write' ? ' ✎' : '' }}</span>@endforeach</div></td>
      <td class="nowrap">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : __('ещё нет') }}@if ($t->last_ip)<br><small class="muted">IP {{ $t->last_ip }}</small>@endif</td>
      <td class="nowrap">@if ($t->revoked_at)<span class="badge b-bad">{{ __('отозван') }}</span>@elseif ($t->expires_at && $t->expires_at->isPast())<span class="badge b-bad">{{ __('срок истёк') }}</span>@elseif ($t->expires_at){{ __('до') }} {{ $t->expires_at->format('d.m.Y') }}@else<span class="badge b-ok">{{ __('бессрочный') }}</span>@endif</td>
      <td class="n">@unless ($off)<div class="flex api-act" style="justify-content:flex-end">
        <button type="button" class="btn btn-s" title="{{ __('Вебхук') }}" data-open="dlg-hook" data-fill="{{ json_encode(['id' => '', 'api_token_id' => $t->id, 'url' => '']) }}" data-allowed="{{ json_encode(Webhooks::eventsFor($sc)) }}" data-key="{{ $t->name }}">{!! ic('link') !!}</button>
        <button type="button" class="btn btn-s" title="{{ __('Изменить') }}" data-open="dlg-key" data-fill="{{ json_encode(['id' => $t->id, 'workspace_id' => $t->workspace_id, 'name' => $t->name, 'contact' => $t->contact, 'purpose' => $t->purpose, 'role' => $t->role, 'read_only' => (bool) $t->read_only, 'expires' => 'keep']) }}" data-objects="{{ json_encode($t->allowedObjects() ?? []) }}">{!! ic('pencil') !!}</button>
        <form method="post" action="/admin/api/tokens/{{ $t->id }}/revoke" data-confirm="{{ __('Отозвать ключ «:n»? Программы, которые им пользуются, перестанут получать данные.', ['n' => $t->name]) }}">@csrf<button class="btn btn-s btn-d" title="{{ __('Отозвать') }}">{!! ic('ban') !!}</button></form>
      </div>@endunless</td>
    </tr>
  @endforeach
  </tbody></table></div>
  <p class="muted" style="font-size:13px;margin-bottom:0">{{ __('Права ключа берутся из роли в компании («Настройки → Права ролей на разделы»): компания поменяет права роли — изменится и ключ.') }} ✎ — {{ __('чтение и запись') }}.</p>
  @else
  <div class="empty">{!! ic('key-round') !!}<div>{{ __('Ключей пока нет. Нажмите «Выдать ключ»: выберите компанию, роль и, если нужно, объекты.') }}</div></div>
  @endif
</div>

<div class="card mt" id="hooks">
  <div class="card-h"><h3>{!! ic('link') !!}{{ __('Вебхуки ключей') }}</h3></div>
  <p class="muted" style="margin-top:0">{{ __('Вебхук добавляется к ключу (кнопка со скрепкой в строке ключа). Уходят только события разделов, которые видит роль ключа, и только по его объектам. Отозвали ключ — события больше не уходят.') }}</p>
  @forelse ($hooks as $h)
    <div class="api-hook {{ $h->is_active && $h->token?->isActive() ? '' : 'api-off' }}" id="hook-{{ $h->id }}">
      <div class="api-hook-h">
        <div class="api-hook-t"><b class="api-url">{{ $h->url }}</b>
          <div><small class="muted">{{ __('Ключ') }}: <b>{{ $h->token?->name ?? '—' }}</b> · {{ $h->workspace?->name }}</small>
          @if (! $h->is_active)<span class="badge b-bad">{{ $h->fails >= Webhooks::MAX_FAILS ? __('выключен: адрес не отвечает') : __('выключен') }}</span>
          @elseif ($h->token && ! $h->token->isActive())<span class="badge b-bad">{{ __('ключ отозван') }}</span>
          @elseif ($h->last_at)<span class="badge {{ $h->fails ? 'b-warn' : 'b-ok' }}">{{ $h->fails ? __('ошибок подряд: :n', ['n' => $h->fails]) : __('работает') }}</span>
          @else<span class="badge">{{ __('ещё не отправляли') }}</span>@endif
          @if ($h->last_at)<small class="muted">{{ __('последняя отправка') }} {{ $h->last_at->diffForHumans() }}@if ($h->last_status) · HTTP {{ $h->last_status }}@endif</small>@endif</div>
        </div>
        <div class="flex api-act">
          <form method="post" action="/admin/api/hooks/{{ $h->id }}/test">@csrf<button class="btn btn-s">{!! ic('send') !!}{{ __('Проверить') }}</button></form>
          @if ($h->token)<button type="button" class="btn btn-s" data-open="dlg-hook" data-fill="{{ json_encode(['id' => $h->id, 'api_token_id' => $h->api_token_id, 'url' => $h->url]) }}" data-events="{{ json_encode(array_values((array) $h->events)) }}" data-allowed="{{ json_encode(Webhooks::eventsFor($h->token->effectiveScopes())) }}" data-key="{{ $h->token->name }}">{!! ic('pencil') !!}{{ __('Изменить') }}</button>@endif
          <form method="post" action="/admin/api/hooks/{{ $h->id }}/toggle">@csrf<button class="btn btn-s">{!! ic($h->is_active ? 'ban' : 'circle-play') !!}{{ $h->is_active ? __('Выключить') : __('Включить') }}</button></form>
          <form method="post" action="/admin/api/hooks/{{ $h->id }}" data-confirm="{{ __('Удалить вебхук? Программа перестанет получать события.') }}">@csrf @method('DELETE')<button class="btn btn-s btn-d" title="{{ __('Удалить') }}">{!! ic('trash-2') !!}</button></form>
        </div>
      </div>
      <div class="api-tags">@foreach ((array) $h->events as $e)<span class="badge plain b-brand" title="{{ $e }}">{{ __($EV[$e][0] ?? $e) }}</span>@endforeach</div>
      @if ($h->last_error && (! $h->is_active || $h->fails))<p class="api-err">{!! ic('circle-alert', '', 15) !!} {{ $h->last_error }}</p>@endif
      <details class="api-sec">
        <summary>{!! ic('lock', '', 15) !!} {{ __('Секрет подписи') }}</summary>
        <div class="api-secret"><code class="code">{{ $h->secret }}</code><button type="button" class="btn btn-s" data-copy="{{ $h->secret }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button>
          <form method="post" action="/admin/api/hooks/{{ $h->id }}/secret" data-confirm="{{ __('Создать новый секрет? Старый перестанет подходить — замените его в программе-получателе.') }}">@csrf<button class="btn btn-s">{!! ic('refresh-cw') !!}{{ __('Новый секрет') }}</button></form></div>
        <small class="muted">{{ __('Передайте секрет получателю: им он проверяет подпись X-Oftob-Signature (пример — в документации).') }}</small>
      </details>
      @if ($ds = $deliveries[$h->id] ?? null)
      <details class="api-sec" @if ($openHook === $h->id) open @endif>
        <summary>{!! ic('history', '', 15) !!} {{ __('Последние отправки') }} ({{ $ds->count() }})</summary>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Когда') }}</th><th>{{ __('Событие') }}</th><th>{{ __('Результат') }}</th><th class="n">{{ __('Попыток') }}</th><th></th></tr></thead><tbody>
        @foreach ($ds->take(15) as $d)
          <tr><td class="nowrap">{{ $d->created_at?->format('d.m H:i:s') }}</td><td><span class="code">{{ $d->event }}</span></td>
            <td>@if ($d->ok)<span class="badge b-ok">HTTP {{ $d->status }} · {{ $d->ms }} {{ __('мс') }}</span>@elseif ($d->attempts)<span class="badge b-bad">{{ $d->status ? 'HTTP '.$d->status : __('ошибка') }}</span> <small class="muted">{{ \Illuminate\Support\Str::limit((string) $d->error, 90) }}</small>@else<span class="badge">{{ __('в очереди') }}</span>@endif</td>
            <td class="n">{{ $d->attempts }}</td>
            <td class="n">@if (! $d->ok && $d->attempts)<form method="post" action="/admin/api/deliveries/{{ $d->id }}/retry">@csrf<button class="btn btn-s">{!! ic('refresh-cw') !!}{{ __('Повторить') }}</button></form>@endif</td></tr>
        @endforeach
        </tbody></table></div>
      </details>
      @endif
    </div>
  @empty
    <div class="empty">{!! ic('link') !!}<div>{{ __('Вебхуков пока нет.') }}</div></div>
  @endforelse
</div>

@if ($fails->count())
<div class="card mt">
  <div class="card-h"><h3>{!! ic('circle-alert') !!}{{ __('Не доставлено') }}</h3></div>
  <div class="tbl-wrap"><table class="tbl"><tbody>
  @foreach ($fails as $d)
    <tr><td class="nowrap">{{ $d->created_at?->format('d.m H:i') }}</td><td>{{ $d->webhook?->workspace?->name }}</td><td><span class="code">{{ $d->event }}</span></td><td class="api-url"><small>{{ $d->webhook?->url }}</small></td><td><small class="muted">{{ \Illuminate\Support\Str::limit((string) ($d->error ?: 'HTTP '.$d->status), 90) }}</small></td></tr>
  @endforeach
  </tbody></table></div>
</div>
@endif

{{-- выдать / изменить ключ --}}
<dialog class="modal wide" id="dlg-key"><form method="post" action="/admin/api/tokens">@csrf<input type="hidden" name="id" value="{{ old('id') }}">
  <div class="modal-h"><h3>{!! ic('key-round') !!}<span data-key-title>{{ __('Выдать ключ API') }}</span></h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Компания — чьи данные') }}</span><select name="workspace_id" required>@foreach ($companies as $w)<option value="{{ $w->id }}" @selected((int) old('workspace_id', $cid) === $w->id)>{{ $w->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Кому выдаём') }}</span><input name="name" maxlength="80" required value="{{ old('name') }}" placeholder="{{ __('Например: Сайт Navruz, amoCRM, разработчик Akmal') }}"></label>
    <label class="field"><span>{{ __('Контакт получателя') }}</span><input name="contact" maxlength="160" value="{{ old('contact') }}" placeholder="+998… / email"></label>
    <label class="field full"><span>{{ __('Зачем') }}</span><input name="purpose" maxlength="300" value="{{ old('purpose') }}" placeholder="{{ __('Например: показывать свободные квартиры на сайте и принимать заявки') }}"></label>
    <label class="field"><span>{{ __('Роль в компании') }}</span><select name="role" required>@foreach ($roles as $k => $n)<option value="{{ $k }}" @selected(old('role', 'manager') === $k)>{{ __($n) }}</option>@endforeach</select><small>{{ __('Ключ может то же, что сотрудник с этой ролью') }}</small></label>
    <label class="switch" style="align-self:end"><input type="checkbox" name="read_only" value="1" @checked(old('read_only'))> {{ __('Только чтение — ничего не менять') }}</label>
    <label class="field"><span>{{ __('Срок действия') }}</span><select name="expires"><option value="keep">{{ __('Не менять') }}</option><option value="0">{{ __('Бессрочно') }}</option><option value="30">{{ __('30 дней') }}</option><option value="90">{{ __('90 дней') }}</option><option value="180">{{ __('180 дней') }}</option><option value="365">{{ __('1 год') }}</option></select></label>
    <div class="field full"><span>{{ __('Что сможет ключ') }}</span><div class="api-tags" data-preview></div></div>
    <div class="field full"><span>{{ __('Объекты') }} <small class="muted">— {{ __('не отмечено ни одного: все объекты, которые видит роль') }}</small></span><div class="api-objs" data-objects></div></div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('key-round') !!}<span data-key-btn>{{ __('Выдать ключ') }}</span></button></div>
</form></dialog>

{{-- вебхук ключа --}}
<dialog class="modal wide" id="dlg-hook"><form method="post" action="/admin/api/hooks">@csrf<input type="hidden" name="id" value="{{ old('id') }}"><input type="hidden" name="api_token_id" value="{{ old('api_token_id') }}">
  <div class="modal-h"><h3>{!! ic('link') !!}{{ __('Вебхук ключа') }} <span class="muted" data-hook-key></span></h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Адрес, куда отправлять события') }}</span><input name="url" type="url" maxlength="500" required value="{{ old('url') }}" placeholder="https://hook.eu1.make.com/…"><small>{{ __('Make, Zapier, n8n, Google Apps Script или ваш сервер. Только https://') }}</small></label>
    <div class="field full"><span>{{ __('О чём сообщать') }} <small class="muted">— {{ __('серые недоступны роли ключа') }}</small></span>
      <div class="api-events">
        @foreach ($EV as $e => [$label])
          <label class="check"><input type="checkbox" name="events[]" value="{{ $e }}" @checked(in_array($e, (array) old('events', []), true))><span>{{ __($label) }}<br><small class="code">{{ $e }}</small></span></label>
        @endforeach
      </div>
    </div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@endsection

@push('scripts')
<script>
(() => {
  const OBJ = @json($objectsOf), ROLES = @json($rolesOf), LABEL = @json(collect(Scopes::MODULES)->map(fn ($m) => __($m[0])));
  const T = { read: @json(__('чтение')), write: @json(__('чтение и запись')), none: @json(__('ничего, кроме объектов')), add: @json(__('Выдать ключ')), edit: @json(__('Изменить ключ')), save: @json(__('Сохранить')) };
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]'); if (!b) return;
    navigator.clipboard?.writeText(b.dataset.copy).then(() => { const s = b.querySelector('span'); if (s) { const x = s.textContent; s.textContent = '✓'; setTimeout(() => s.textContent = x, 1500); } });
  });
  // ключ: объекты выбранной компании и что сможет роль (с учётом «только чтение»)
  const kd = document.getElementById('dlg-key'), kf = kd.querySelector('form');
  const ws = kf.elements.workspace_id, role = kf.elements.role, ro = kf.elements.read_only;
  let picked = @json((array) old('objects', []));
  const drawObjects = () => {
    const list = OBJ[ws.value] || [];
    kd.querySelector('[data-objects]').innerHTML = list.map(o => `<label class="check"><input type="checkbox" name="objects[]" value="${o.id}" ${picked.includes(o.id) ? 'checked' : ''}> ${o.name.replace(/</g, '&lt;')}</label>`).join('') || '<span class="muted">—</span>';
  };
  const drawPreview = () => {
    let sc = ((ROLES[ws.value] || {})[role.value] || []);
    if (ro.checked) sc = sc.filter(s => s.endsWith(':read'));
    const mods = {}; sc.forEach(s => { const [m, a] = s.split(':'); if (!mods[m] || a === 'write') mods[m] = a; });
    kd.querySelector('[data-preview]').innerHTML = Object.keys(LABEL).filter(m => mods[m]).map(m => `<span class="badge plain ${mods[m] === 'write' ? 'b-warn' : 'b-info'}">${LABEL[m]} — ${T[mods[m]]}</span>`).join('') || `<span class="muted">${T.none}</span>`;
  };
  ws.addEventListener('change', () => { picked = []; drawObjects(); drawPreview(); });
  role.addEventListener('change', drawPreview); ro.addEventListener('change', drawPreview);
  // окна открываются общим обработчиком (data-open, data-fill) — здесь дорисовываем объекты, права, события
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-open]'); if (!o) return;
    if (o.dataset.open === 'dlg-key') {
      const edit = !!JSON.parse(o.dataset.fill || '{}').id;
      picked = o.dataset.objects ? JSON.parse(o.dataset.objects) : [];
      ws.disabled = edit; kf.elements.expires.querySelector('[value=keep]').hidden = !edit;
      kd.querySelector('[data-key-title]').textContent = edit ? T.edit : T.add; kd.querySelector('[data-key-btn]').textContent = edit ? T.save : T.add;
      drawObjects(); drawPreview();
    }
    if (o.dataset.open === 'dlg-hook') {
      const allowed = JSON.parse(o.dataset.allowed || '[]'), ev = o.dataset.events ? JSON.parse(o.dataset.events) : allowed.filter(x => ['lead.created', 'apartment.updated', 'apartment.paid', 'task.created'].includes(x));
      document.querySelector('[data-hook-key]').textContent = o.dataset.key ? '· ' + o.dataset.key : '';
      document.querySelectorAll('#dlg-hook [name="events[]"]').forEach(c => { c.disabled = !allowed.includes(c.value); c.checked = !c.disabled && ev.includes(c.value); c.closest('label').style.opacity = c.disabled ? .45 : 1; });
    }
  });
  drawObjects(); drawPreview();
  @if (session('open'))document.getElementById(@json(session('open')))?.showModal();@endif
  @if ($openHook)document.getElementById('hook-{{ (int) $openHook }}')?.scrollIntoView({ block: 'center' });@endif
})();
</script>
@endpush
