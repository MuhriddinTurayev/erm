<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ограничение частоты для внешних адресов (вебхуки, заявки с сайта, телефоны сотрудников)
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        Paginator::useBootstrapFive();

        $this->webhooks();

        // компьютер (Open Server) без планировщика: после ответа страницы сам запускаем фоновую работу бота — без bot.bat
        if (\App\Services\TelegramPoller::autoMode() && ! $this->app->runningInConsole()) {
            $this->app->terminating(function () {
                try {
                    \App\Services\TelegramPoller::autostart();
                } catch (\Throwable) {
                }
            });
        }

        // даты по-узбекски — латиницей (у Carbon «uz» по умолчанию кириллица)
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Events\LocaleUpdated::class, function ($e) {
            \Illuminate\Support\Carbon::setLocale($e->locale === 'uz' ? 'uz_Latn' : $e->locale);
        });

        // общие данные страниц сайта (шаблон сайта рендерится после страницы — переменные нужны обоим)
        \Illuminate\Support\Facades\View::composer(['layouts.site', 'site.*'], function ($view) {
            static $shared;
            $shared ??= (function () {
                $u = auth()->user();
                $s = fn ($k) => \App\Models\PlatformSetting::get($k);

                return [
                    'brand' => \App\Models\PlatformSetting::brand(),
                    'appUrl' => $u ? ($u->isSuperAdmin() ? '/admin' : '/crm') : null,
                    'regOpen' => $s('registration_open') === '1',
                    'support' => ['phone' => $s('support_phone'), 'telegram' => $s('support_telegram'), 'email' => $s('support_email')],
                ];
            })();
            $view->with($shared);
        });
    }

    /** Вебхуки: о новых записях и изменениях сообщаем программам, которые на это подписаны (квартиры — в StateWriter / StateController) */
    private function webhooks(): void
    {
        $emit = fn (int $ws, string $event, \Closure $data) => \App\Services\Api\Webhooks::emit($ws, $event, $data);
        $P = \App\Services\Api\Present::class;

        \App\Models\LeadInbox::created(fn ($m) => $emit($m->workspace_id, 'lead.created', fn () => $P::lead($m)));
        \App\Models\StockMove::created(fn ($m) => $emit($m->workspace_id, 'stock.moved', fn () => $P::move($m->loadMissing(['item', 'warehouse']))));
        \App\Models\CashOp::created(fn ($m) => $emit($m->workspace_id, 'cash.created', fn () => $P::cashOp($m->loadMissing(['desk', 'category']))));
        \App\Models\Counterparty::created(fn ($m) => $emit($m->workspace_id, 'counterparty.created', fn () => $P::counterparty($m)));
        \App\Models\PaymentRequest::created(fn ($m) => $emit($m->workspace_id, 'payment.updated', fn () => $P::paymentRequest($m->loadMissing(['counterparty', 'category'])) + ['previous_status' => null]));
        \App\Models\PaymentRequest::updated(function ($m) use ($emit, $P) {
            if ($m->wasChanged('status')) {
                $prev = $m->getOriginal('status');
                $emit($m->workspace_id, 'payment.updated', fn () => $P::paymentRequest($m->loadMissing(['counterparty', 'category'])) + ['previous_status' => $prev]);
            }
        });
        \App\Models\Task::created(fn ($m) => $emit($m->workspace_id, 'task.created', fn () => $P::task($m->loadMissing('assignee'))));
        \App\Models\Task::updated(function ($m) use ($emit, $P) {
            if ($m->wasChanged(['title', 'status', 'progress', 'assignee_id', 'due_date', 'priority', 'project_id'])) {
                $emit($m->workspace_id, 'task.updated', fn () => $P::task($m->loadMissing('assignee')) + ['changed' => array_values(array_intersect(array_keys($m->getChanges()),
                    ['title', 'status', 'progress', 'assignee_id', 'due_date', 'priority', 'project_id']))]);
            }
        });
    }
}
