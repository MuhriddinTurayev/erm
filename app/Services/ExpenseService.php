<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/** Расходы из Telegram и с сайта: разбор текста, согласование руководителем, проведение в бухгалтерию */
class ExpenseService
{
    /** Статьи расходов (как в программе) и слова, по которым их узнаём */
    public const CATS = [
        'Материалы' => ['бетон', 'beton', 'арматур', 'armatur', 'кирпич', "g'isht", 'gisht', 'блок', 'blok', 'цемент', 'sement', 'tsement', 'песок', 'qum', 'щебен', 'shag', 'доск', 'taxta', 'брус', 'материал', 'material', 'кабел', 'kabel', 'труб', 'quvur', 'краск', "bo'yoq", 'гипс', 'gips', 'плитк', 'kafel', 'окн', 'deraza', 'мавод', 'хишт', 'семент', 'регу'],
        'Техника и топливо' => ['солярк', 'solyarka', 'дизел', 'dizel', 'бензин', 'benzin', 'топлив', "yoqilg'i", 'yoqilgi', 'кран', 'kran', 'экскаватор', 'ekskavator', 'самосвал', 'samosval', 'аренда техники', 'техника', 'texnika', 'сӯзишворӣ', 'газ', 'propan', 'metan'],
        'Зарплата рабочих' => ['зарплат', 'maosh', 'ish haqi', 'oylik', 'аванс', 'avans', 'бригад', 'brigada', 'рабоч', 'ishchi', 'музд', 'мардикор'],
        'Субподряд' => ['субподряд', 'subpudrat', 'подряд', 'pudrat'],
        'Проект и разрешения' => ['проект', 'loyiha', 'разрешен', 'ruxsat', 'экспертиз', 'ekspertiza', 'хокимият', 'hokimiyat'],
        'Налоги и сборы' => ['налог', 'soliq', 'сбор', "yig'im", 'андоз'],
        'Подготовительные работы' => ['ограждени', "to'siq", 'бытовк', 'вагончик', 'vagon', 'забор', 'zabor'],
        'Котлован и грунт' => ['котлован', 'kotlovan', 'грунт', 'grunt', 'вывоз', 'чуқурӣ'],
    ];

    /**
     * «Бетон 25 млн БетонСтрой», «armatura 1,2 mlrd», «солярка 850000 АЗС» → сумма, что купили, кому.
     *
     * @return array{amount: float, desc: string, who: string}|null
     */
    public static function parse(string $text): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $units = '(млрд|mlrd|миллиард|млн|mln|million|миллион|тыс|ming|ming\.|k|к|ҳазор)';
        $rx = '/(?<![\w.,])(\d[\d\s]*(?:[.,]\d+)?)\s*'.$units.'?(?:\s*(?:сум|so\'m|som|сӯм|сомонӣ|uzs))?(?![\w])/iu';
        if (! preg_match_all($rx, $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }
        // берём самое «крупное» число — это сумма (а не «10 мешков»)
        $best = null;
        foreach ($all as $m) {
            $num = (float) str_replace([' ', ','], ['', '.'], $m[1][0]);
            $u = mb_strtolower($m[2][0] ?? '');
            $mult = match (true) {
                in_array($u, ['млрд', 'mlrd', 'миллиард'], true) => 1e9,
                in_array($u, ['млн', 'mln', 'million', 'миллион'], true) => 1e6,
                in_array($u, ['тыс', 'ming', 'ming.', 'k', 'к', 'ҳазор'], true) => 1e3,
                default => 1,
            };
            $val = $num * $mult;
            if (! $best || $val > $best['amount']) {
                $best = ['amount' => $val, 'start' => mb_strlen(substr($text, 0, $m[0][1])), 'len' => mb_strlen($m[0][0])];
            }
        }
        if (! $best || $best['amount'] < 1000) {
            return null;
        }
        // не trim(): он режет по байтам, и «—» в списке символов обрезал бы хвост буквы («Бетонмастер» → «Бетонмасте�»)
        $cut = fn (string $s) => preg_replace('/^[\s,.\-—:;]+|[\s,.\-—:;]+$/u', '', $s);
        $before = $cut(mb_substr($text, 0, $best['start']));
        $after = $cut(mb_substr($text, $best['start'] + $best['len']));

        return ['amount' => round($best['amount']), 'desc' => $before !== '' ? $before : $after, 'who' => $before !== '' ? $after : ''];
    }

