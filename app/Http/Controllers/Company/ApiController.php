<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Api\Spec;
use App\Services\Api\Webhooks;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «API и интеграции» в кабинете компании (руководитель): какие ключи выдала платформа и кому, с какой ролью,
 * когда ими пользовались; заявка на новый ключ; срочно отозвать ключ. Выдаёт ключи только администратор платформы.
 */
class ApiController extends Controller
{
    public function index()
    {
        $ws = $this->ws();

        return view('company.api', [
            'ws' => $ws,
            'tokens' => ApiToken::of($ws->id)->with('webhooks')->orderByRaw('case when revoked_at is null then 0 else 1 end')->orderByDesc('id')->get(),
            'request' => $ws->setting('api_request'),
            'base' => url('/api/v1'),
            'endpoints' => Spec::endpoints(),
            'leadKey' => $ws->integration?->api_key,
        ]);
    }

    /** Заявка на ключ: кому и зачем нужен доступ, с какой ролью — администратор платформы выдаст ключ */
    public function request(Request $request)
    {
        $ws = $this->ws();
        $d = $request->validate([
            'who' => ['required', 'string', 'max:120'], 'purpose' => ['required', 'string', 'max:500'],
            'role' => ['required', Rule::in(array_keys(config('oftob.roles')))], 'contact' => ['nullable', 'string', 'max:160'],
        ]);
        $ws->putSetting('api_request', $d + ['by' => $request->user()->name, 'at' => now()->toIso8601String()]);
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $request->user()->id, 'action' => 'api.add', 'summary' => 'Заявка на API-ключ: '.$d['who'].' · '.config('oftob.roles.'.$d['role'])]);
        // владельцу и администраторам платформы с правом «API» — сразу в Telegram
        if (Telegram::enabled()) {
            foreach (User::whereIn('role', ['superadmin', 'padmin'])->whereNotNull('telegram_chat_id')->get()->filter(fn ($u) => $u->adminCan('api')) as $a) {
                try {
                    Telegram::message($a->telegram_chat_id, '🔌 <b>'.e(__('Заявка на API')).'</b>'."\n".e($ws->name).' — '.e($d['who'])."\n".e($d['purpose']),
                        [[['text' => __('Открыть'), 'url' => url('/admin/api')]]]);
                } catch (\Throwable) {
                }
            }
        }

        return back()->with('ok', __('Заявка отправлена. Администратор платформы выдаст ключ и сообщит вам.'));
    }

    /** Срочно отозвать ключ своей компании (например, если он попал не в те руки) */
    public function tokenRevoke(int $id)
    {
        $t = ApiToken::of($this->ws()->id)->findOrFail($id);
        if (! $t->revoked_at) {
            $t->forceFill(['revoked_at' => now()])->save();
            Webhooks::forget($t->workspace_id);
            AuditLog::create(['workspace_id' => $t->workspace_id, 'user_id' => auth()->id(), 'action' => 'api.del', 'summary' => 'API-ключ отозван руководителем: «'.$t->name.'»']);
        }

        return back()->with('ok', __('Ключ отозван — программы с ним больше не войдут'));
    }
}
