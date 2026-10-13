<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\PlatformSetting;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Настройки компании: реквизиты, язык, время отчёта, Telegram, выбор ИИ */
class SettingsController extends Controller
{
    public function edit()
    {
        $ws = $this->ws();
        if (! $ws->report_link_code) {
            $ws->forceFill(['report_link_code' => strtoupper(Str::random(6))])->save();
        }

        return view('company.settings', [
            'ws' => $ws,
            'bot' => PlatformSetting::get('telegram_bot'),
            'tgEnabled' => Telegram::enabled(),
            'providers' => AiProvider::active()->get(),
            'aiMonth' => AiUsage::where('workspace_id', $ws->id)->where('created_at', '>=', now()->startOfMonth())
                ->selectRaw('kind, count(*) as n, sum(input_tokens) as tin, sum(output_tokens) as tout')->groupBy('kind')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['required', Rule::in(array_keys(config('oftob.locales')))],
            'timezone' => ['required', Rule::in(array_keys(config('oftob.timezones')))],
            'report_enabled' => ['nullable', 'boolean'],
            'report_ai' => ['nullable', 'boolean'],
            'report_time' => ['required', 'date_format:H:i'],
            'report_email' => ['nullable', 'email', 'max:120'],
            'report_chats' => ['nullable', 'string', 'max:250', 'regex:/^[\-0-9,\s]*$/'],
            'ai_provider_id' => ['nullable', 'integer', Rule::exists('ai_providers', 'id')->where('is_active', true)],
        ]);
        $data['report_enabled'] = $request->boolean('report_enabled');
        $data['report_ai'] = $request->boolean('report_ai');
        $data['report_chats'] = implode(',', array_filter(array_map('trim', explode(',', (string) ($data['report_chats'] ?? '')))));
        $this->ws()->update($data);

        return back()->with('ok', __('Сохранено'));
    }

    /** Найти чат руководителя в Telegram по коду, который он отправил боту */
    public function telegramLink(\App\Services\TelegramPoller $poller)
    {
        $before = (string) $this->ws()->report_chats;
        try {
            $poller->once(0);   // бот сам привязывает чат, получив «/start КОД»
        } catch (\Throwable $e) {
            return back()->withErrors(['tg' => $e->getMessage()]);
        }
        $after = (string) $this->ws()->fresh()->report_chats;
        if ($after === $before) {
            return back()->withErrors(['tg' => __('Сообщение с кодом не найдено. Отправьте боту код и нажмите ещё раз.')]);
        }

        return back()->with('ok', __('Telegram подключён.'));
    }

    /** Права ролей на разделы ERP */
    public function roles(Request $request)
    {
        $mods = array_keys(config('oftob.modules'));
        $out = [];
        foreach (array_keys(config('oftob.roles')) as $role) {
            if ($role !== 'admin') {
                $out[$role] = array_values(array_intersect((array) $request->input('m.'.$role, []), $mods));
            }
        }
        $this->ws()->putSetting('role_modules', $out);

        return back()->with('ok', __('Права ролей сохранены'));
    }

    /** Согласование расходов и публичная шахматка */
    public function updateOps(Request $request)
    {
        $d = $request->validate(['approval_limit' => ['nullable', 'numeric', 'min:0'], 'didox_token' => ['nullable', 'string', 'max:500']]);
        $ws = $this->ws();
        if (! empty($d['didox_token'])) {
            $ws->putSetting('didox.token', \Illuminate\Support\Facades\Crypt::encryptString($d['didox_token']));
        }
        $ws->approval_limit = (float) ($d['approval_limit'] ?? 0);
        $ws->public_enabled = $request->boolean('public_enabled');
        if ($ws->public_enabled && ! $ws->public_key) {
            $ws->public_key = Str::lower(Str::random(12));
        }
        $ws->save();

        return back()->with('ok', __('Сохранено'));
    }
}
