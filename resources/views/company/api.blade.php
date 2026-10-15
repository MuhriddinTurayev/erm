@extends('layouts.panel')
@section('title', __('API и интеграции'))
@section('subtitle', __('Сайт, другая CRM, 1С, Make или Zapier: ключ API — чтобы программа читала и меняла данные, вебхук — чтобы она сразу узнавала о событиях.'))
@use('App\Services\Api\Scopes')
@use('App\Services\Api\Webhooks')
@php
  $EV = Webhooks::EVENTS;
  $mods = ['common' => [__('Общее'), 'plug']] + array_map(fn ($m) => [__($m[0]), $m[1]], Scopes::MODULES);
  $methodCls = ['GET' => 'b-info', 'POST' => 'b-ok', 'PATCH' => 'b-warn', 'DELETE' => 'b-bad'];
  $newKey = session('apiKey');
  $key = $newKey['key'] ?? 'oft_…';
  // примеры для документации — из данных компании
  $proj = collect($ws->projects())->first(fn ($p) => is_array($p) && ! empty($p['id']));
  $apt = $proj ? (\App\Services\Apartments::list($proj)[0] ?? null) : null;
  $sampleApt = $apt ? \App\Services\Api\Present::apartment($apt, (string) $proj['id'], true) : null;
  $pathVals = ['{object}' => $proj['id'] ?? 'object-id', '{apartment}' => $apt['id'] ?? '1-2-0', '{id}' => '1'];
  $bodyVals = ['name' => 'Akmal Karimov', 'phone' => '+998901234567', 'source' => 'Сайт', 'rooms' => 2, 'message' => 'Перезвоните после 18:00',
    'status' => 'booked', 'buyer_name' => 'Akmal Karimov', 'buyer_phone' => '+998901234567', 'title' => 'Проверить арматуру на 3-м этаже',
    'due_date' => now()->addDays(3)->toDateString(), 'priority' => 'high', 'kind' => 'supplier', 'inn' => '123456789', 'progress' => 50];
  $groups = [];
  foreach ($endpoints as $e) { $groups[\App\Services\Api\Spec::module($e[2])][] = $e; }
  $hookBody = json_encode(['id' => '4f0c2a8e-6b1d-4c5e-9a57-0d1f3e2b7c90', 'event' => 'apartment.updated', 'created_at' => now()->utc()->format('Y-m-d\TH:i:sP'),
    'company' => ['id' => $ws->id, 'name' => $ws->name], 'data' => ($sampleApt ?? ['id' => '1-2-0', 'status' => 'booked']) + ['previous_status' => 'free']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $codePhp = <<<'CODE'
$body = file_get_contents('php://input');
$ts   = $_SERVER['HTTP_X_OFTOB_TIMESTAMP'] ?? '';
$sig  = $_SERVER['HTTP_X_OFTOB_SIGNATURE'] ?? '';
$good = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, 'whsec_…');
if (!hash_equals($good, $sig) || abs(time() - (int) $ts) > 300) {
    http_response_code(401);
    exit;
}
$event = json_decode($body, true);   // $event['event'], $event['data']
http_response_code(200);
CODE;
  $codeNode = <<<'CODE'
const crypto = require('crypto');
app.post('/oftob', express.raw({ type: 'application/json' }), (req, res) => {
  const ts = req.get('X-Oftob-Timestamp'), sig = req.get('X-Oftob-Signature') || '';
  const good = 'sha256=' + crypto.createHmac('sha256', process.env.OFTOB_SECRET)
    .update(ts + '.' + req.body).digest('hex');
  const ok = sig.length === good.length && crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(good));
  if (!ok || Math.abs(Date.now() / 1000 - Number(ts)) > 300) return res.sendStatus(401);
  const event = JSON.parse(req.body);   // event.event, event.data
  res.sendStatus(200);
});
CODE;
  $codePy = <<<'CODE'
import hmac, hashlib, time

