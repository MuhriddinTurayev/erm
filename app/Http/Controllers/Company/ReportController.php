<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\DailyReport;
use App\Services\DailyReportService;
use App\Services\ReportDocs;
use Illuminate\Http\Request;

/** Ежедневные отчёты руководителю */
class ReportController extends Controller
{
    /** Отчёт — по всей компании: сотруднику одного объекта он не показывается */
    private function companyWide(): void
    {
        abort_unless(auth()->user()->allowedObjects() === null, 403, __('Нет доступа к этому разделу'));
    }

    public function index()
    {
        $this->companyWide();
        $ws = $this->ws();

        return view('company.reports', [
            'ws' => $ws,
            'reports' => $ws->reports()->orderByDesc('date')->paginate(30),
        ]);
    }

    public function show(DailyReport $report, DailyReportService $svc)
    {
        $this->companyWide();
        abort_unless((int) $report->workspace_id === (int) $this->ws()->id, 404);

        return view('company.report', ['ws' => $this->ws(), 'r' => $report, 'text' => $svc->text($this->ws(), $report)]);
    }

    /** Скачать отчёт файлом: PDF или Word */
    public function file(DailyReport $report, string $type)
    {
        $this->companyWide();
        $ws = $this->ws();
        abort_unless((int) $report->workspace_id === (int) $ws->id, 404);
        $bytes = $type === 'pdf' ? ReportDocs::pdf($ws, $report) : ReportDocs::docx($ws, $report);

        return response($bytes, 200, [
            'Content-Type' => $type === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => ($type === 'pdf' ? 'inline' : 'attachment').'; filename="'.ReportDocs::filename($ws, $report, $type).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Собрать отчёт за сегодня и (по желанию) отправить сейчас */
    public function run(Request $request, DailyReportService $svc)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $ws = $this->ws();
        $r = $svc->generate($ws, null, $request->boolean('ai', true));
        if ($request->boolean('send')) {
            $errors = $svc->send($ws, $r);

            return redirect('/company/reports/'.$r->id)->with($errors ? 'warn' : 'ok', $errors ? implode('; ', $errors) : __('Отчёт отправлен'));
        }

        return redirect('/company/reports/'.$r->id);
    }
}