    public static function guessCategory(string $text): string
    {
        $t = mb_strtolower($text);
        foreach (self::CATS as $cat => $words) {
            foreach ($words as $w) {
                if (str_contains($t, $w)) {
                    return $cat;
                }
            }
        }

        return 'Офис и прочее';
    }

    public static function needsApproval(Workspace $ws, ?User $user, float $amount): bool
    {
        return $ws->approval_limit > 0 && $amount >= $ws->approval_limit && ! ($user && $user->isAdmin());
    }

    /** Новый расход: сразу в бухгалтерию или на согласование */
    public function create(Workspace $ws, ?User $user, string $projectId, array $d, string $source = 'web', ?string $receipt = null): Expense
    {
        $e = Expense::create([
            'workspace_id' => $ws->id, 'project_id' => $projectId, 'user_id' => $user?->id, 'source' => $source,
            'date' => $d['date'] ?? $ws->localNow()->toDateString(), 'amount' => $d['amount'], 'cat' => $d['cat'] ?? self::guessCategory(($d['desc'] ?? '').' '.($d['who'] ?? '')),
            'desc' => mb_substr((string) ($d['desc'] ?? ''), 0, 300), 'who' => mb_substr((string) ($d['who'] ?? ''), 0, 120), 'receipt' => $receipt, 'status' => 'pending',
        ]);
        if (self::needsApproval($ws, $user, $e->amount)) {
            $this->notifyApprovers($ws, $e);
        } else {
            $this->post($e, $user);
        }

        return $e->fresh();
    }

    /** Провести в бухгалтерию объекта */
    public function post(Expense $e, ?User $by): void
    {
        $txId = StateWriter::addTx($e->workspace_id, $e->project_id, [
            'date' => $e->date->toDateString(), 'type' => 'out', 'cat' => $e->cat, 'amount' => (float) $e->amount,
            'who' => (string) $e->who, 'desc' => (string) $e->desc, 'receipt' => $e->receiptUrl(), 'link' => ['kind' => 'exp', 'id' => $e->id],
        ]);
        $e->forceFill(['tx_id' => $txId, 'status' => 'approved', 'decided_by' => $by?->id, 'decided_at' => now()])->save();
        $proj = StateWriter::projects(Workspace::find($e->workspace_id))[$e->project_id] ?? '';
        AuditLog::create(['workspace_id' => $e->workspace_id, 'user_id' => $by?->id ?? $e->user_id, 'project' => $proj, 'action' => 'tx.add',
            'summary' => ($e->source === 'telegram' ? 'Telegram: ' : '').'расход −'.number_format($e->amount, 0, '.', ' ').' · '.$e->cat.($e->desc ? ' · '.$e->desc : '').($e->who ? ' · '.$e->who : '')]);
    }