def verify(body: bytes, ts: str, sig: str, secret: str) -> bool:
    good = 'sha256=' + hmac.new(secret.encode(), ts.encode() + b'.' + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(good, sig) and abs(time.time() - int(ts)) < 300
CODE;
  $curl = function (array $e) use ($base, $key, $pathVals, $bodyVals) {
      [$m, $path, , , $params] = $e;
      $url = $base.strtr($path, $pathVals);
      $out = 'curl'.($m !== 'GET' ? ' -X '.$m : '').' "'.$url.'" \\'."\n".'  -H "Authorization: Bearer '.$key.'"';
      $body = [];
      foreach ($params as [$n, $in]) { if ($in === 'body' && array_key_exists($n, $bodyVals)) { $body[$n] = $bodyVals[$n]; } }
      if ($body) { $out .= ' \\'."\n".'  -H "Content-Type: application/json" \\'."\n".'  -d \''.json_encode($body, JSON_UNESCAPED_UNICODE).'\''; }
      return $out;
  };
  $openHook = session('hook');
@endphp
@section('actions')
<button class="btn btn-p btn-s" data-open="dlg-key">{!! ic('key-round') !!}{{ __('Новый ключ') }}</button>
<button class="btn btn-s" data-open="dlg-hook" data-fill='{"id":""}'>{!! ic('link') !!}{{ __('Новый вебхук') }}</button>
<a class="btn btn-s" href="#docs">{!! ic('book-open') !!}{{ __('Документация') }}</a>
@endsection
@section('content')

@if ($newKey)
<div class="card api-new">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Ключ «:n» создан', ['n' => $newKey['name']]) }}</h3><span class="badge b-warn">{{ __('показывается один раз') }}</span></div>
  <p class="muted" style="margin-top:0">{{ __('Скопируйте ключ и сохраните его в программе, которую подключаете. Мы храним только отпечаток ключа — посмотреть его ещё раз будет нельзя, можно только выпустить новый.') }}</p>
  <div class="api-secret"><code class="code">{{ $newKey['key'] }}</code><button type="button" class="btn btn-p btn-s" data-copy="{{ $newKey['key'] }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button></div>
  <p class="muted mt" style="margin-bottom:6px">{{ __('Проверить ключ:') }}</p>
  <pre class="api-pre">curl "{{ $base }}/me" \
  -H "Authorization: Bearer {{ $newKey['key'] }}"</pre>
</div>
@endif

<div class="card api-steps">
  <div class="api-step"><span>1</span><div><b>{{ __('Выпустите ключ') }}</b><small>{{ __('Отметьте, что программе можно: только читать или ещё и менять, какие разделы.') }}</small></div></div>
  <div class="api-step"><span>2</span><div><b>{{ __('Передайте его разработчику') }}</b><small>{{ __('Или вставьте в Make, Zapier, n8n — как заголовок Authorization: Bearer.') }}</small></div></div>
  <div class="api-step"><span>3</span><div><b>{{ __('Добавьте вебхук') }}</b><small>{{ __('Чтобы программа сразу узнавала о заявках, продажах, оплатах — без постоянных запросов.') }}</small></div></div>
  <div class="api-base"><span class="muted">{{ __('Адрес API') }}</span><code class="code">{{ $base }}</code><button type="button" class="btn btn-s" data-copy="{{ $base }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button></div>
</div>

<div class="card mt" id="keys">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Ключи API') }}</h3><button class="btn btn-s" data-open="dlg-key">{!! ic('plus') !!}{{ __('Новый ключ') }}</button></div>
  @if ($tokens->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Ключ') }}</th><th>{{ __('Права') }}</th><th>{{ __('Пользовались') }}</th><th>{{ __('Срок') }}</th><th></th></tr></thead><tbody>
  @foreach ($tokens as $t)
    @php $off = ! $t->isActive(); @endphp
    <tr class="{{ $off ? 'api-off' : '' }}">
      <td><b>{{ $t->name }}</b><br><small class="muted"><span class="code">{{ $t->hint }}</span> · {{ $t->user?->name ?? '—' }} · {{ $t->created_at?->format('d.m.Y') }}</small></td>
      <td><div class="api-tags">@foreach ((array) $t->scopes as $s)<span class="badge plain {{ str_ends_with($s, ':write') ? 'b-warn' : 'b-info' }}">{{ Scopes::label($s) }}</span>@endforeach</div></td>
      <td class="nowrap">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : __('ещё нет') }}@if ($t->last_ip)<br><small class="muted">IP {{ $t->last_ip }}</small>@endif</td>
      <td class="nowrap">@if ($t->revoked_at)<span class="badge b-bad">{{ __('отозван') }}</span>@elseif ($t->expires_at && $t->expires_at->isPast())<span class="badge b-bad">{{ __('срок истёк') }}</span>@elseif ($t->expires_at){{ __('до') }} {{ $t->expires_at->format('d.m.Y') }}@else<span class="badge b-ok">{{ __('бессрочный') }}</span>@endif</td>
      <td class="n">@unless ($off)<form method="post" action="/company/api/tokens/{{ $t->id }}/revoke" data-confirm="{{ __('Отозвать ключ «:n»? Программы, которые им пользуются, перестанут получать данные.', ['n' => $t->name]) }}">@csrf<button class="btn btn-s btn-d">{!! ic('ban') !!}{{ __('Отозвать') }}</button></form>@endunless</td>
    </tr>
  @endforeach
  </tbody></table></div>
  @else
  <div class="empty">{!! ic('key-round') !!}<div>{{ __('Ключей пока нет. Выпустите первый — например, для сайта: он покажет свободные квартиры и будет присылать заявки.') }}</div></div>
  @endif
