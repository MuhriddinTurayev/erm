<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\IntegrationError;
use App\Models\LeadInbox;
use App\Services\MetaGraph;
use Illuminate\Http\Request;

/** Заявки: Facebook / Instagram (формы в рекламе и Direct), Messenger, WhatsApp Business, сайт / Tilda / Make / Zapier */
class LeadController extends Controller
{
    /* ---------- общее ---------- */
    /** Заявка во «Входящие» компании (её же вызывает открытый API) */
    public static function push(int $wsId, array $it): LeadInbox
    {
        $text = mb_substr((string) ($it['text'] ?? ''), 0, 1000);
        if (empty($it['phone']) && preg_match('/(\+?\d[\d\s\-()]{7,16}\d)/u', $text, $m)) {
            $it['phone'] = preg_replace('/[^\d+]/', '', $m[1]);
        }
        return LeadInbox::create([
            'workspace_id' => $wsId,
            'ch' => $it['ch'],
            'kind' => $it['kind'] ?? 'msg',
            'key' => mb_substr((string) $it['key'], 0, 120),
            'name' => mb_substr((string) ($it['name'] ?? ''), 0, 120) ?: null,
            'phone' => mb_substr((string) ($it['phone'] ?? ''), 0, 40) ?: null,
            'user' => mb_substr((string) ($it['user'] ?? ''), 0, 80) ?: null,
            'src' => mb_substr((string) ($it['src'] ?? ''), 0, 40) ?: null,
            'rooms' => mb_substr((string) ($it['rooms'] ?? ''), 0, 2) ?: null,
            'budget' => isset($it['budget']) && is_numeric($it['budget']) ? (int) $it['budget'] : null,
            'text' => $text,
        ]);
    }

    private static function fromFields(array $fd): array
    {
        $f = [];
        foreach ($fd as $x) {
            $f[$x['name'] ?? ''] = implode(', ', (array) ($x['values'] ?? []));
        }
        $name = $f['full_name'] ?? trim(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''));
        $phone = $f['phone_number'] ?? ($f['phone'] ?? '');
        $rest = [];
        foreach ($f as $k => $v) {
            if (! in_array($k, ['full_name', 'first_name', 'last_name', 'phone_number', 'phone'], true)) {
                $rest[] = str_replace('_', ' ', $k).': '.$v;
            }
        }

