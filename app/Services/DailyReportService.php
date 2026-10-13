<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\PlatformSetting;
use App\Models\Workspace;
use App\Services\Ai\AiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Ежедневный отчёт руководителю: сколько денег, сколько ушло сегодня и вчера, на что, по объектам — в Telegram и на почту */
class DailyReportService
{
    public function __construct(private AiService $ai) {}

    /** Собрать (или пересобрать) отчёт за день */
    public function generate(Workspace $ws, ?string $date = null, bool $withAi = true): DailyReport
    {
        return $this->inLocale($ws, function () use ($ws, $date, $withAi) {
            $stats = CompanyStats::build($ws, $date);
            $summary = null;
            if ($withAi && $ws->report_ai && $ws->aiAllowed()) {
                $summary = $this->aiSummary($ws, $stats);
            }

            return DailyReport::updateOrCreate(['workspace_id' => $ws->id, 'date' => $stats['date']], ['stats' => $stats, 'ai_summary' => $summary]);
        });
    }

    private function aiSummary(Workspace $ws, array $stats): ?string
    {
        $lang = ['ru' => 'русском', 'uz' => 'узбекском (латиница)', 'tg' => 'таджикском'][$ws->locale] ?? 'русском';
        $data = $stats;
        unset($data['days14']);
        $system = "Ты — финансовый аналитик строительной компании. По данным за день дай руководителю короткий вывод: 3–5 пунктов, каждый с новой строки, начиная с «• ». "
            ."Что важно: резкий рост расходов, крупные платежи, объекты с перерасходом или просрочкой, низкий остаток в кассе, что проверить завтра. "
            ."Только по данным, без выдумок, без вступления и заголовков, без markdown. Пиши на {$lang} языке. Суммы — в {$stats['currency']}.";
        try {
            return trim($this->ai->ask($ws, null, 'report', $system, [['role' => 'user', 'content' => json_encode($data, JSON_UNESCAPED_UNICODE)]], null, 900)['text']) ?: null;
        } catch (\Throwable $e) {
            Log::warning('Daily report AI: '.$e->getMessage());

            return null;
        }
    }