</div>

<div class="card mt" id="hooks">
  <div class="card-h"><h3>{!! ic('link') !!}{{ __('Вебхуки') }}</h3><button class="btn btn-s" data-open="dlg-hook" data-fill='{"id":""}'>{!! ic('plus') !!}{{ __('Новый вебхук') }}</button></div>
  <p class="muted" style="margin-top:0">{{ __('Вебхук — адрес вашей программы. Когда происходит событие (новая заявка, бронь или продажа квартиры, оплата), мы сразу отправляем туда POST-запрос с данными. Не ответил — повторим 5 раз с паузой до часа.') }}</p>
  @forelse ($hooks as $h)
    <div class="api-hook {{ $h->is_active ? '' : 'api-off' }}" id="hook-{{ $h->id }}">
      <div class="api-hook-h">
        <div class="api-hook-t"><b class="api-url">{{ $h->url }}</b>
          <div>@if (! $h->is_active)<span class="badge b-bad">{{ $h->fails >= Webhooks::MAX_FAILS ? __('выключен: адрес не отвечает') : __('выключен') }}</span>
          @elseif ($h->last_at)<span class="badge {{ $h->fails ? 'b-warn' : 'b-ok' }}">{{ $h->fails ? __('ошибок подряд: :n', ['n' => $h->fails]) : __('работает') }}</span>
          @else<span class="badge">{{ __('ещё не отправляли') }}</span>@endif
          @if ($h->last_at)<small class="muted">{{ __('последняя отправка') }} {{ $h->last_at->diffForHumans() }}@if ($h->last_status) · HTTP {{ $h->last_status }}@endif</small>@endif</div>
        </div>
        <div class="flex api-act">
          <form method="post" action="/company/api/hooks/{{ $h->id }}/test">@csrf<button class="btn btn-s">{!! ic('send') !!}{{ __('Проверить') }}</button></form>
          <button type="button" class="btn btn-s" data-open="dlg-hook" data-fill="{{ json_encode(['id' => $h->id, 'url' => $h->url]) }}" data-events="{{ json_encode(array_values((array) $h->events)) }}">{!! ic('pencil') !!}{{ __('Изменить') }}</button>
          <form method="post" action="/company/api/hooks/{{ $h->id }}/toggle">@csrf<button class="btn btn-s">{!! ic($h->is_active ? 'ban' : 'circle-play') !!}{{ $h->is_active ? __('Выключить') : __('Включить') }}</button></form>
          <form method="post" action="/company/api/hooks/{{ $h->id }}" data-confirm="{{ __('Удалить вебхук? Программа перестанет получать события.') }}">@csrf @method('DELETE')<button class="btn btn-s btn-d" title="{{ __('Удалить') }}">{!! ic('trash-2') !!}</button></form>
        </div>
      </div>
      <div class="api-tags">@foreach ((array) $h->events as $e)<span class="badge plain b-brand" title="{{ $e }}">{{ __($EV[$e][0] ?? $e) }}</span>@endforeach</div>
      @if (! $h->is_active || $h->fails)@if ($h->last_error)<p class="api-err">{!! ic('circle-alert', '', 15) !!} {{ $h->last_error }}</p>@endif @endif
      <details class="api-sec">
        <summary>{!! ic('lock', '', 15) !!} {{ __('Секрет подписи') }}</summary>
        <div class="api-secret"><code class="code">{{ $h->secret }}</code><button type="button" class="btn btn-s" data-copy="{{ $h->secret }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button>
          <form method="post" action="/company/api/hooks/{{ $h->id }}/secret" data-confirm="{{ __('Создать новый секрет? Старый перестанет подходить — замените его в программе-получателе.') }}">@csrf<button class="btn btn-s">{!! ic('refresh-cw') !!}{{ __('Новый секрет') }}</button></form></div>
        <small class="muted">{{ __('Им программа-получатель проверяет, что запрос пришёл от нас (заголовок X-Oftob-Signature) — пример ниже, в документации.') }}</small>
      </details>
      @if ($ds = $deliveries[$h->id] ?? null)
      <details class="api-sec" @if ($openHook === $h->id) open @endif>
        <summary>{!! ic('history', '', 15) !!} {{ __('Последние отправки') }} ({{ $ds->count() }})</summary>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Когда') }}</th><th>{{ __('Событие') }}</th><th>{{ __('Результат') }}</th><th class="n">{{ __('Попыток') }}</th><th></th></tr></thead><tbody>
        @foreach ($ds->take(15) as $d)
          <tr><td class="nowrap">{{ $d->created_at?->format('d.m H:i:s') }}</td><td><span class="code">{{ $d->event }}</span></td>
            <td>@if ($d->ok)<span class="badge b-ok">HTTP {{ $d->status }} · {{ $d->ms }} {{ __('мс') }}</span>@elseif ($d->attempts)<span class="badge b-bad">{{ $d->status ? 'HTTP '.$d->status : __('ошибка') }}</span> <small class="muted">{{ \Illuminate\Support\Str::limit((string) $d->error, 90) }}</small>@else<span class="badge">{{ __('в очереди') }}</span>@endif</td>
            <td class="n">{{ $d->attempts }}</td>
            <td class="n">@if (! $d->ok && $d->attempts)<form method="post" action="/company/api/deliveries/{{ $d->id }}/retry">@csrf<button class="btn btn-s">{!! ic('refresh-cw') !!}{{ __('Повторить') }}</button></form>@endif</td></tr>
        @endforeach
        </tbody></table></div>
      </details>
      @endif
    </div>
  @empty
    <div class="empty">{!! ic('link') !!}<div>{{ __('Вебхуков пока нет. Добавьте адрес из Make, Zapier, n8n или своего сервера.') }}</div></div>
  @endforelse