        return ['name' => $name, 'phone' => $phone, 'text' => implode('; ', $rest)];
    }

    /** Разбор события Meta (тот же путь проходят и тестовые заявки) */
    private function handleMeta(Integration $in, array $body, bool $test = false): void
    {
        $ws = $in->workspace_id;
        $g = new MetaGraph($in);
        foreach ($body['entry'] ?? [] as $e) {
            foreach ($e['changes'] ?? [] as $ch) {
                if (($ch['field'] ?? '') === 'leadgen') {           // форма заявки в рекламе
                    $v = $ch['value'] ?? [];
                    $lid = (string) ($v['leadgen_id'] ?? '');
                    try {
                        $L = $test ? $v['test'] : $g->call($lid, 'GET', ['fields' => 'created_time,field_data,platform,ad_name,campaign_name,form_id']);
                        $f = self::fromFields($L['field_data'] ?? []);
                        $ig = ($L['platform'] ?? '') === 'ig';
                        $text = implode(' · ', array_filter([! empty($L['ad_name']) ? 'Реклама: '.$L['ad_name'] : '', $f['text']])) ?: 'Заявка из рекламной формы';
                        $this->push($ws, ['ch' => $ig ? 'instagram' : 'facebook', 'kind' => 'form', 'key' => 'lead:'.$lid, 'name' => $f['name'], 'phone' => $f['phone'], 'text' => $text]);
                    } catch (\Throwable $er) {
                        IntegrationError::log($ws, 'Заявка Facebook '.$lid.': '.$er->getMessage());
                        $this->push($ws, ['ch' => 'facebook', 'kind' => 'form', 'key' => 'lead:'.$lid, 'name' => 'Заявка Facebook', 'text' => 'Не удалось загрузить данные: '.$er->getMessage()]);
                    }
                }
                if (($body['object'] ?? '') === 'whatsapp_business_account' && ($ch['field'] ?? '') === 'messages') {
                    $v = $ch['value'] ?? [];
                    $names = [];
                    foreach ($v['contacts'] ?? [] as $c) {
                        $names[$c['wa_id'] ?? ''] = $c['profile']['name'] ?? '';
                    }
                    foreach ($v['messages'] ?? [] as $m) {
                        $from = (string) ($m['from'] ?? '');
                        $text = $m['text']['body'] ?? ($m['button']['text'] ?? (isset($m['interactive']) ? mb_substr(json_encode($m['interactive'], JSON_UNESCAPED_UNICODE), 0, 200) : '['.($m['type'] ?? '?').']'));
                        $ad = $m['referral']['headline'] ?? ($m['referral']['body'] ?? '');
                        $this->push($ws, ['ch' => 'whatsapp', 'kind' => 'msg', 'key' => 'wa:'.$from, 'name' => $names[$from] ?? '', 'phone' => '+'.$from, 'text' => ($ad ? 'Реклама: '.$ad.' · ' : '').$text]);
                    }
                }
            }
            foreach ($e['messaging'] ?? [] as $m) {                 // Instagram Direct и Messenger
                if (empty($m['message']) || ! empty($m['message']['is_echo'])) {
                    continue;
                }
                $ig = ($body['object'] ?? '') === 'instagram';
                $id = (string) ($m['sender']['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $pr = $test ? ['name' => $m['test_name'] ?? '', 'username' => $m['test_user'] ?? ''] : $g->profile($id, $ig);
                $text = $m['message']['text'] ?? (! empty($m['message']['attachments']) ? '[вложение]' : '');
                $this->push($ws, ['ch' => $ig ? 'instagram' : 'messenger', 'kind' => 'msg', 'key' => ($ig ? 'ig:' : 'fb:').$id, 'name' => $pr['name'], 'user' => $pr['username'], 'text' => $text]);
            }
        }
    }

    /* ---------- внешние адреса ---------- */
    /** Meta проверяет адрес вебхука */
    public function metaVerify(Request $request, string $hook)
    {
        $in = Integration::where('hook_id', $hook)->firstOrFail();
        if ($request->query('hub_mode') === 'subscribe' && hash_equals($in->verify_token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('verify token mismatch', 403);
    }

    /** События от Meta: проверяем подпись X-Hub-Signature-256 ключом App Secret */
    public function metaEvent(Request $request, string $hook)
    {
        $in = Integration::where('hook_id', $hook)->firstOrFail();
        $raw = $request->getContent();
        if (! $in->app_secret) {
            IntegrationError::log($in->workspace_id, 'Пришло событие от Meta, но не указан App Secret — событие отклонено');

            return response()->json(['error' => 'app secret not set'], 403);
        }
        $want = 'sha256='.hash_hmac('sha256', $raw, $in->app_secret);
        if (! hash_equals($want, (string) $request->header('X-Hub-Signature-256'))) {
            IntegrationError::log($in->workspace_id, 'Неверная подпись события Meta (проверьте App Secret)');

            return response()->json(['error' => 'bad signature'], 403);
        }
        $body = json_decode($raw, true) ?: [];
        try {
            $this->handleMeta($in, $body);
        } catch (\Throwable $e) {
            IntegrationError::log($in->workspace_id, 'Webhook: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /** Заявка с сайта / Tilda / Make / Zapier: POST /api/lead?key=... */
    public function siteLead(Request $request)
    {
        $cors = ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Headers' => 'Content-Type, X-Api-Key', 'Access-Control-Allow-Methods' => 'POST, OPTIONS'];
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $cors);
        }
        $key = (string) ($request->query('key') ?: ($request->header('X-Api-Key') ?: $request->input('key')));
        $in = $key !== '' ? Integration::where('api_key', $key)->first() : null;
        if (! $in) {
            return response()->json(['error' => 'bad key'], 403, $cors);
        }
        $b = $request->all();
        $name = mb_substr((string) ($b['name'] ?? $b['Name'] ?? $b['full_name'] ?? $b['fio'] ?? ''), 0, 120);
        $phone = mb_substr((string) ($b['phone'] ?? $b['Phone'] ?? $b['tel'] ?? ''), 0, 40);
        if ($name === '' && $phone === '') {
            return response()->json(['error' => 'name or phone required'], 400, $cors);
        }
        $digits = preg_replace('/\D/', '', $phone);
        $this->push($in->workspace_id, [
            'ch' => 'site', 'kind' => 'form', 'key' => 'site:'.($digits ?: bin2hex(random_bytes(6))),
            'name' => $name, 'phone' => $phone, 'src' => (string) ($b['src'] ?? $b['source'] ?? $b['utm_source'] ?? ''),
            'rooms' => (string) ($b['rooms'] ?? ''), 'budget' => $b['budget'] ?? null,
            'text' => (string) ($b['message'] ?? $b['comment'] ?? $b['text'] ?? ''),
        ]);

        return response()->json(['ok' => true], 200, $cors);
    }

    /* ---------- для программы (после входа) ---------- */
    public function inbox(Request $request)
    {
        $ws = $this->ws()->id;
        $since = (int) $request->query('since', 0);
        $limit = 500;
        // граница — до выборки; если заявок больше лимита, seq = последняя отданная, остальные придут следующим запросом
        $top = (int) (LeadInbox::where('workspace_id', $ws)->max('id') ?? 0);
        $items = LeadInbox::where('workspace_id', $ws)->where('id', '>', $since)->where('id', '<=', $top)->orderBy('id')->limit($limit)->get();
        $more = $items->count() >= $limit;

        return response()->json([
            'seq' => $more ? (int) $items->last()->id : max($since, $top),
            'more' => $more,
            'items' => $items->map->toClient()->values(),
        ]);
    }

    /** Ключи подключений видят руководитель и менеджер по продажам */
    private function leadsRole(): void
    {
        $u = auth()->user();
        abort_unless($u->isAdmin() || ($u->role === 'manager' && $u->canEdit()), 403, __('Нет доступа к этому разделу'));
    }

    private function publicCfg(Integration $in): array
    {
        $base = rtrim($in->public_url ?: url('/'), '/');

        return [
            'verifyToken' => $in->verify_token,
            'apiKey' => $in->api_key,
            'publicUrl' => $base,
            'hookUrl' => $base.'/webhook/meta/'.$in->hook_id,
            'leadUrl' => $base.'/api/lead?key='.$in->api_key,
            'graphVer' => config('oftob.graph_version'),
            'has' => ['appSecret' => (bool) $in->app_secret, 'pageToken' => (bool) $in->page_token],
            'count' => LeadInbox::where('workspace_id', $in->workspace_id)->count(),
            'last' => LeadInbox::where('workspace_id', $in->workspace_id)->orderByDesc('id')->limit(10)->get()->map->toClient()->values(),
            'errors' => IntegrationError::where('workspace_id', $in->workspace_id)->orderByDesc('id')->limit(20)->get()
                ->map(fn ($e) => ['t' => $e->created_at?->toIso8601String(), 'msg' => $e->msg])->values(),
        ];
    }

    public function connect()
    {
        $this->leadsRole();

        return response()->json($this->publicCfg($this->ws()->integrationOrCreate()));
    }

    public function connectSave(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Ключи меняет только руководитель');
        $in = $this->ws()->integrationOrCreate();
        $b = $request->validate([
            'publicUrl' => ['nullable', 'string', 'max:255'],
            'appSecret' => ['nullable', 'string', 'max:255'],
            'pageToken' => ['nullable', 'string', 'max:2000'],
            'clear' => ['nullable', 'in:appSecret,pageToken'],
            'regen' => ['nullable', 'in:verify,api'],
        ]);
        if ($request->has('publicUrl')) {
            $u = rtrim(trim((string) $b['publicUrl']), '/');
            if ($u !== '' && ! str_starts_with($u, 'https://')) {
                return response()->json(['error' => 'Адрес должен начинаться с https://'], 422);
            }
            $in->public_url = $u ?: null;
        }
        if (! empty($b['appSecret'])) {
            $in->app_secret = trim($b['appSecret']);
        }
        if (! empty($b['pageToken'])) {
            $in->page_token = trim($b['pageToken']);
        }
        if (($b['clear'] ?? '') === 'appSecret') {
            $in->app_secret = null;
        }
        if (($b['clear'] ?? '') === 'pageToken') {
            $in->page_token = null;
        }
        if (($b['regen'] ?? '') === 'verify') {
            $in->verify_token = 'oftob-'.bin2hex(random_bytes(8));
        }
        if (($b['regen'] ?? '') === 'api') {
            $in->api_key = bin2hex(random_bytes(12));
        }
        $in->save();

        return response()->json($this->publicCfg($in));
    }

    public function connectCheck()
    {
        $this->leadsRole();
        $g = new MetaGraph($this->ws()->integrationOrCreate());
        try {
            $me = $g->call('me', 'GET', ['fields' => 'id,name,instagram_business_account{username}']);
            $subs = [];
            try {
                $subs = $g->call($me['id'].'/subscribed_apps')['data'] ?? [];
            } catch (\Throwable) {
            }

            return response()->json([
                'ok' => true,
                'page' => $me['name'] ?? '',
                'ig' => $me['instagram_business_account']['username'] ?? null,
                'subscribed' => collect($subs)->map(fn ($s) => implode(', ', $s['subscribed_fields'] ?? []))->implode('; '),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function connectSubscribe(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $g = new MetaGraph($this->ws()->integrationOrCreate());
        try {
            $me = $g->call('me', 'GET', ['fields' => 'id']);
            $g->call($me['id'].'/subscribed_apps', 'POST', ['subscribed_fields' => 'leadgen,messages']);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /** Тестовая заявка — проходит тот же путь, что и настоящая */
    public function test(Request $request)
    {
        $this->leadsRole();
        $in = $this->ws()->integrationOrCreate();
        $ch = (string) $request->input('ch');
        $id = bin2hex(random_bytes(5));
        if ($ch === 'site') {
            $this->push($in->workspace_id, ['ch' => 'site', 'kind' => 'form', 'key' => 'site:test'.$id, 'name' => 'Тест с сайта', 'phone' => '+998900000004', 'src' => 'Сайт', 'rooms' => '2', 'text' => 'Перезвоните после 18:00']);

            return response()->json(['ok' => true]);
        }
        $wa = '99890000'.random_int(1000, 9999);
        $S = [
            'facebook' => ['object' => 'page', 'entry' => [['changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'test'.$id, 'test' => ['platform' => 'fb', 'ad_name' => 'ЖК — квартиры в рассрочку', 'field_data' => [['name' => 'full_name', 'values' => ['Тест Facebook']], ['name' => 'phone_number', 'values' => ['+992900000001']], ['name' => 'сколько_комнат', 'values' => ['2']]]]]]]]]],
            'instagram' => ['object' => 'instagram', 'entry' => [['messaging' => [['sender' => ['id' => 'test'.$id], 'test_name' => 'Тест Instagram', 'test_user' => 'test.client', 'message' => ['text' => 'Здравствуйте! Сколько стоит 3-комнатная? Мой номер +996 700 000 002']]]]]],
            'whatsapp' => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['contacts' => [['wa_id' => $wa, 'profile' => ['name' => 'Тест WhatsApp']]], 'messages' => [['from' => $wa, 'type' => 'text', 'text' => ['body' => 'Добрый день, интересует 1-комнатная квартира, есть рассрочка?']]]]]]]]],
        ];
        if (! isset($S[$ch])) {
            return response()->json(['error' => 'unknown channel'], 400);
        }
        $this->handleMeta($in, $S[$ch], true);

        return response()->json(['ok' => true]);
    }
}
