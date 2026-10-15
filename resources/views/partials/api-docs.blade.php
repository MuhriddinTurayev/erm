{{-- Документация API: в кабинете компании, у администратора платформы и на открытой странице /developers.
     Нужны: $base, $endpoints; необязательно: $sampleApt (пример квартиры), $company (название для примера), $leadKey --}}
@php
  $EV = \App\Services\Api\Webhooks::EVENTS;
  $mods = ['common' => [__('Общее'), 'plug']] + array_map(fn ($m) => [__($m[0]), $m[1]], \App\Services\Api\Scopes::MODULES);
  $methodCls = ['GET' => 'b-info', 'POST' => 'b-ok', 'PATCH' => 'b-warn', 'DELETE' => 'b-bad'];
  $groups = [];
  foreach ($endpoints as $e) { $groups[\App\Services\Api\Spec::module($e[2])][] = $e; }
  $sampleApt = $sampleApt ?? null;
  $pathVals = ['{object}' => $sampleApt['object_id'] ?? 'object-id', '{apartment}' => $sampleApt['id'] ?? '1-2-0', '{id}' => '1'];
  $bodyVals = ['name' => 'Akmal Karimov', 'phone' => '+998901234567', 'source' => 'Сайт', 'rooms' => 2, 'message' => 'Перезвоните после 18:00',
    'status' => 'booked', 'buyer_name' => 'Akmal Karimov', 'buyer_phone' => '+998901234567', 'title' => 'Проверить арматуру на 3-м этаже',
    'due_date' => now()->addDays(3)->toDateString(), 'priority' => 'high', 'kind' => 'supplier', 'inn' => '123456789', 'progress' => 50];
  $curl = function (array $e) use ($base, $pathVals, $bodyVals) {
      [$m, $path, , , $params] = $e;
      $out = 'curl'.($m !== 'GET' ? ' -X '.$m : '').' "'.$base.strtr($path, $pathVals).'" \\'."\n".'  -H "Authorization: Bearer oft_…"';
      $body = [];
      foreach ($params as [$n, $in]) { if ($in === 'body' && array_key_exists($n, $bodyVals)) { $body[$n] = $bodyVals[$n]; } }
      if ($body) { $out .= ' \\'."\n".'  -H "Content-Type: application/json" \\'."\n".'  -d \''.json_encode($body, JSON_UNESCAPED_UNICODE).'\''; }
      return $out;
  };
  $hookBody = json_encode(['id' => '4f0c2a8e-6b1d-4c5e-9a57-0d1f3e2b7c90', 'event' => 'apartment.updated', 'created_at' => now()->utc()->format('Y-m-d\TH:i:sP'),
    'company' => ['id' => 1, 'name' => $company ?? 'Navruz Qurilish'], 'data' => ($sampleApt ?? ['id' => '1-2-0', 'object_id' => 'object-id', 'number' => '12', 'status' => 'booked', 'price' => 650000000]) + ['previous_status' => 'free']],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
@endphp
<div class="api-doc">
  <h4>1. {{ __('Вход по ключу') }}</h4>
  <p>{{ __('Ключ выдаёт администратор платформы — для одной компании и с одной ролью. Ключ видит и делает ровно то, что сотрудник компании с этой ролью, и только на его объектах.') }}</p>
  <p>{{ __('Каждый запрос — с ключом в заголовке. Ответ всегда в JSON, даты — ISO 8601, суммы — числа в валюте компании.') }}</p>
  <pre class="api-pre">GET {{ $base }}/me
Authorization: Bearer oft_…
{{ __('или') }}  X-Api-Key: oft_…</pre>
  <ul class="api-list">
    <li>{{ __('GET /me покажет компанию, роль ключа и его права (scopes) — начните с него.') }}</li>
    <li>{{ __('Лимит — :n запросов в минуту на ключ (заголовки X-RateLimit-Limit и X-RateLimit-Remaining).', ['n' => \App\Http\Middleware\ApiAuth::PER_MINUTE]) }}</li>
    <li>{{ __('Язык названий (склады, кассы, статьи): ?lang=uz | ru | tg или заголовок Accept-Language.') }}</li>
    <li>{{ __('Каждое изменение через API записывается в «Историю изменений» с названием ключа.') }}</li>
    <li>{{ __('Повтор без двойного действия: передайте заголовок Idempotency-Key (уникальная строка на каждое действие) — повтор с тем же ключом вернёт первый ответ, а не создаст запись ещё раз.') }}</li>
  </ul>

  <h4>2. {{ __('Ответы и ошибки') }}</h4>
  <pre class="api-pre">{"data": { … }}                                     — {{ __('одна запись') }}
{"data": [ … ], "meta": {"page": 1, "per_page": 50, "total": 134, "last_page": 3}}   — {{ __('список') }}
{"error": {"code": "validation_failed", "message": "…", "fields": {"phone": ["…"]}}}</pre>
  <div class="tbl-wrap"><table class="tbl api-codes"><tbody>
    <tr><td><b>401</b></td><td>{{ __('нет ключа, неверный, отозван или срок истёк') }}</td></tr>
    <tr><td><b>402</b></td><td>{{ __('подписка компании не активна') }}</td></tr>
    <tr><td><b>403</b></td><td>{{ __('роль ключа не видит этот раздел (insufficient_scope) или API выключен на платформе (api_disabled)') }}</td></tr>
    <tr><td><b>404</b></td><td>{{ __('запись не найдена или это не объект ключа') }}</td></tr>
    <tr><td><b>409</b></td><td>{{ __('конфликт: дубль контрагента по ИНН, объект ещё не в продаже') }}</td></tr>
    <tr><td><b>422</b></td><td>{{ __('ошибка в данных — поле fields подскажет, в каком') }}</td></tr>
    <tr><td><b>429</b></td><td>{{ __('слишком много запросов — подождите Retry-After секунд') }}</td></tr>
  </tbody></table></div>
  <p class="muted">{{ __('Списки — по страницам: ?page=2&per_page=100 (до 200). Для синхронизации: updated_since у задач, контрагентов, товаров, заявок на оплату; since_id у заявок клиентов.') }}</p>

  <h4>3. {{ __('Методы') }}</h4>
  <p class="muted">{{ __('Справа у метода — право, которое для него нужно. Есть ли оно у ключа, решает его роль.') }}</p>
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
  <p>{{ __('Вебхук подключает администратор платформы к ключу. POST на ваш адрес, тело — JSON; приходят только события разделов, которые видит роль ключа. Ответьте кодом 2xx в течение 10 секунд; иначе повторим через 30 с, 2 мин, 10 мин, 30 мин и 1 час. Одно событие может прийти дважды — проверяйте поле id.') }}</p>
  <div class="tbl-wrap"><table class="tbl api-codes"><tbody>
    @foreach ($EV as $e => [$label])<tr><td><code class="code">{{ $e }}</code></td><td>{{ __($label) }}</td><td><span class="api-scope">{{ \App\Services\Api\Webhooks::SCOPE[$e] }}</span></td></tr>@endforeach
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

  @if (! empty($leadKey))
  <h4>5. {{ __('Простая форма на сайте (без программиста)') }}</h4>
  <p>{{ __('Для Tilda, WordPress и конструкторов сайтов есть короткий адрес только для заявок — его можно вставить прямо в настройки формы:') }}</p>
  <div class="api-secret"><code class="code">{{ url('/api/lead') }}?key={{ $leadKey }}</code><button type="button" class="btn btn-s" data-copy="{{ url('/api/lead') }}?key={{ $leadKey }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button></div>
  <p class="muted">{{ __('Поля формы: name, phone, message (необязательно — rooms, budget, source). Ключ этого адреса меняется в программе: «Заявки → Подключения».') }}</p>
  @endif
</div>
@once
<script>
// документация API: вкладки с кодом и кнопки «Скопировать»
document.addEventListener('click', e => {
  const t = e.target.closest('[data-tabs] [data-t]');
  if (t) { const w = t.closest('[data-tabs]'); w.querySelectorAll('[data-t]').forEach(x => x.classList.toggle('on', x === t)); w.querySelectorAll('[data-p]').forEach(p => p.hidden = p.dataset.p !== t.dataset.t); return; }
  const b = e.target.closest('[data-copy]'); if (!b) return;
  navigator.clipboard?.writeText(b.dataset.copy).then(() => { const s = b.querySelector('span'); if (s) { const x = s.textContent; s.textContent = '✓'; setTimeout(() => s.textContent = x, 1500); } });
});
</script>
@endonce
