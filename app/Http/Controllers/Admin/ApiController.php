<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use App\Services\Api\Scopes;
use App\Services\Api\Spec;
use App\Services\Api\Webhooks;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API платформы. Ключи выдаёт только администратор платформы: кому, для какой компании, с какой ролью и на какие объекты.
 * Ключ работает как сотрудник компании с этой ролью. Здесь же — вебхуки ключей, заявки компаний на API и общий выключатель.
 */
class ApiController extends Controller
{
    public function index(Request $request)
    {
        $cid = (int) $request->query('company') ?: null;
        $q = trim((string) $request->query('q'));
        $only = fn ($x) => $x->when($cid, fn ($y) => $y->where('workspace_id', $cid))
            ->when($q !== '', fn ($y) => $y->where(fn ($z) => $z->where('name', 'like', "%$q%")->orWhere('contact', 'like', "%$q%")
                ->orWhereHas('workspace', fn ($w) => $w->where('name', 'like', "%$q%"))));
        $active = fn ($x) => $x->whereNull('revoked_at')->where(fn ($y) => $y->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        $day = now()->subDay();
        $companies = Workspace::orderBy('name')->get(['id', 'name']);   // без данных CRM — их может быть много
        $tokens = $only(ApiToken::with(['workspace', 'user', 'webhooks']))->orderByRaw('case when revoked_at is null then 0 else 1 end')->orderByDesc('id')->limit(300)->get();
        $hooks = Webhook::with(['workspace', 'token'])->when($cid, fn ($x) => $x->where('workspace_id', $cid))->orderByDesc('is_active')->orderByDesc('fails')->orderByDesc('id')->get();

        return view('admin.api', [
            'enabled' => Scopes::enabled(),
            'cid' => $cid, 'q' => $q,
            'companies' => $companies,
            // названия объектов — только у компаний, где ключ ограничен объектами
            'names' => Workspace::whereIn('id', $tokens->filter(fn ($t) => $t->allowedObjects())->pluck('workspace_id')->unique())->get()
                ->mapWithKeys(fn ($w) => [$w->id => StateWriter::projects($w)]),
            'requests' => Workspace::whereNotNull('settings->api_request')->orderBy('name')->get(['id', 'name', 'settings'])->filter(fn ($w) => is_array($w->setting('api_request'))),
            'tokens' => $tokens,
            'hooks' => $hooks,
            'deliveries' => WebhookDelivery::whereIn('webhook_id', $hooks->pluck('id'))->latest('id')->limit(80)->get()->groupBy('webhook_id'),
            'fails' => WebhookDelivery::with('webhook.workspace')->where('ok', false)->where('attempts', '>', 0)
                ->when($cid, fn ($x) => $x->whereHas('webhook', fn ($h) => $h->where('workspace_id', $cid)))->latest('id')->limit(15)->get(),
            'kpi' => [
                'companies' => $active(ApiToken::query())->distinct()->count('workspace_id'),
                'keys' => $active(ApiToken::query())->count(),
                'used' => ApiToken::where('last_used_at', '>=', $day)->count(),
                'hooks' => Webhook::where('is_active', true)->count(),
                'hooksOff' => Webhook::where('is_active', false)->count(),
                'sent' => WebhookDelivery::where('created_at', '>=', $day)->where('ok', true)->count(),
                'failed' => WebhookDelivery::where('created_at', '>=', $day)->where('ok', false)->where('attempts', '>', 0)->count(),
            ],
            'base' => url('/api/v1'),
            'endpoints' => Spec::endpoints(),
        ]);
    }

    /** Для окна «Выдать ключ»: объекты выбранной компании и что может каждая роль в ней */
    public function company(Workspace $workspace)
    {
        return response()->json([
            'objects' => collect(StateWriter::projects($workspace))->map(fn ($n, $id) => ['id' => (string) $id, 'name' => $n])->values(),
            'roles' => collect(config('oftob.roles'))->mapWithKeys(fn ($n, $r) => [$r => Scopes::forRole($workspace, $r)]),
        ]);
    }

    /** Запись в «Историю изменений» компании: что сделал администратор платформы с её API */
    private function log(int $wsId, string $action, string $summary): void
    {
        AuditLog::create(['workspace_id' => $wsId, 'user_id' => auth()->id(), 'action' => $action, 'summary' => 'Платформа: '.$summary]);
    }

    /** API на всей платформе: выключить (ключи и вебхуки сохраняются) или включить снова */
    public function toggle(Request $request)
    {
        $on = $request->boolean('enabled');
        PlatformSetting::put('api_enabled', $on ? '1' : '0');
        foreach (Webhook::distinct()->pluck('workspace_id') as $ws) {
            Webhooks::forget((int) $ws);
        }

        return back()->with('ok', $on ? __('API включён на платформе') : __('API выключен на платформе: ключи не работают, вебхуки не отправляются'));
    }

    /* ---------------- ключи ---------------- */

    /** Выдать ключ (или изменить: кому, роль, объекты, только чтение, срок) */
    public function tokenSave(Request $request)
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'workspace_id' => ['required_without:id', 'nullable', 'integer', Rule::exists('workspaces', 'id')],
            'name' => ['required', 'string', 'max:80'], 'contact' => ['nullable', 'string', 'max:160'], 'purpose' => ['nullable', 'string', 'max:300'],
            'role' => ['required', Rule::in(array_keys(config('oftob.roles')))], 'read_only' => ['nullable', 'boolean'],
            'objects' => ['nullable', 'array'], 'objects.*' => ['string', 'max:40'], 'expires' => ['nullable', Rule::in(['keep', '0', '30', '90', '180', '365'])],
        ]);
        $t = ! empty($d['id']) ? ApiToken::findOrFail($d['id']) : null;
        $ws = $t ? $t->workspace : Workspace::findOrFail($d['workspace_id']);
        $objects = array_values(array_intersect((array) ($d['objects'] ?? []), array_map('strval', array_keys(StateWriter::projects($ws)))));
        $o = ['role' => $d['role'], 'read_only' => $request->boolean('read_only'), 'object_ids' => $objects ?: null,
            'contact' => trim((string) ($d['contact'] ?? '')) ?: null, 'purpose' => trim((string) ($d['purpose'] ?? '')) ?: null];
        $exp = $d['expires'] ?? '0';
        if ($exp !== 'keep') {
            $o['expires_at'] = (int) $exp ? now()->addDays((int) $exp) : null;
        }
        $what = config('oftob.roles.'.$d['role']).($o['read_only'] ? ', только чтение' : '').($objects ? ', объекты: '.implode(', ', array_intersect_key(StateWriter::projects($ws), array_flip($objects))) : '');
        if ($t) {
            $t->fill($o + ['name' => trim($d['name'])]);
            $t->scopes = Scopes::forRole($ws, $t->role, (bool) $t->read_only);
            $t->save();
            $this->log($ws->id, 'api.edit', 'изменён API-ключ «'.$t->name.'»: '.$what);
            Webhooks::forget($ws->id);

            return back()->with('ok', __('Ключ изменён: права — как у роли «:r»', ['r' => $t->roleName()]));
        }
        [$t, $plain] = ApiToken::issue($ws, $request->user(), trim($d['name']), $o);
        $this->log($ws->id, 'api.add', 'выдан API-ключ «'.$t->name.'»'.($t->contact ? ' ('.$t->contact.')' : '').': '.$what);
        // заявка компании на API выполнена
        if (is_array($ws->setting('api_request'))) {
            $ws->putSetting('api_request', null);
        }

        return back()->with('apiKey', ['id' => $t->id, 'name' => $t->name, 'company' => $ws->name, 'role' => $t->roleName(), 'key' => $plain]);
    }

    public function tokenRevoke(ApiToken $token)
    {
        if (! $token->revoked_at) {
            $token->forceFill(['revoked_at' => now()])->save();
            Webhooks::forget($token->workspace_id);
            $this->log($token->workspace_id, 'api.del', 'отозван API-ключ «'.$token->name.'»');
        }

        return back()->with('ok', __('Ключ отозван — программы с ним больше не войдут'));
    }

    /** Заявку компании на API — отклонить (ключ выдавать не будем) */
    public function requestDismiss(Workspace $workspace)
    {
        $workspace->putSetting('api_request', null);
        $this->log($workspace->id, 'api.del', 'заявка на API отклонена');

        return back()->with('ok', __('Заявка закрыта'));
    }

    /* ---------------- вебхуки ключа ---------------- */

    public function hookSave(Request $request)
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'], 'api_token_id' => ['required_without:id', 'nullable', 'integer'], 'url' => ['required', 'string', 'max:500'],
            'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
        ], ['events.required' => __('Отметьте хотя бы одно событие')]);
        $h = ! empty($d['id']) ? Webhook::findOrFail($d['id']) : null;
        $t = $h ? $h->token : ApiToken::findOrFail($d['api_token_id']);
        abort_if(! $t, 422, __('Вебхук без ключа'));
        $url = trim($d['url']);
        try {
            Webhooks::target($url);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['url' => $e->getMessage()])->withInput()->with('open', 'dlg-hook');
        }
        // только события, которые роль ключа видит
        $events = array_values(array_intersect(array_unique($d['events']), Webhooks::eventsFor($t->effectiveScopes())));
        if (! $events) {
            return back()->withErrors(['url' => __('Роль ключа не видит выбранные события')])->withInput()->with('open', 'dlg-hook');
        }
        if ($h) {
            $h->update(['url' => $url, 'events' => $events, 'is_active' => true, 'fails' => 0]);
            $this->log($t->workspace_id, 'api.edit', 'изменён вебхук ключа «'.$t->name.'»: '.$url);
        } else {
            if ($t->webhooks()->count() >= Webhooks::MAX_HOOKS) {
                return back()->withErrors(['url' => __('Не больше :n вебхуков', ['n' => Webhooks::MAX_HOOKS])]);
            }
            $h = Webhook::create(['workspace_id' => $t->workspace_id, 'api_token_id' => $t->id, 'user_id' => auth()->id(), 'url' => $url, 'secret' => 'whsec_'.Str::random(40), 'events' => $events]);
            $this->log($t->workspace_id, 'api.add', 'вебхук ключа «'.$t->name.'»: '.$url.' · '.implode(', ', $events));
        }
        Webhooks::forget($t->workspace_id);

        return back()->with('ok', __('Сохранено. Нажмите «Проверить», чтобы отправить тестовое событие.'))->with('hook', $h->id);
    }

    public function hookToggle(Webhook $webhook)
    {
        $webhook->forceFill(['is_active' => ! $webhook->is_active, 'fails' => 0])->save();
        Webhooks::forget($webhook->workspace_id);
        $this->log($webhook->workspace_id, 'api.edit', 'вебхук '.($webhook->is_active ? 'включён' : 'выключен').': '.$webhook->url);

        return back()->with('ok', $webhook->is_active ? __('Вебхук включён') : __('Вебхук выключен'))->with('hook', $webhook->id);
    }

    public function hookTest(Webhook $webhook)
    {
        $d = Webhooks::ping($webhook);

        return $d->ok
            ? back()->with('ok', __('Тест доставлен: ответ :s за :ms мс', ['s' => $d->status, 'ms' => $d->ms]))->with('hook', $webhook->id)
            : back()->withErrors(['hook' => __('Тест не доставлен').': '.($d->error ?: 'HTTP '.$d->status)])->with('hook', $webhook->id);
    }

    public function hookSecret(Webhook $webhook)
    {
        $webhook->forceFill(['secret' => 'whsec_'.Str::random(40)])->save();
        $this->log($webhook->workspace_id, 'api.edit', 'новый секрет подписи вебхука: '.$webhook->url);

        return back()->with('ok', __('Новый секрет создан — замените его в программе-получателе'))->with('hook', $webhook->id);
    }

    public function hookDelete(Webhook $webhook)
    {
        $webhook->delete();
        Webhooks::forget($webhook->workspace_id);
        $this->log($webhook->workspace_id, 'api.del', 'вебхук удалён: '.$webhook->url);

        return back()->with('ok', __('Вебхук удалён'));
    }

    /** Отправить событие ещё раз (например, когда программа-получатель снова работает) */
    public function deliveryRetry(WebhookDelivery $delivery)
    {
        $ok = Webhooks::deliver($delivery, true);

        return $ok ? back()->with('ok', __('Доставлено'))->with('hook', $delivery->webhook_id)
            : back()->withErrors(['hook' => __('Не доставлено').': '.($delivery->error ?: 'HTTP '.$delivery->status)])->with('hook', $delivery->webhook_id);
    }
}
