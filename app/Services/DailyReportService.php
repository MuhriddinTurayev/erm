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
            // отчёт за день ищем по дате (в SQLite дата хранится с временем — поиск по строке «Y-m-d» его не находил и падал на уникальном ключе)
            $r = DailyReport::where('workspace_id', $ws->id)->whereDate('date', $stats['date'])->first();
            $summary = $r?->ai_summary;
            // ИИ — платный запрос: не чаще одного раза за день на компанию, даже если отчёт пересобирают или отправка не удалась
            if ($withAi && ! $summary && $ws->report_ai && $ws->aiAllowed()
                && \Illuminate\Support\Facades\Cache::add('report-ai:'.$ws->id.':'.$stats['date'], 1, now()->addDay())) {
                $summary = $this->aiSummary($ws, $stats);
            }
            if ($r) {
                $r->fill(['stats' => $stats, 'ai_summary' => $summary])->save();

                return $r;
            }

            return DailyReport::create(['workspace_id' => $ws->id, 'date' => $stats['date'], 'stats' => $stats, 'ai_summary' => $summary]);
        });
    }

    private function aiSummary(Workspace $ws, array $stats): ?string
    {
        $lang = ['ru' => 'русском', 'uz' => 'узбекском (латиница)', 'tg' => 'таджикском'][$ws->locale] ?? 'русском';
        $data = $stats;
        unset($data['days14']);
        $system = "Ты — финансовый аналитик строительной компании. По данным за день дай руководителю короткий вывод: 3–5 пунктов, каждый с новой строки, начиная с «• ». "
            ."Что важно: резкий рост расходов, крупные платежи, объекты с перерасходом или просрочкой, низкий остаток в кассе, что проверить завтра. "
            ."Только по данным, без выдумок, без вступления и заголовков, без markdown. Пиши на {$lang} языке. "
            // как в программе: «1 151 164 300 so'm», а не «1,151,164,300 сум»
            .'Суммы пиши полностью, цифрами, тысячи через пробел, без «млн»/«млрд»; валюту называй так: «'.__((string) $stats['currency'], [], $ws->locale).'».';
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
            $L[] = '💰 <b>'.__('Деньги на счету (касса)').': '.CompanyStats::money((float) $T['balance']).' '.__($s['currency']).'</b>';
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
                    $L[] = '• '.$m($t['amount']).' — '.$e($t['desc'] ? self::shown($t['desc']) : __($t['cat'])).($t['who'] ? ', '.$e($t['who']) : '').' <i>('.$e(self::shown((string) $t['obj'])).')</i>';
                }
            }
            if ($s['objects']) {
                $L[] = '';
                $L[] = '🏗 <b>'.__('Объекты').':</b>';
                foreach (array_slice($s['objects'], 0, 10) as $o) {
                    $L[] = '• <b>'.$e(self::shown((string) $o['name'])).'</b> — '.__('приход − расход').' '.$m($o['balance']).', '.__('сегодня').' −'.$m($o['out_day']).', '.__('готовность').' '.$o['progress'].'%'
                        .($o['overdue'] ? ' ⚠️ '.__('просрочено этапов').': '.$o['overdue'] : '');
                }
            }
            // снабжение: что заканчивается на объектах и какие заявки опаздывают
            if ($low = self::lowStock($ws)) {
                $L[] = '';
                $L[] = '⚠️ <b>'.__('Заканчиваются материалы').':</b>';
                foreach (array_slice($low, 0, 8) as $x) {
                    $L[] = '• '.$e($x['obj']).': '.$e($x['item']).' — '.$x['qty'].' '.$e($x['unit']).($x['days'] !== null ? ' (~'.__(':n дн.', ['n' => $x['days']]).')' : '');
                }
            }
            if ($late = self::lateSupply($ws, $s['date'])) {
                $L[] = '';
                $L[] = '🚚 <b>'.__('Материал опаздывает').':</b>';
                foreach ($late as $x) {
                    $L[] = '• №'.$x['number'].' · '.$e($x['obj']).' · '.__('нужно было к').' '.$x['need'].' · '.$x['status'].($x['who'] ? ' · '.$e($x['who']) : '');
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

    /**
     * Подписи, которые система сохранила по-русски («Заявка №3 · …», «Зарплата за Октябрь 2026», «… (копия)»), — на языке отчёта.
     * Вызывать внутри inLocale.
     */
    public static function shown(string $s): string
    {
        if ($s === '' || App::getLocale() === 'ru') {
            return $s;
        }
        if (($t = __($s)) !== $s) {
            return $t;
        }
        $s = (string) preg_replace_callback('/^Заявка №\s*(\S+)/u', fn ($m) => __('Заявка №:n', ['n' => $m[1]]), $s);
        $s = (string) preg_replace_callback('/^Зарплата за (\p{L}+)(?= |$)/u', function ($m) {
            $i = array_search(mb_strtolower($m[1]), ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'], true);
            if ($i === false) {
                return $m[0];
            }
            $mon = Carbon::create(2000, $i + 1, 1)->locale(App::getLocale() === 'uz' ? 'uz_Latn' : App::getLocale())->translatedFormat('F');

            return __('Зарплата за').' '.mb_convert_case($mon, MB_CASE_TITLE);
        }, $s);

        return str_replace('(копия)', __('(копия)'), $s);
    }

    /**
     * Материалы, которые заканчиваются на складах объектов: [['obj', 'item', 'qty', 'unit', 'days'], …]
     *
     * @return array<int, array{obj: string, item: string, qty: string, unit: string, days: ?int}>
     */
    public static function lowStock(Workspace $ws): array
    {
        $low = [];
        foreach (StateWriter::projects($ws) as $pid => $pn) {
            if (\App\Services\Bot\Catalog::warehouse($ws, (string) $pid)) {
                foreach (\App\Services\Bot\Catalog::stock($ws, (string) $pid) as $row) {
                    if ($row['low']) {
                        $low[] = ['obj' => self::shown((string) $pn), 'item' => (string) $row['item']->name, 'qty' => \App\Services\Bot\Catalog::n($row['qty']),
                            'unit' => (string) \App\Services\Erp\Supply::unit($row['item']->unit), 'days' => $row['days']];
                    }
                }
            }
        }

        return $low;
    }

    /**
     * Заявки на материалы, которые опаздывают к нужной дате
     *
     * @return array<int, array{number: string, obj: string, need: string, status: string, who: string}>
     */
    public static function lateSupply(Workspace $ws, string $date): array
    {
        return \App\Models\SupplyRequest::of($ws->id)->whereIn('status', \App\Services\Erp\Supply::RECEIVABLE)->whereNotNull('need_by')
            ->whereDate('need_by', '<', $date)->with('counterparty')->orderBy('need_by')->limit(6)->get()
            ->map(fn ($sr) => ['number' => (string) $sr->number, 'obj' => self::shown((string) (StateWriter::projects($ws)[$sr->project_id] ?? '')), 'need' => $sr->need_by->format('d.m'),
                'status' => __(\App\Services\Erp\Supply::STATUS[$sr->status] ?? $sr->status), 'who' => (string) ($sr->counterparty?->name ?? '')])->all();
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
        $tg = $chats && Telegram::enabled();
        // тот же отчёт файлами PDF и Word — с логотипами, чтобы распечатать или переслать
        $want = ($tg || $ws->report_email) && $ws->setting('report_files', true);
        $files = $want ? ReportDocs::files($ws, $r) : [];
        if ($want && count($files) < 2) {
            $errors[] = __('Не удалось собрать файлы отчёта (PDF/Word) — отправлен только текст');
        }
        if ($tg) {
            $ok = false;
            $caption = '📎 <b>'.e($ws->name).'</b> · '.__('Ежедневный отчёт').' '.\Illuminate\Support\Carbon::parse($r->stats['date'])->format('d.m.Y');
            foreach ($chats as $chat) {
                try {
                    Telegram::send($chat, $text);
                    $ok = true;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();

                    continue;
                }
                try {
                    Telegram::documents($chat, $files, $caption);
                } catch (\Throwable $e) {
                    $errors[] = __('Файлы отчёта').': '.$e->getMessage();
                }
            }
            $r->sent_telegram = $ok;
        }
        if ($ws->report_email) {
            try {
                $html = '<div style="font:15px/1.6 Arial,sans-serif">'.nl2br($text).'</div>';
                Mail::html($html, function ($m) use ($ws, $text, $files) {
                    $m->to($ws->report_email)->subject(strip_tags(strtok($text, "\n")));
                    foreach ($files as $name => $bytes) {
                        $m->attachData($bytes, $name, ['mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
                    }
                });
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
            // после ошибки — пауза 30 минут, а не новая попытка каждую минуту
            if (! \Illuminate\Support\Facades\Cache::add('report-try:'.$ws->id.':'.$date, 1, now()->addMinutes(30))) {
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