</div>

<div class="card mt" id="docs">
  <div class="card-h"><h3>{!! ic('book-open') !!}{{ __('Документация для разработчика') }}</h3><a class="btn btn-s" href="/api/v1/openapi.json" target="_blank" rel="noopener">{!! ic('download') !!}OpenAPI · Postman</a></div>
  <div class="api-doc">
    <h4>1. {{ __('Вход по ключу') }}</h4>
    <p>{{ __('Каждый запрос — с ключом в заголовке. Ответ всегда в JSON, даты — ISO 8601, суммы — числа в валюте компании.') }}</p>
    <pre class="api-pre">Authorization: Bearer oft_…
{{ __('или') }}  X-Api-Key: oft_…</pre>
    <ul class="api-list">
      <li>{{ __('Лимит — :n запросов в минуту на ключ (заголовки X-RateLimit-Limit и X-RateLimit-Remaining).', ['n' => \App\Http\Middleware\ApiAuth::PER_MINUTE]) }}</li>
      <li>{{ __('Язык названий (склады, кассы, статьи): ?lang=uz | ru | tg или заголовок Accept-Language.') }}</li>
      <li>{{ __('Каждое изменение через API записывается в «Историю изменений» с названием ключа.') }}</li>
    </ul>

    <h4>2. {{ __('Ответы и ошибки') }}</h4>
    <pre class="api-pre">{"data": { … }}                                     — {{ __('одна запись') }}
{"data": [ … ], "meta": {"page": 1, "per_page": 50, "total": 134, "last_page": 3}}   — {{ __('список') }}
{"error": {"code": "validation_failed", "message": "…", "fields": {"phone": ["…"]}}}</pre>
    <div class="tbl-wrap"><table class="tbl api-codes"><tbody>
      <tr><td><b>401</b></td><td>{{ __('нет ключа, неверный, отозван или срок истёк') }}</td></tr>
      <tr><td><b>402</b></td><td>{{ __('подписка компании не активна') }}</td></tr>
      <tr><td><b>403</b></td><td>{{ __('у ключа нет права на этот раздел (insufficient_scope)') }}</td></tr>
      <tr><td><b>404</b></td><td>{{ __('запись не найдена') }}</td></tr>
      <tr><td><b>409</b></td><td>{{ __('конфликт: дубль контрагента по ИНН, объект ещё не в продаже') }}</td></tr>
      <tr><td><b>422</b></td><td>{{ __('ошибка в данных — поле fields подскажет, в каком') }}</td></tr>
      <tr><td><b>429</b></td><td>{{ __('слишком много запросов — подождите Retry-After секунд') }}</td></tr>
    </tbody></table></div>
    <p class="muted">{{ __('Списки — по страницам: ?page=2&per_page=100 (до 200). Для синхронизации: updated_since у задач, контрагентов, товаров, заявок на оплату; since_id у заявок клиентов.') }}</p>

    <h4>3. {{ __('Методы') }}</h4>
    @foreach ($groups as $mod => $list)
      <div class="api-mod"><b>{!! ic($mods[$mod][1] ?? 'plug', '', 16) !!} {{ $mods[$mod][0] ?? $mod }}</b></div>
      @foreach ($list as $e)
        @php [$m, $path, $scope, $sum, $params] = $e; @endphp
        <details class="api-ep">
          <summary><span class="badge plain {{ $methodCls[$m] ?? '' }}">{{ $m }}</span><code>{{ $path }}</code><span class="api-ep-s">{{ __($sum) }}</span>@if ($scope)<span class="api-scope">{{ $scope }}</span>@endif</summary>
          @if ($params)
          <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Параметр') }}</th><th>{{ __('Где') }}</th><th>{{ __('Тип') }}</th><th>{{ __('Описание') }}</th></tr></thead><tbody>
          @foreach ($params as [$pn, $in, $type, $desc, $req])
            <tr><td><code class="code">{{ $pn }}</code>@if ($req) <span class="api-req">*</span>@endif</td><td>{{ ['path' => __('в адресе'), 'query' => '?query', 'body' => 'JSON'][$in] }}</td><td>{{ $type }}</td><td>{{ __($desc) }}</td></tr>
          @endforeach
          </tbody></table></div>
          @endif
          <pre class="api-pre">{{ $curl($e) }}</pre>
        </details>
      @endforeach
    @endforeach

    <h4>4. {{ __('Вебхуки') }}</h4>
    <p>{{ __('POST на ваш адрес, тело — JSON. Ответьте кодом 2xx в течение 10 секунд; иначе повторим через 30 с, 2 мин, 10 мин, 30 мин и 1 час. Одно событие может прийти дважды — проверяйте поле id.') }}</p>
    <div class="tbl-wrap"><table class="tbl api-codes"><tbody>
      @foreach ($EV as $e => [$label])<tr><td><code class="code">{{ $e }}</code></td><td>{{ __($label) }}</td></tr>@endforeach
    </tbody></table></div>
    <pre class="api-pre">X-Oftob-Event: apartment.updated
