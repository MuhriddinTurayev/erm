<?php

namespace App\Services\Erp;

use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StateWriter;
use App\Services\Telegram;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Заявки на оплату: заявка → согласующие по цепочке ролей (например, бухгалтер → руководитель)
 * → оплата из выбранной кассы. Согласовать можно на сайте или одной кнопкой в Telegram.
 */
class Payments
{
    public static function create(Workspace $ws, User $by, array $d): PaymentRequest
    {
        // договор задаёт контрагента; заблокированному контрагенту заявку не создаём
        if (! empty($d['contract_id'])) {
            $c = \App\Models\Contract::of($ws->id)->findOrFail($d['contract_id']);
            $d['counterparty_id'] = $c->counterparty_id;
        }
        if (! empty($d['counterparty_id'])) {
            $cp = \App\Models\Counterparty::of($ws->id)->findOrFail($d['counterparty_id']);
            if ($cp->status === 'blocked') {
                throw new \DomainException(__('Контрагент «:n» заблокирован: :r', ['n' => $cp->name, 'r' => $cp->status_reason ?: '—']));
            }
        }
        $pr = DB::transaction(function () use ($ws, $by, $d) {
            $num = (int) PaymentRequest::of($ws->id)->lockForUpdate()->max('number') + 1;
            // автор заявки не согласует сам себя: его роль в цепочке пропускается
            // и роли, которых в компании нет, тоже — иначе заявка зависнет
            $roles = $ws->users()->where('is_active', true)->pluck('role')->unique()->all();
            $chain = array_values(array_filter($ws->approvalChain(), fn ($r) => $r === 'admin' || ($r !== $by->role && in_array($r, $roles, true))));
            if (! in_array('admin', $chain, true) && ! $chain) {
                $chain = ['admin'];
            }
            if ($by->isAdmin()) {
                $chain = [];
            }

            return PaymentRequest::create($d + ['workspace_id' => $ws->id, 'number' => $num, 'user_id' => $by->id, 'chain' => $chain,
                'step' => 0, 'status' => $chain ? 'pending' : 'approved']);
        });
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $by->id, 'project' => $pr->project_id ? (StateWriter::projects($ws)[$pr->project_id] ?? null) : null,
            'action' => 'pay.add', 'summary' => 'Заявка на оплату №'.$pr->number.' · '.Money::fmt($pr->amount, $pr->currency).' · '.$pr->purpose]);
        self::notify($ws, $pr);

        return $pr;
    }

    /** Роль, которая должна согласовать сейчас */
    public static function currentRole(PaymentRequest $pr): ?string
    {
        return $pr->status === 'pending' ? ($pr->chain[$pr->step] ?? null) : null;
    }

    /** Может ли пользователь принять решение по заявке сейчас */
    public static function canDecide(User $u, PaymentRequest $pr): bool
    {
        if ($pr->status !== 'pending' || (int) $u->workspace_id !== (int) $pr->workspace_id || ! $u->canEdit()) {
            return false;
        }
        if ($u->isAdmin()) {
            return true;
        }
        $only = $u->allowedObjects();
        if ($only !== null && $pr->project_id && ! in_array($pr->project_id, $only, true)) {
            return false;
        }

        return self::currentRole($pr) === $u->role && $pr->user_id !== $u->id;
    }

    public static function decide(PaymentRequest $pr, User $u, bool $approve, ?string $comment = null): bool
    {
        // решение принимается под блокировкой: нажатие в Telegram и на сайте одновременно не продвинет заявку дважды
        $done = DB::transaction(function () use (&$pr, $u, $approve, $comment) {
            $fresh = PaymentRequest::whereKey($pr->id)->lockForUpdate()->first();
            if (! $fresh || ! self::canDecide($u, $fresh)) {
                return false;
            }
            $pr = $fresh;
            Approval::create(['payment_request_id' => $pr->id, 'step' => $pr->step, 'user_id' => $u->id, 'decision' => $approve ? 'approve' : 'reject', 'comment' => $comment]);
            if (! $approve) {
                $pr->status = 'rejected';
            } elseif ($u->isAdmin() || $pr->step + 1 >= count((array) $pr->chain)) {
                $pr->status = 'approved';   // руководитель утверждает окончательно
            } else {
                $pr->step++;
            }
            $pr->save();

            return true;
        });
        if (! $done) {
            return false;
        }
        AuditLog::create(['workspace_id' => $pr->workspace_id, 'user_id' => $u->id, 'action' => $approve ? 'pay.edit' : 'pay.del',
            'summary' => 'Заявка №'.$pr->number.': '.($approve ? ($pr->status === 'approved' ? 'утверждена' : 'согласована, далее — '.__(config('oftob.roles.'.self::currentRole($pr)))) : 'отклонена').($comment ? ' · '.$comment : '')]);
        self::refreshCards($pr);
        if ($pr->status === 'pending') {
            self::notify(Workspace::find($pr->workspace_id), $pr);
        } else {
            self::tellAuthor($pr);
        }

        return true;
    }

    /** Оплатить одобренную заявку из кассы */
    public static function pay(Workspace $ws, User $by, PaymentRequest $pr, int $deskId, ?string $date = null): void
    {
        abort_unless((int) $pr->workspace_id === (int) $ws->id, 404);
        $desk = \App\Models\CashDesk::of($ws->id)->findOrFail($deskId);
        DB::transaction(function () use ($ws, $by, $pr, $desk, $date) {
            // блокируем заявку и проверяем статус ещё раз: двойное нажатие или два кассира одновременно — оплата одна
            $fresh = PaymentRequest::whereKey($pr->id)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'approved') {
                throw new \DomainException(__('Заявка уже оплачена или ещё не утверждена'));
            }
            if ($fresh->counterparty_id && \App\Models\Counterparty::whereKey($fresh->counterparty_id)->value('status') === 'blocked') {
                throw new \DomainException(__('Контрагент заблокирован — оплата запрещена'));
            }
            $amount = $fresh->amount;
            if ($desk->currency !== $fresh->currency) {   // заявка в $, касса в сумах — и наоборот
                $base = Money::toBase($ws, $fresh->amount, $fresh->currency);
                $amount = round($base / Money::rate($ws, $desk->currency), 2);
            }
            $op = Cash::add($ws, $by, ['cash_desk_id' => $desk->id, 'type' => 'out', 'amount' => $amount, 'date' => $date, 'category_id' => $fresh->category_id,
                'project_id' => $fresh->project_id, 'counterparty_id' => $fresh->counterparty_id, 'contract_id' => $fresh->contract_id, 'payment_request_id' => $fresh->id,
                'note' => 'Заявка №'.$fresh->number.' · '.$fresh->purpose]);
            $fresh->forceFill(['status' => 'paid', 'cash_op_id' => $op->id])->save();
        });
        self::tellAuthor($pr->fresh());
    }

    /* ---------------- Telegram ---------------- */

    public static function card(PaymentRequest $pr): string
    {
        $ws = Workspace::find($pr->workspace_id);
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $st = ['pending' => '⏳ '.__('на согласовании'), 'approved' => '✅ '.__('утверждена'), 'rejected' => '❌ '.__('отклонена'), 'paid' => '💵 '.__('оплачена'), 'cancelled' => __('отменена')][$pr->status] ?? $pr->status;
        $lines = ['<b>'.__('Заявка на оплату').' №'.$pr->number.'</b> — '.$st, '',
            '💰 <b>'.$h(Money::fmt($pr->amount, $pr->currency)).'</b>', '📝 '.$h($pr->purpose)];
        if ($pr->project_id) {
            $lines[] = '🏗 '.$h(StateWriter::projects($ws)[$pr->project_id] ?? $pr->project_id);
        }
        if ($pr->counterparty) {
            $lines[] = '🤝 '.$h($pr->counterparty->name).($pr->counterparty->status === 'blocked' || $pr->counterparty->status === 'suspicious' ? ' ⚠️ '.__('проблемный контрагент') : '');
        }
        if ($pr->category) {
            $lines[] = '🏷 '.$h(__($pr->category->name));
        }
        if ($pr->due_date) {
            $lines[] = '📅 '.__('Оплатить до').' '.$pr->due_date->format('d.m.Y');
        }
        $lines[] = '👤 '.$h($pr->user?->name ?? '—');
        foreach ($pr->approvals()->with('user')->orderBy('id')->get() as $a) {
            $lines[] = ($a->decision === 'approve' ? '✔️ ' : '✖️ ').$h($a->user?->name).($a->comment ? ' — '.$h($a->comment) : '');
        }

        return implode("\n", $lines);
    }

    /** Сообщить тем, кто должен согласовать сейчас */
    public static function notify(Workspace $ws, PaymentRequest $pr): void
    {
        if ($pr->status !== 'pending' || ! Telegram::enabled()) {
            return;
        }
        $role = self::currentRole($pr);
        $msgs = (array) $pr->tg;
        $find = fn (string $r) => $ws->users()->where('is_active', true)->whereNotNull('telegram_chat_id')->where('id', '!=', $pr->user_id)
            ->where('role', $r)->get()->filter(fn (User $u) => self::canDecide($u, $pr));
        $to = $find($role);
        if ($to->isEmpty() && $role !== 'admin') {
            $to = $find('admin');   // согласующего с такой ролью нет в Telegram — решает руководитель
        }
        foreach ($to as $u) {
            self::inLocale($u->botLocale(), function () use ($u, $pr, &$msgs) {
                try {
                    $r = Telegram::message($u->telegram_chat_id, '🔔 <b>'.__('Нужно согласовать оплату')."</b>\n\n".self::card($pr), [[
                        ['text' => '✅ '.__('Согласовать'), 'data' => 'pa:'.$pr->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'pn:'.$pr->id],
                    ], [['text' => __('Открыть на сайте'), 'url' => url('/company/payments/'.$pr->id)]]]);
                    $msgs[] = ['chat' => $u->telegram_chat_id, 'msg' => (int) ($r['message_id'] ?? 0), 'locale' => $u->botLocale()];
                } catch (\Throwable $e) {
                    Log::warning('Payment notify: '.$e->getMessage());
                }
            });
        }
        $pr->forceFill(['tg' => $msgs])->saveQuietly();
    }

    private static function refreshCards(PaymentRequest $pr): void
    {
        if (! Telegram::enabled()) {
            return;
        }
        foreach ((array) $pr->tg as $m) {
            self::inLocale($m['locale'] ?? 'ru', function () use ($m, $pr) {
                try {
                    Telegram::edit($m['chat'], (int) $m['msg'], self::card($pr));
                } catch (\Throwable) {
                }
            });
        }
    }

    private static function tellAuthor(PaymentRequest $pr): void
    {
        $a = $pr->user;
        if (! Telegram::enabled() || ! $a?->telegram_chat_id) {
            return;
        }
        self::inLocale($a->botLocale(), function () use ($a, $pr) {
            try {
                Telegram::message($a->telegram_chat_id, self::card($pr));
            } catch (\Throwable) {
            }
        });
    }

    private static function inLocale(?string $locale, callable $fn): void
    {
        $prev = App::getLocale();
        App::setLocale($locale ?: 'ru');
        try {
            $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