    /**
     * Занять решение по расходу: только один из одновременных запросов (Telegram и сайт, двойное нажатие) получит true.
     * Атомарное обновление pending → $status, поэтому расход не попадёт в бухгалтерию дважды.
     */
    private function claim(Expense $e, string $status, User $by, ?string $reason = null): bool
    {
        if ((int) $by->workspace_id !== (int) $e->workspace_id || ! $by->isAdmin()) {
            return false;
        }

        return Expense::whereKey($e->id)->where('status', 'pending')
            ->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'reason' => $reason, 'updated_at' => now()]) === 1;
    }

    public function approve(Expense $e, User $by): bool
    {
        if (! $this->claim($e, 'approving', $by)) {
            return false;
        }
        try {
            $this->post($e->fresh(), $by);
        } catch (\Throwable $ex) {
            Expense::whereKey($e->id)->update(['status' => 'pending', 'decided_by' => null, 'decided_at' => null]);   // не провелось — вернуть на согласование
            throw $ex;
        }
        $this->afterDecision($e->fresh(), $by);

        return true;
    }

    public function reject(Expense $e, User $by, ?string $reason = null): bool
    {
        if (! $this->claim($e, 'rejected', $by, $reason)) {
            return false;
        }
        $e = $e->fresh();
        AuditLog::create(['workspace_id' => $e->workspace_id, 'user_id' => $by->id, 'project' => StateWriter::projects(Workspace::find($e->workspace_id))[$e->project_id] ?? '',
            'action' => 'exp.reject', 'summary' => 'Отклонён расход −'.number_format($e->amount, 0, '.', ' ').' · '.$e->desc.($reason ? ' · '.$reason : '')]);
        $this->afterDecision($e->fresh(), $by);

        return true;
    }

    /** Текст карточки расхода (на языке получателя) */
    public static function card(Expense $e, Workspace $ws): string
    {
        $proj = StateWriter::projects($ws)[$e->project_id] ?? '—';
        $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $status = ['pending' => '⏳ '.__('Ждёт согласования'), 'approved' => '✅ '.__('Согласовано и проведено'), 'rejected' => '❌ '.__('Отклонено')][$e->status] ?? '';

        return '💸 <b>'.CompanyStats::money($e->amount).' '.$h(__(CompanyStats::currency($ws))).'</b> · '.$h(__($e->cat))."\n"
            .($e->desc ? $h($e->desc)."\n" : '').($e->who ? __('Кому').': '.$h($e->who)."\n" : '')
            .'🏗 '.$h($proj).' · '.$e->date->format('d.m.Y')."\n"
            .'👤 '.$h($e->user?->name ?? '—').($e->receipt ? ' · 🧾 '.__('есть чек') : '')."\n"
            .$status.($e->decider && $e->status !== 'pending' ? ' — '.$h($e->decider->name) : '').($e->reason ? "\n".__('Причина').': '.$h($e->reason) : '');
    }

    /** Руководителям — карточка с кнопками «Согласовать / Отклонить» */
    private function notifyApprovers(Workspace $ws, Expense $e): void
    {
        if (! Telegram::enabled()) {
            return;
        }
        $msgs = [];
        foreach ($ws->users()->where('role', 'admin')->whereNotNull('telegram_chat_id')->get() as $admin) {
            $this->inLocale($admin->locale ?: $ws->locale, function () use ($admin, $e, $ws, &$msgs) {
                try {
                    $r = Telegram::message($admin->telegram_chat_id, "🔔 <b>".__('Нужно согласовать расход')."</b>\n\n".self::card($e, $ws), [[
                        ['text' => '✅ '.__('Согласовать'), 'data' => 'ap:'.$e->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'rj:'.$e->id],
                    ], [['text' => __('Открыть на сайте'), 'url' => url('/company/approvals')]]]);
                    $msgs[] = ['chat' => $admin->telegram_chat_id, 'msg' => (int) ($r['message_id'] ?? 0), 'locale' => $admin->locale ?: $ws->locale];
                } catch (\Throwable $ex) {
                    Log::warning('Approval notify: '.$ex->getMessage());
                }
            });
        }
        $e->forceFill(['tg' => $msgs])->save();
    }

    /** Обновить карточки у руководителей и сообщить автору */
    private function afterDecision(Expense $e, User $by): void
    {
        if (! Telegram::enabled()) {
            return;
        }
        $ws = Workspace::find($e->workspace_id);
        foreach ((array) $e->tg as $m) {
            $this->inLocale($m['locale'] ?? $ws->locale, fn () => Telegram::edit($m['chat'], (int) $m['msg'], self::card($e, $ws)));
        }
        $author = $e->user;
        if ($author && $author->telegram_chat_id && $author->id !== $by->id) {
            $this->inLocale($author->locale ?: $ws->locale, function () use ($author, $e, $ws) {
                try {
                    Telegram::message($author->telegram_chat_id, self::card($e, $ws));
                } catch (\Throwable) {
                }
            });
        }
    }

    private function inLocale(?string $locale, callable $fn)
    {
        $prev = App::getLocale();
        App::setLocale($locale ?: 'ru');
        try {
            return $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