X-Oftob-Event-Id: 4f0c2a8e-…
X-Oftob-Timestamp: {{ time() }}
X-Oftob-Signature: sha256=…   ← HMAC-SHA256({{ __('время') }} + "." + {{ __('тело') }}, {{ __('секрет') }})

{{ $hookBody }}</pre>
    <p>{{ __('Проверка подписи:') }}</p>
    <div class="api-tabs" data-tabs>
      <div class="api-tab-h"><button type="button" class="on" data-t="php">PHP</button><button type="button" data-t="node">Node.js</button><button type="button" data-t="py">Python</button></div>
      <pre class="api-pre" data-p="php">{{ $codePhp }}</pre>
      <pre class="api-pre" data-p="node" hidden>{{ $codeNode }}</pre>
      <pre class="api-pre" data-p="py" hidden>{{ $codePy }}</pre>
    </div>

    @if ($leadKey)
    <h4>5. {{ __('Простая форма на сайте (без программиста)') }}</h4>
    <p>{{ __('Для Tilda, WordPress и конструкторов сайтов есть короткий адрес только для заявок — его можно вставить прямо в настройки формы:') }}</p>
    <div class="api-secret"><code class="code">{{ url('/api/lead') }}?key={{ $leadKey }}</code><button type="button" class="btn btn-s" data-copy="{{ url('/api/lead') }}?key={{ $leadKey }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button></div>
    <p class="muted">{{ __('Поля формы: name, phone, message (необязательно — rooms, budget, source). Ключ этого адреса меняется в программе: «Заявки → Подключения».') }}</p>
    @endif
  </div>
