<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\PlatformSetting;
use App\Models\Workspace;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

/**
 * Ежедневный отчёт файлами: PDF (шрифт Inter внутри файла — выглядит одинаково везде) и Word (.docx).
 * В шапке — логотип платформы и логотип компании, кто прислал и кому, за какой день; дальше деньги, статьи, объекты, снабжение, вывод ИИ.
 */
class ReportDocs
{
    private const ACCENT = '6C4CF1';

    private const INK = '1E1B4B';

    private const MUTED = '6B7280';

    /** Всё содержимое отчёта уже на языке компании — одно для PDF и Word */
    public static function content(Workspace $ws, DailyReport $r): array
    {
        return self::inLocale($ws, function () use ($ws, $r) {
            $s = $r->stats;
            $T = $s['totals'];
            $cur = __((string) $s['currency']);
            $money = fn ($v) => CompanyStats::money((float) $v).' '.$cur;
            $d = Carbon::parse($s['date'])->locale(App::getLocale() === 'uz' ? 'uz_Latn' : App::getLocale());
            $outDay = max(1, (float) $T['out_day']);
            $days = [];
            foreach ((array) ($s['days14'] ?? []) as $k => $v) {
                $days[] = ['d' => Carbon::parse($k)->format('d.m'), 'v' => (float) $v, 'today' => $k === $s['date']];
            }
            $bosses = $ws->users()->where('role', 'admin')->where('is_active', true)->pluck('name')->all();
            $words = preg_split('/\s+/u', trim($ws->name)) ?: [];

            return [
                'brand' => PlatformSetting::brand(),
                'brandLogo' => resource_path('brand/logo-mark.png'),
                'companyLogo' => CompanyLogo::exists($ws) ? CompanyLogo::path($ws) : null,
                'initials' => mb_strtoupper(mb_substr($words[0] ?? 'O', 0, 1).mb_substr($words[1] ?? '', 0, 1)),
                'company' => $ws->name,
                'phone' => (string) $ws->phone,
                'title' => __('Ежедневный отчёт'),
                'date' => $d->format('d.m.Y'),
                'weekday' => mb_convert_case($d->translatedFormat('l'), MB_CASE_TITLE),
                'month' => mb_convert_case($d->translatedFormat('F Y'), MB_CASE_TITLE),
                'generated' => ($r->sent_at ? $r->sent_at->timezone($ws->timezone) : $ws->localNow())->format('d.m.Y H:i'),
                'from' => __('Автоматический отчёт платформы :b', ['b' => PlatformSetting::brand()]),
                'to' => $bosses ? __('Руководитель').': '.implode(', ', $bosses) : __('Руководитель компании'),
                'kpis' => [
                    ['l' => __('Деньги на счету (касса)'), 'v' => $money($T['balance']), 's' => $ws->name, 'hero' => true],
                    ['l' => __('Потрачено сегодня'), 'v' => $money($T['out_day']), 's' => __('вчера').' '.$money($T['out_yest'])
                        .($s['diff_pct'] === null ? '' : ' · '.($s['diff_pct'] >= 0 ? '▲ +' : '▼ ').$s['diff_pct'].'%')],
                    ['l' => __('Поступило сегодня'), 'v' => $money($T['in_day']), 's' => __('за месяц').' '.$money($T['in_month'])],
                    ['l' => __('Расходы месяца'), 'v' => $money($T['out_month']), 's' => __('за 7 дней').' '.$money($T['out_week'])],
                ],
                'days' => $days,
                'cats' => array_map(fn ($cat, $v) => ['n' => __((string) $cat), 'v' => $money($v), 'p' => (int) round($v / $outDay * 100)],
                    array_keys(array_slice($s['cats_day'], 0, 8, true)), array_slice($s['cats_day'], 0, 8, true)),
                'top' => array_map(fn ($t) => ['v' => $money($t['amount']), 'desc' => $t['desc'] ? DailyReportService::shown((string) $t['desc']) : __((string) $t['cat']), 'who' => (string) ($t['who'] ?? ''), 'obj' => DailyReportService::shown((string) ($t['obj'] ?? ''))],
                    array_slice((array) $s['top'], 0, 6)),
                // объекты с движением денег или стройки; пустые (черновики, тестовые) — одной строкой «ещё N без движения»
                'objects' => array_map(fn ($o) => ['n' => DailyReportService::shown((string) $o['name']), 'bal' => $money($o['balance']), 'day' => $money($o['out_day']), 'month' => $money($o['out_month']),
                    'p' => (int) ($o['progress'] ?? 0), 'late' => (int) ($o['overdue'] ?? 0)], array_slice($active = array_values(array_filter((array) $s['objects'], fn ($o) => self::moving($o))), 0, 12)),
                'objectsIdle' => count((array) $s['objects']) - min(12, count($active)),
                'low' => array_slice(DailyReportService::lowStock($ws), 0, 8),
                'late' => DailyReportService::lateSupply($ws, $s['date']),
                'cats_in' => array_map(fn ($cat, $v) => ['n' => __((string) $cat), 'v' => $money($v), 'p' => (int) round($v / max(1, (float) $T['in_day']) * 100)],
                    array_keys(array_slice((array) ($s['cats_in_day'] ?? []), 0, 8, true)), array_slice((array) ($s['cats_in_day'] ?? []), 0, 8, true)),
                'top_in' => array_map(fn ($t) => ['v' => $money($t['amount']), 'desc' => $t['desc'] ? DailyReportService::shown((string) $t['desc']) : __((string) $t['cat']), 'who' => (string) ($t['who'] ?? ''), 'obj' => DailyReportService::shown((string) ($t['obj'] ?? ''))],
                    array_slice((array) ($s['top_in'] ?? []), 0, 6)),
                'ai' => ($ai = app(DailyReportService::class)->aiText($ws, $r)) ? trim($ai) : null,
                'url' => url('/company/reports/'.$r->id),
            ];
        });
    }

