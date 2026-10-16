<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Services\Telegram;
use Illuminate\Http\Request;

/** Настройки платформы и журнал оплат */
class SettingsController extends Controller
{
    public function edit()
    {
        \App\Services\TelegramPoller::autostart();

        return view('admin.settings', [
            'v' => fn ($k) => PlatformSetting::get($k),
            'hasToken' => Telegram::enabled(),
            // получает ли этот сайт сообщения бота и не забирает ли их другой сайт с тем же ботом
            'tgPoll' => \App\Services\TelegramPoller::active(),
            'tgListening' => \App\Services\TelegramPoller::listening(),
            'tgShared' => \App\Services\TelegramPoller::shared(),
            'plans' => Plan::orderBy('sort')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $d = $request->validate([
            'brand' => ['required', 'string', 'max:60'],
            'support_phone' => ['nullable', 'string', 'max:40'],
            'support_telegram' => ['nullable', 'string', 'max:60'],
            'support_email' => ['nullable', 'email', 'max:120'],
            'default_plan' => ['nullable', 'string', 'max:40'],
            'telegram_token' => ['nullable', 'string', 'max:120'],
            'google_client_id' => ['nullable', 'string', 'max:200', 'regex:/^[\w\-.]*$/'],
            'google_client_secret' => ['nullable', 'string', 'max:200'],
        ]);
        if (empty($d['google_client_secret'])) {
            unset($d['google_client_secret']);   // пустое поле — секрет не меняется
        }
        $d['registration_open'] = $request->boolean('registration_open') ? '1' : '0';
        if (! empty($d['telegram_token'])) {
            try {
                $d['telegram_bot'] = Telegram::me($d['telegram_token']);
            } catch (\Throwable $e) {
                return back()->withErrors(['telegram_token' => $e->getMessage()])->withInput();
            }
        } else {
            unset($d['telegram_token']);   // пустое поле — токен не меняется
        }
        if ($request->boolean('telegram_clear')) {
            $d['telegram_token'] = '';
            $d['telegram_bot'] = '';
        }
        // другой бот — свой счёт сообщений: старое смещение у нового бота пропустило бы (и удалило) его сообщения
        if (array_key_exists('telegram_token', $d) && $d['telegram_token'] !== Telegram::token()) {
            $d['telegram_offset'] = '0';
            \Illuminate\Support\Facades\Cache::forget('tg-conflict-at');
            \Illuminate\Support\Facades\Cache::forget('tg-yield');   // свой бот — уступать больше некому
        }
        foreach ($d as $k => $v) {
            PlatformSetting::put($k, $v === null ? '' : (string) $v);
        }

        return back()->with('ok', __('Сохранено'));
    }

    public function payments()
    {
        return view('admin.payments', [
            'payments' => SubscriptionPayment::with(['workspace', 'plan'])->latest('paid_at')->latest('id')->paginate(50),
            'total' => SubscriptionPayment::where('paid_at', '>=', now()->startOfMonth()->toDateString())->sum('amount'),
            'year' => SubscriptionPayment::where('paid_at', '>=', now()->startOfYear()->toDateString())->sum('amount'),
        ]);
    }
}