</div>

{{-- новый ключ --}}
<dialog class="modal wide" id="dlg-key"><form method="post" action="/company/api/tokens">@csrf
  <div class="modal-h"><h3>{!! ic('key-round') !!}{{ __('Новый ключ API') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Для кого ключ') }}</span><input name="name" maxlength="80" required value="{{ old('name') }}" placeholder="{{ __('Например: Сайт компании, amoCRM, 1С') }}"></label>
    <div class="field full"><span>{{ __('Что разрешить') }}</span>
      <div class="api-presets">
        @foreach (Scopes::PRESETS as $k => [$label, $list])
          <label class="api-preset"><input type="radio" name="preset" value="{{ $k }}" @checked(old('preset', 'site') === $k)><b>{{ __($label) }}</b><small>{{ implode(' · ', array_map(fn ($s) => Scopes::label($s), $list ?? Scopes::readOnly())) }}</small></label>
        @endforeach
        <label class="api-preset"><input type="radio" name="preset" value="custom" @checked(old('preset') === 'custom')><b>{{ __('Выбрать самому') }}</b><small>{{ __('отметьте разделы в таблице') }}</small></label>
      </div>
    </div>
    <div class="full tbl-wrap"><table class="tbl api-perm"><thead><tr><th>{{ __('Раздел') }}</th><th>{{ __('Чтение') }}</th><th>{{ __('Запись') }}</th></tr></thead><tbody>
    @foreach (Scopes::MODULES as $m => [$label, $icon, $acc])
      <tr><td class="nowrap">{!! ic($icon, '', 16) !!} {{ __($label) }}</td>
        @foreach (['read', 'write'] as $a)
          <td>@isset($acc[$a])<label class="check"><input type="checkbox" name="scopes[]" value="{{ $m }}:{{ $a }}" @checked(in_array("$m:$a", (array) old('scopes', []), true))><small>{{ __($acc[$a]) }}</small></label>@else<span class="muted">—</span>@endisset</td>
        @endforeach</tr>
    @endforeach
    </tbody></table></div>
    <label class="field"><span>{{ __('Срок действия') }}</span><select name="expires"><option value="0">{{ __('Бессрочно') }}</option><option value="30">{{ __('30 дней') }}</option><option value="90">{{ __('90 дней') }}</option><option value="365">{{ __('1 год') }}</option></select></label>
    <p class="muted full" style="font-size:13px;margin:0">{{ __('Ключ открывает данные компании — передавайте его только тем, кому доверяете. Для сайта выбирайте «Сайт»: он видит квартиры и может только добавлять заявки. Ключ можно отозвать в любой момент.') }}</p>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('key-round') !!}{{ __('Выпустить ключ') }}</button></div>
</form></dialog>

{{-- вебхук --}}
<dialog class="modal wide" id="dlg-hook"><form method="post" action="/company/api/hooks">@csrf<input type="hidden" name="id" value="{{ old('id') }}">
  <div class="modal-h"><h3>{!! ic('link') !!}{{ __('Вебхук') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Адрес, куда отправлять события') }}</span><input name="url" type="url" maxlength="500" required value="{{ old('url') }}" placeholder="https://hook.eu1.make.com/…"><small>{{ __('Make, Zapier, n8n, Google Apps Script или ваш сервер. Только https://') }}</small></label>
    <div class="field full"><span>{{ __('О чём сообщать') }}</span>
      <div class="api-events">
        @foreach ($EV as $e => [$label, $mod])
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
  // копировать: ключ, секрет, адрес
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]'); if (!b) return;
    navigator.clipboard?.writeText(b.dataset.copy).then(() => { const s = b.querySelector('span'); if (s) { const t = s.textContent; s.textContent = '✓'; setTimeout(() => s.textContent = t, 1500); } });
  });
  // готовые наборы прав: галочки показывают, что войдёт в ключ; «Выбрать самому» — галочки свои
  const PRESETS = @json(collect(Scopes::PRESETS)->map(fn ($p) => $p[1] ?? Scopes::readOnly()));
  const kf = document.querySelector('#dlg-key form'), boxes = [...kf.querySelectorAll('[name="scopes[]"]')];
  const syncPreset = () => {
    const p = kf.querySelector('[name=preset]:checked')?.value || 'custom';
    boxes.forEach(b => { if (p !== 'custom') b.checked = PRESETS[p].includes(b.value) || (b.value.endsWith(':read') && b.value !== 'leads:read' && PRESETS[p].includes(b.value.replace(':read', ':write'))); b.disabled = p !== 'custom'; });
  };
  kf.addEventListener('change', e => { if (e.target.name === 'preset') syncPreset(); });
  boxes.forEach(b => b.addEventListener('change', () => { if (b.checked && b.value.endsWith(':write') && b.value !== 'leads:write') { const r = kf.querySelector(`[value="${b.value.replace(':write', ':read')}"]`); if (r) r.checked = true; } }));
  syncPreset();
  // вебхук: «Изменить» — отметить его события; новый — самые нужные
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-open="dlg-hook"]'); if (!o) return;
    const ev = o.dataset.events ? JSON.parse(o.dataset.events) : ['lead.created', 'apartment.updated', 'apartment.paid'];
    document.querySelectorAll('#dlg-hook [name="events[]"]').forEach(c => c.checked = ev.includes(c.value));
  });
  // примеры кода: вкладки
  document.querySelectorAll('[data-tabs]').forEach(w => w.addEventListener('click', e => {
    const b = e.target.closest('[data-t]'); if (!b) return;
    w.querySelectorAll('[data-t]').forEach(x => x.classList.toggle('on', x === b));
    w.querySelectorAll('[data-p]').forEach(p => p.hidden = p.dataset.p !== b.dataset.t);
  }));
  @if (session('open'))document.getElementById(@json(session('open')))?.showModal();@endif
  @if ($openHook)document.getElementById('hook-{{ (int) $openHook }}')?.scrollIntoView({ block: 'center' });@endif
})();
</script>
@endpush