    /** По объекту есть движение: деньги, расход за день/месяц, готовность или просрочка */
    private static function moving(array $o): bool
    {
        return abs((float) ($o['balance'] ?? 0)) > 0 || (float) ($o['out_day'] ?? 0) > 0 || (float) ($o['out_month'] ?? 0) > 0
            || (float) ($o['in_month'] ?? 0) > 0 || (int) ($o['progress'] ?? 0) > 0 || (int) ($o['overdue'] ?? 0) > 0;
    }

    /** Имя файла: «Hisobot_Navruz-Qurilish_2026-10-16.pdf» */
    public static function filename(Workspace $ws, DailyReport $r, string $ext): string
    {
        $word = ['uz' => 'Hisobot', 'tg' => 'Hisobot', 'ru' => 'Otchet'][DailyReportService::localeOf($ws)] ?? 'Hisobot';
        $name = trim(preg_replace('/[^\pL\pN]+/u', '-', \Illuminate\Support\Str::ascii($ws->name)), '-') ?: 'company';

        return $word.'_'.$name.'_'.Carbon::parse($r->stats['date'])->format('Y-m-d').'.'.$ext;
    }

    /** Оба файла: [имя => содержимое]. Что не собралось — пропускается (в лог), отчёт текстом всё равно уйдёт */
    public static function files(Workspace $ws, DailyReport $r): array
    {
        $out = [];
        foreach (['pdf', 'docx'] as $ext) {
            try {
                $out[self::filename($ws, $r, $ext)] = $ext === 'pdf' ? self::pdf($ws, $r) : self::docx($ws, $r);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /* ---------------- PDF ---------------- */

    public static function pdf(Workspace $ws, DailyReport $r): string
    {
        $c = self::content($ws, $r);
        $img = fn (?string $p) => $p && is_file($p) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($p)) : null;
        $html = self::inLocale($ws, fn () => view('reports.daily-pdf', ['c' => $c, 'brandLogo' => $img($c['brandLogo']), 'companyLogo' => $img($c['companyLogo']),
            'font' => str_replace('\\', '/', resource_path('fonts'))])->render());
        @mkdir(storage_path('fonts'), 0775, true);
        $o = new Options;
        $o->set('fontDir', storage_path('fonts'));
        $o->set('fontCache', storage_path('fonts'));
        $o->set('chroot', [resource_path(), storage_path()]);
        $o->set('isRemoteEnabled', false);
        $o->set('defaultFont', 'Inter');
        $o->set('isPhpEnabled', false);
        $pdf = new Dompdf($o);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        // подвал каждой страницы: кто прислал и номер страницы
        $canvas = $pdf->getCanvas();
        $fm = $pdf->getFontMetrics();
        $f = $fm->getFont('Inter', 'normal');
        $foot = $c['brand'].' · '.$c['company'].' · '.$c['title'].' '.$c['date'];
        $canvas->page_text(40, $canvas->get_height() - 28, $foot, $f, 7.5, [0.42, 0.45, 0.5]);
        $canvas->page_text($canvas->get_width() - 78, $canvas->get_height() - 28, '{PAGE_NUM} / {PAGE_COUNT}', $f, 7.5, [0.42, 0.45, 0.5]);

        return (string) $pdf->output();
    }

    /* ---------------- Word ---------------- */

    public static function docx(Workspace $ws, DailyReport $r): string
    {
        $c = self::content($ws, $r);

        return self::inLocale($ws, fn () => self::buildDocx($c));   // заголовки таблиц — тоже на языке компании
    }

    private static function buildDocx(array $c): string
    {
        $w = new PhpWord;
        $w->setDefaultFontName('Arial');
        $w->setDefaultFontSize(10);
        $w->getDocInfo()->setCreator($c['brand'])->setCompany($c['company'])->setTitle($c['title'].' — '.$c['date']);
        $sec = $w->addSection(['marginTop' => 720, 'marginBottom' => 720, 'marginLeft' => 800, 'marginRight' => 800]);
        $muted = ['size' => 8.5, 'color' => self::MUTED];
        $tight = ['spaceAfter' => 0, 'spaceBefore' => 0];

        // шапка: логотип платформы слева, логотип компании справа
        $hd = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => 40]);
        $hd->addRow();
        $l = $hd->addCell(5200, ['valign' => 'center']);
        $lt = $l->addTable();
        $lt->addRow();
        $lt->addCell(700, ['valign' => 'center'])->addImage($c['brandLogo'], ['width' => 30, 'height' => 30]);
        $lc = $lt->addCell(4400, ['valign' => 'center']);
        $lc->addText($c['brand'], ['bold' => true, 'size' => 13, 'color' => self::ACCENT], $tight);
        $lc->addText(__('Учёт стройки, денег и продаж'), $muted, $tight);
        $rc = $hd->addCell(4800, ['valign' => 'center']);
        $rt = $rc->addTable(['alignment' => JcTable::END]);
        $rt->addRow();
        $rn = $rt->addCell(3600, ['valign' => 'center']);
        $rn->addText($c['company'], ['bold' => true, 'size' => 11, 'color' => self::INK], ['alignment' => Jc::END] + $tight);
        if ($c['phone']) {
            $rn->addText($c['phone'], $muted, ['alignment' => Jc::END] + $tight);
        }
        $lg = $rt->addCell(900, ['valign' => 'center']);
        if ($c['companyLogo']) {
            $lg->addImage($c['companyLogo'], ['height' => 34, 'alignment' => Jc::END]);
        } else {
            $lg->addText($c['initials'], ['bold' => true, 'size' => 14, 'color' => self::ACCENT], ['alignment' => Jc::CENTER] + $tight);
        }

        // заголовок — цветная полоса
        $sec->addTextBreak(1, ['size' => 4]);
        $band = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => 160]);
        $band->addRow();
        $bc = $band->addCell(10000, ['bgColor' => self::ACCENT]);
        $bc->addText($c['title'], ['bold' => true, 'size' => 18, 'color' => 'FFFFFF'], $tight);
        $bc->addText($c['date'].' · '.$c['weekday'].' · '.$c['month'], ['size' => 10.5, 'color' => 'E9E5FF'], $tight);

        // кто прислал и кому
        $sec->addTextBreak(1, ['size' => 4]);
        $meta = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => 70, 'borderBottomSize' => 6, 'borderBottomColor' => 'E5E7EB']);
        foreach ([[__('Отправитель'), $c['from']], [__('Компания'), $c['company'].($c['phone'] ? ' · '.$c['phone'] : '')], [__('Получатель'), $c['to']], [__('Сформирован'), $c['generated']]] as [$k, $v]) {
            $meta->addRow();
            $meta->addCell(2300, ['bgColor' => 'F5F3FF'])->addText($k, ['bold' => true, 'size' => 9, 'color' => self::ACCENT], $tight);
            $meta->addCell(7700)->addText($v, ['size' => 9.5], $tight);
        }

        // главные цифры
        $sec->addTextBreak(1, ['size' => 6]);
        $k = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => 120, 'cellSpacing' => 60]);
        $k->addRow();
        foreach ($c['kpis'] as $x) {
            $cell = $k->addCell(2500, ['bgColor' => ! empty($x['hero']) ? self::ACCENT : 'F5F3FF']);
            $col = ! empty($x['hero']) ? 'FFFFFF' : self::INK;
            $cell->addText($x['l'], ['size' => 8.5, 'color' => ! empty($x['hero']) ? 'E9E5FF' : self::MUTED], $tight);
            $cell->addText($x['v'], ['bold' => true, 'size' => 12, 'color' => $col], $tight);
            $cell->addText($x['s'], ['size' => 7.5, 'color' => ! empty($x['hero']) ? 'E9E5FF' : self::MUTED], $tight);
        }

        $h = function (string $t) use ($sec) {
            $sec->addTextBreak(1, ['size' => 6]);
            $sec->addText($t, ['bold' => true, 'size' => 12, 'color' => self::INK], ['spaceAfter' => 80]);
        };
        // ячейка — строка или [текст, свой стиль шрифта]
        $table = function (array $head, array $rows, array $widths, array $right = [], int $pad = 80) use ($sec) {
            $t = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => $pad, 'borderBottomSize' => 4, 'borderBottomColor' => 'E5E7EB', 'borderInsideHSize' => 4, 'borderInsideHColor' => 'EEF0F4']);
            $t->addRow(null, ['tblHeader' => true]);
            foreach ($head as $i => $x) {
                $t->addCell($widths[$i], ['bgColor' => self::ACCENT])->addText($x, ['bold' => true, 'size' => 9, 'color' => 'FFFFFF'], ['alignment' => in_array($i, $right, true) ? Jc::END : Jc::START, 'spaceAfter' => 0]);
            }
            foreach ($rows as $n => $row) {
                $t->addRow();
                foreach ($row as $i => $x) {
                    [$text, $font] = is_array($x) ? $x : [(string) $x, ['size' => 9.5, 'bold' => $i === 0 && in_array(0, $right, true)]];
                    $t->addCell($widths[$i], $n % 2 ? ['bgColor' => 'FAFAFD'] : [])->addText($text, $font,
                        ['alignment' => in_array($i, $right, true) ? Jc::END : Jc::START, 'spaceAfter' => 0]);
                }
            }
        };

        $h(__('Расходы за 14 дней'));
        $days = array_slice($c['days'], -14);
        $max = max(1, ...array_column($days, 'v'));
        $table([__('Дата'), __('Потрачено'), ''], array_map(fn ($x) => [
            [$x['d'], ['size' => 9, 'bold' => $x['today'], 'color' => $x['today'] ? self::ACCENT : self::INK]],
            [CompanyStats::money($x['v']), ['size' => 9, 'bold' => $x['today']]],
            $x['v'] > 0 ? [str_repeat('█', max(1, (int) round($x['v'] / $max * 34))), ['size' => 9, 'color' => $x['today'] ? self::ACCENT : 'C4B5FD']] : ['·', ['size' => 9, 'color' => 'D1D5DB']],
        ], $days), [1300, 2700, 6000], [1], 45);

        $h(__('На что ушли деньги сегодня'));
        $c['cats'] ? $table([__('Статья'), __('Сумма'), __('Доля')], array_map(fn ($x) => [$x['n'], $x['v'], $x['p'].'%'], $c['cats']), [5000, 3200, 1800], [1, 2])
            : $sec->addText(__('Сегодня расходов не было'), ['color' => self::MUTED]);
        if ($c['top_in']) {
            $h(__('Откуда поступили деньги'));
            $table([__('Сумма'), __('Описание'), __('От кого'), __('Объект')], array_map(fn ($x) => [$x['v'], $x['desc'], $x['who'] ?: '—', $x['obj']], $c['top_in']), [2400, 3600, 2000, 2000], [0]);
        }
        if ($c['top']) {
            $h(__('Крупные расходы'));
            $table([__('Сумма'), __('Описание'), __('Кому'), __('Объект')], array_map(fn ($x) => [$x['v'], $x['desc'], $x['who'] ?: '—', $x['obj']], $c['top']), [2400, 3600, 2000, 2000], [0]);
        }
        if ($c['objects'] || $c['objectsIdle'] > 0) {
            $h(__('Объекты'));
            if ($c['objects']) {
                $table([__('Объект'), __('Приход − расход'), __('Сегодня'), __('За месяц'), __('Готовность')],
                    array_map(fn ($o) => [$o['n'].($o['late'] ? '  ⚠ '.__('просрочено этапов').': '.$o['late'] : ''), $o['bal'], $o['day'], $o['month'], $o['p'].'%'], $c['objects']), [3000, 2200, 1700, 1900, 1200], [1, 2, 3, 4]);
            }
            if ($c['objectsIdle'] > 0) {
                $sec->addText(__('Ещё объектов без движения денег и работ: :n', ['n' => $c['objectsIdle']]), ['size' => 8.5, 'color' => self::MUTED], ['spaceBefore' => 60]);
            }
        }
        if ($c['low']) {
            $h(__('Заканчиваются материалы'));
            $table([__('Объект'), __('Материал'), __('Осталось'), __('Хватит на')], array_map(fn ($x) => [$x['obj'], $x['item'], $x['qty'].' '.$x['unit'], $x['days'] !== null ? __(':n дн.', ['n' => $x['days']]) : '—'], $c['low']), [2600, 3600, 2000, 1800], [2, 3]);
        }
        if ($c['late']) {
            $h(__('Материал опаздывает'));
            $table(['№', __('Объект'), __('Нужно было к'), __('Статус'), __('Поставщик')], array_map(fn ($x) => [$x['number'], $x['obj'], $x['need'], $x['status'], $x['who'] ?: '—'], $c['late']), [900, 2800, 1700, 2100, 2500]);
        }
        if ($c['ai']) {
            $h(__('Вывод ИИ'));
            $ai = $sec->addTable(['width' => 100 * 50, 'unit' => 'pct', 'cellMargin' => 140]);
            $ai->addRow();
            $cell = $ai->addCell(10000, ['bgColor' => 'F5F3FF', 'borderLeftSize' => 24, 'borderLeftColor' => self::ACCENT]);
            foreach (preg_split('/\R+/u', $c['ai']) as $line) {
                if (trim($line) !== '') {
                    $cell->addText(trim($line), ['size' => 9.5], ['spaceAfter' => 60]);
                }
            }
        }
        $sec->addTextBreak(1, ['size' => 6]);
        $sec->addLink($c['url'], __('Отчёт на сайте').': '.$c['url'], ['size' => 8.5, 'color' => self::ACCENT, 'underline' => 'single']);

        $foot = $sec->addFooter();
        $ft = $foot->addTable(['width' => 100 * 50, 'unit' => 'pct']);
        $ft->addRow();
        $ft->addCell(8000)->addText($c['brand'].' · '.$c['company'].' · '.$c['title'].' '.$c['date'], $muted, $tight);
        $ft->addCell(2000)->addPreserveText('{PAGE} / {NUMPAGES}', $muted, ['alignment' => Jc::END] + $tight);

        $tmp = tempnam(sys_get_temp_dir(), 'rep');
        IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function inLocale(Workspace $ws, callable $fn)
    {
        $prev = App::getLocale();
        App::setLocale(DailyReportService::localeOf($ws));
        try {
            return $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
