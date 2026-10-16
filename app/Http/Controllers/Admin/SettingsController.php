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
            // один бот на двух сайтах: этот — второй (берёт сообщения с основного) или к этому подключён второй
            'tgRelay' => \App\Services\TelegramRelay::secondary(),
            'tgRelayOk' => \App\Services\TelegramRelay::ok(),
            'tgPeer' => \App\Services\TelegramRelay::peerOnline() ? \App\Services\TelegramRelay::peerSite() : null,
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
            'telegram_hub' => ['nullable', 'url', 'max:200'],
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
        // основной сайт бота (этот же бот работает на сервере): сообщения этого сайта берём оттуда
        $hub = rtrim(trim((string) ($d['telegram_hub'] ?? '')), '/');
        if ($hub !== '' && rtrim((string) config('app.url'), '/') === $hub) {
            return back()->withErrors(['telegram_hub' => __('Это адрес этого же сайта. Укажите адрес основного сайта, где работает бот (например, сервера).')])->withInput();
        }
        $d['telegram_hub'] = $hub;
        if ($hub !== \App\Services\TelegramRelay::hub()) {
            foreach (['tg-relay-ack', 'tg-relay-claims', 'tg-relay-unclaims', 'tg-relay-rel-ack', 'tg-relay-ok', 'tg-yield', 'tg-conflict-at'] as $k) {
                \Illuminate\Support\Facades\Cache::forget($k);
            }
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