    /** Текст отчёта (HTML-разметка Telegram) */
    public function text(Workspace $ws, DailyReport $r): string
    {
        return $this->inLocale($ws, function () use ($ws, $r) {
            $s = $r->stats;
            $T = $s['totals'];
            $c = ' '.__($s['currency']);
            $m = fn ($v) => CompanyStats::short((float) $v).$c;
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5);
            $L = [];
            $L[] = '☀️ <b>'.$e(PlatformSetting::brand()).' — '.__('отчёт за').' '.Carbon::parse($s['date'])->format('d.m.Y').'</b>';
            $L[] = '🏢 '.$e($ws->name);
            $L[] = '';
            $L[] = '💰 <b>'.__('Деньги на счету (касса)').': '.$m($T['balance']).'</b>';
            $diff = $s['diff_pct'] === null ? '' : ' ('.($s['diff_pct'] >= 0 ? '▲ +' : '▼ ').$s['diff_pct'].'% '.__('к вчера').')';
            $L[] = '📉 '.__('Потрачено сегодня').': <b>'.$m($T['out_day']).'</b>'.$diff;
            $L[] = '📉 '.__('Потрачено вчера').': '.$m($T['out_yest']);
            $L[] = '📈 '.__('Поступило сегодня').': '.$m($T['in_day']);
            $L[] = '🗓 '.__('За 7 дней').': −'.$m($T['out_week']).' · '.__('за месяц').': −'.$m($T['out_month']).' / +'.$m($T['in_month']);
            if ($s['cats_day']) {
                $L[] = '';
                $L[] = '🧾 <b>'.__('На что ушли деньги сегодня').':</b>';
                foreach (array_slice($s['cats_day'], 0, 6, true) as $cat => $v) {
                    $L[] = '• '.$e(__($cat)).' — '.$m($v).' ('.round($v / max(1, $T['out_day']) * 100).'%)';
                }
            } else {
                $L[] = '';
                $L[] = '🧾 '.__('Сегодня расходов не было');
            }
            if ($s['top']) {
                $L[] = '';
                $L[] = '🔝 <b>'.__('Крупные расходы').':</b>';
                foreach (array_slice($s['top'], 0, 5) as $t) {
                    $L[] = '• '.$m($t['amount']).' — '.$e($t['desc'] ?: __($t['cat'])).($t['who'] ? ', '.$e($t['who']) : '').' <i>('.$e($t['obj']).')</i>';
                }
            }
            if ($s['objects']) {
                $L[] = '';
                $L[] = '🏗 <b>'.__('Объекты').':</b>';
                foreach (array_slice($s['objects'], 0, 10) as $o) {
                    $L[] = '• <b>'.$e($o['name']).'</b> — '.__('касса').' '.$m($o['balance']).', '.__('сегодня').' −'.$m($o['out_day']).', '.__('готовность').' '.$o['progress'].'%'
                        .($o['overdue'] ? ' ⚠️ '.__('просрочено этапов').': '.$o['overdue'] : '');
                }
            }
            if ($r->ai_summary) {
                $L[] = '';
                $L[] = '🤖 <b>'.__('Вывод ИИ').':</b>';
                $L[] = $e($r->ai_summary);
            }
            $L[] = '';
            $L[] = '🔗 '.url('/company/reports/'.$r->id);

            return implode("\n", $L);
        });
    }

    /** Отправить руководителю. Возвращает список ошибок (пустой — всё ушло) */
    public function send(Workspace $ws, DailyReport $r): array
    {
        return $this->inLocale($ws, fn () => $this->deliver($ws, $r));
    }

    private function deliver(Workspace $ws, DailyReport $r): array
    {
        $errors = [];
        $text = $this->text($ws, $r);
        $chats = array_filter(array_map('trim', explode(',', (string) $ws->report_chats)));
        if ($chats && Telegram::enabled()) {
            $ok = false;
            foreach ($chats as $chat) {
                try {
                    Telegram::send($chat, $text);
                    $ok = true;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
            $r->sent_telegram = $ok;
        }
        if ($ws->report_email) {
            try {
                $html = '<div style="font:15px/1.6 Arial,sans-serif">'.nl2br($text).'</div>';
                Mail::html($html, fn ($m) => $m->to($ws->report_email)->subject(strip_tags(strtok($text, "\n"))));
                $r->sent_email = true;
            } catch (\Throwable $e) {
                $errors[] = 'Email: '.$e->getMessage();
            }
        }
        if (! $chats && ! $ws->report_email) {
            $errors[] = __('Не указано, куда отправлять отчёт: подключите Telegram или укажите email');
        }
        $r->error = $errors ? mb_substr(implode('; ', $errors), 0, 500) : null;
        $r->sent_at = now();
        $r->save();

        return $errors;
    }

    /** Вызывается планировщиком каждую минуту: отправляет отчёты, у которых наступило время */
    public function runDue(): int
    {
        $n = 0;
        Workspace::with('plan')->where('report_enabled', true)->get()->each(function (Workspace $ws) use (&$n) {
            if (! $ws->hasAccess() || ($ws->plan && ! $ws->plan->daily_report)) {
                return;
            }
            $now = $ws->localNow();
            if ($now->format('H:i') < ($ws->report_time ?: '20:00')) {
                return;
            }
            $date = $now->toDateString();
            if (DailyReport::where('workspace_id', $ws->id)->whereDate('date', $date)->whereNotNull('sent_at')->exists()) {
                return;
            }
            try {
                $this->send($ws, $this->generate($ws, $date));
                $n++;
            } catch (\Throwable $e) {
                Log::error('Daily report for workspace '.$ws->id.': '.$e->getMessage());
            }
        });

        return $n;
    }

    private function inLocale(Workspace $ws, callable $fn)
    {
        $prev = App::getLocale();
        App::setLocale($ws->locale ?: 'ru');
        try {
            return $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
