<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Api\Scopes;
use App\Services\Api\Spec;
use App\Services\Api\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** «API и интеграции»: ключи для других программ, вебхуки и документация — только руководитель */
class ApiController extends Controller
{
    public const MAX_KEYS = 20;

    public function index()
    {
        $ws = $this->ws();
        $hooks = Webhook::of($ws->id)->orderByDesc('id')->get();

        return view('company.api', [
            'ws' => $ws,
            'tokens' => ApiToken::of($ws->id)->with('user')->orderByRaw('case when revoked_at is null then 0 else 1 end')->orderByDesc('id')->get(),
            'hooks' => $hooks,
            'deliveries' => WebhookDelivery::whereIn('webhook_id', $hooks->pluck('id'))->latest('id')->limit(60)->get()->groupBy('webhook_id'),
            'base' => url('/api/v1'),
            'endpoints' => Spec::endpoints(),
            'leadKey' => $ws->integration?->api_key,
        ]);
    }

    private function log(string $action, string $summary): void
    {
        AuditLog::create(['workspace_id' => $this->ws()->id, 'user_id' => auth()->id(), 'action' => $action, 'summary' => $summary]);
    }

    /* ---------------- ключи ---------------- */

    public function tokenStore(Request $request)
    {
        $ws = $this->ws();
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'preset' => ['nullable', Rule::in(array_merge(array_keys(Scopes::PRESETS), ['custom']))],
            'scopes' => ['nullable', 'array'], 'scopes.*' => ['string', 'max:30'], 'expires' => ['nullable', Rule::in(['0', '30', '90', '365'])],
        ]);
        $preset = $d['preset'] ?? 'custom';
        $scopes = Scopes::clean($preset !== 'custom' ? Scopes::preset($preset) : (array) ($d['scopes'] ?? []));
        if (! $scopes) {
            return back()->withErrors(['scopes' => __('Отметьте хотя бы один раздел')])->withInput()->with('open', 'dlg-key');
        }
        if (ApiToken::of($ws->id)->whereNull('revoked_at')->count() >= self::MAX_KEYS) {
            return back()->withErrors(['name' => __('Не больше :n действующих ключей. Отзовите ненужные.', ['n' => self::MAX_KEYS])]);
        }
        $days = (int) ($d['expires'] ?? 0);
        [$t, $plain] = ApiToken::issue($ws, $request->user(), trim($d['name']), $scopes, $days ? now()->addDays($days) : null);
        $this->log('api.add', 'API-ключ выпущен: «'.$t->name.'» · '.implode(', ', $scopes));

        // ключ целиком — только сейчас, один раз
        return back()->with('apiKey', ['id' => $t->id, 'name' => $t->name, 'key' => $plain]);
    }

    public function tokenRevoke(int $id)
    {
        $t = ApiToken::of($this->ws()->id)->findOrFail($id);
        if (! $t->revoked_at) {
            $t->forceFill(['revoked_at' => now()])->save();
            $this->log('api.del', 'API-ключ отозван: «'.$t->name.'»');
        }

        return back()->with('ok', __('Ключ отозван — программы с ним больше не войдут'));
    }

    /* ---------------- вебхуки ---------------- */

    public function hookStore(Request $request)
    {
        $ws = $this->ws();
        $d = $request->validate([
            'id' => ['nullable', 'integer'], 'url' => ['required', 'string', 'max:500'],
            'events' => ['required', 'array', 'min:1'], 'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
        ], ['events.required' => __('Отметьте хотя бы одно событие')]);
        $url = trim($d['url']);
        try {
            Webhooks::target($url);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['url' => $e->getMessage()])->withInput()->with('open', 'dlg-hook');
        }
        $events = array_values(array_unique($d['events']));
        if (! empty($d['id'])) {
            $h = Webhook::of($ws->id)->findOrFail($d['id']);
            $h->update(['url' => $url, 'events' => $events, 'is_active' => true, 'fails' => 0]);
            $this->log('api.edit', 'Вебхук изменён: '.$url);
        } else {
            if (Webhook::of($ws->id)->count() >= Webhooks::MAX_HOOKS) {
                return back()->withErrors(['url' => __('Не больше :n вебхуков', ['n' => Webhooks::MAX_HOOKS])]);
            }
            $h = Webhook::create(['workspace_id' => $ws->id, 'user_id' => auth()->id(), 'url' => $url, 'secret' => 'whsec_'.Str::random(40), 'events' => $events]);
            $this->log('api.add', 'Вебхук добавлен: '.$url.' · '.implode(', ', $events));
        }
        Webhooks::forget($ws->id);

        return back()->with('ok', __('Сохранено. Нажмите «Проверить», чтобы отправить тестовое событие.'))->with('hook', $h->id);
    }

    private function hook(int $id): Webhook
    {
        return Webhook::of($this->ws()->id)->findOrFail($id);
    }

    public function hookToggle(int $id)
    {
        $h = $this->hook($id);
        $h->forceFill(['is_active' => ! $h->is_active, 'fails' => 0])->save();
        Webhooks::forget($h->workspace_id);
        $this->log('api.edit', 'Вебхук '.($h->is_active ? 'включён' : 'выключен').': '.$h->url);

        return back()->with('ok', $h->is_active ? __('Вебхук включён') : __('Вебхук выключен'));
    }

    public function hookTest(int $id)
    {
        $d = Webhooks::ping($this->hook($id));

        return $d->ok
            ? back()->with('ok', __('Тест доставлен: ответ :s за :ms мс', ['s' => $d->status, 'ms' => $d->ms]))->with('hook', $id)
            : back()->withErrors(['hook' => __('Тест не доставлен').': '.($d->error ?: 'HTTP '.$d->status)])->with('hook', $id);
    }

    public function hookSecret(int $id)
    {
        $h = $this->hook($id);
        $h->forceFill(['secret' => 'whsec_'.Str::random(40)])->save();
        $this->log('api.edit', 'Вебхук: новый секрет подписи · '.$h->url);

        return back()->with('ok', __('Новый секрет создан — замените его в программе-получателе'))->with('hook', $id);
    }

    public function hookDelete(int $id)
    {
        $h = $this->hook($id);
        $h->delete();
        Webhooks::forget($h->workspace_id);
        $this->log('api.del', 'Вебхук удалён: '.$h->url);

        return back()->with('ok', __('Вебхук удалён'));
    }

    /** Отправить событие ещё раз (например, когда программа-получатель снова работает) */
    public function deliveryRetry(int $id)
    {
        $d = WebhookDelivery::whereIn('webhook_id', Webhook::of($this->ws()->id)->pluck('id'))->findOrFail($id);
        $ok = Webhooks::deliver($d, true);

        return $ok ? back()->with('ok', __('Доставлено'))->with('hook', $d->webhook_id)
            : back()->withErrors(['hook' => __('Не доставлено').': '.($d->error ?: 'HTTP '.$d->status)])->with('hook', $d->webhook_id);
    }
}
