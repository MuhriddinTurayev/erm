<?php

namespace App\Services;

use App\Models\BuyerLink;
use App\Models\PaymentRequest;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Ежедневные напоминания в Telegram (раз в день, в 10:00 по времени компании):
 * покупателям — о платеже по рассрочке за 3 дня и в день оплаты, при просрочке;
 * исполнителям — о задачах со сроком сегодня и просроченных;
 * бухгалтерии — об утверждённых заявках со сроком оплаты сегодня.
 */
class Reminders
{
    public function run(): int
    {
        if (! Telegram::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (Workspace::all() as $ws) {
            if (! $ws->hasAccess() || $ws->localNow()->format('H') !== '10') {
                continue;
            }
            $today = $ws->localNow()->toDateString();
            if ($ws->setting('reminded_on') === $today) {
                continue;
            }
            $ws->putSetting('reminded_on', $today);
            $n += $this->buyers($ws, $today) + $this->tasks($ws, $today) + $this->payments($ws, $today);
        }

        return $n;
    }

    private function buyers(Workspace $ws, string $today): int
    {
        $n = 0;
        $cur = CompanyStats::currency($ws);
        foreach (BuyerLink::where('workspace_id', $ws->id)->whereNotNull('tg_chat_id')->get() as $bl) {
            $p = StateWriter::project($ws, $bl->project_id);
            $a = $p ? Apartments::find($p, $bl->apt_id) : null;
            if (! $a || ! in_array($a['status'], ['sold', 'booked'], true)) {
                continue;
            }
            $next = Apartments::nextPayment($a);
            $overdue = Apartments::overdue($a, $today);
            $days = $next ? (int) now()->parse($today)->diffInDays($next['date'], false) : null;
            if (! $overdue && ! in_array($days, [3, 0], true)) {
                continue;
            }
            $this->inLocale($ws->locale, function () use ($bl, $ws, $p, $a, $next, $overdue, $days, $cur, &$n) {
                $text = '🏠 <b>'.e($p['name'] ?? '').', '.__('Кв.').' '.e($a['num']).'</b>'."\n\n";
                if ($overdue > 0) {
                    $text .= '⚠️ '.__('Просрочено к оплате: :s.', ['s' => money_s($overdue, $cur)])."\n";
                }
                if ($next && $days !== null && $days >= 0) {
                    $text .= '📅 '.($days === 0 ? __('Сегодня день оплаты') : __('Через :n дня платёж по графику', ['n' => $days])).': <b>'.money_s($next['amount'], $cur).'</b>';
                }
                $text .= "\n\n".e($ws->name).($ws->phone ? ' · '.e($ws->phone) : '');
                try {
                    Telegram::message($bl->tg_chat_id, $text, [[['text' => __('Мой кабинет'), 'url' => $bl->url()]]]);
                    $bl->forceFill(['last_reminded' => now()])->save();
                    $n++;
                } catch (\Throwable $e) {
                    Log::info('Buyer reminder: '.$e->getMessage());
                }
            });
        }

        return $n;
    }

    private function tasks(Workspace $ws, string $today): int
    {
        $n = 0;
        $byUser = Task::of($ws->id)->where('status', '!=', 'done')->whereNotNull('assignee_id')->whereDate('due_date', '<=', $today)->with('assignee')->get()->groupBy('assignee_id');
        foreach ($byUser as $tasks) {
            $u = $tasks->first()->assignee;
            if (! $u?->telegram_chat_id || ! $u->is_active) {
                continue;
            }
            $this->inLocale($u->locale ?: $ws->locale, function () use ($u, $tasks, $today, &$n) {
                $lines = $tasks->take(10)->map(fn ($t) => ($t->due_date->toDateString() < $today ? '🔴 ' : '🟡 ').e($t->title).' — '.$t->due_date->format('d.m'))->implode("\n");
                try {
                    Telegram::message($u->telegram_chat_id, '📌 <b>'.__('Задачи на сегодня и просроченные').'</b>'."\n\n".$lines, [[['text' => __('Открыть задачи'), 'url' => url('/company/tasks?who=me')]]]);
                    $n++;
                } catch (\Throwable) {
                }
            });
        }

        return $n;
    }

    private function payments(Workspace $ws, string $today): int
    {
        $due = PaymentRequest::of($ws->id)->where('status', 'approved')->whereDate('due_date', '<=', $today)->get();
        if ($due->isEmpty()) {
            return 0;
        }
        $n = 0;
        foreach ($ws->users()->whereIn('role', ['admin', 'accountant', 'cashier'])->where('is_active', true)->whereNotNull('telegram_chat_id')->get() as $u) {
            $this->inLocale($u->locale ?: $ws->locale, function () use ($u, $due, &$n) {
                $lines = $due->take(10)->map(fn ($r) => '№'.$r->number.' · '.e($r->purpose).' — <b>'.\App\Services\Erp\Money::fmt($r->amount, $r->currency).'</b>')->implode("\n");
                try {
                    Telegram::message($u->telegram_chat_id, '💵 <b>'.__('Сегодня нужно оплатить').'</b>'."\n\n".$lines, [[['text' => __('Заявки к оплате'), 'url' => url('/company/payments?tab=approved')]]]);
                    $n++;
                } catch (\Throwable) {
                }
            });
        }

        return $n;
    }

    private function inLocale(?string $l, callable $fn): void
    {
        $prev = App::getLocale();
        App::setLocale($l ?: 'ru');
        try {
            $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
