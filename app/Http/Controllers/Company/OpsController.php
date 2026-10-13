<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BuyerLink;
use App\Models\Expense;
use App\Models\SiteLog;
use App\Services\ExpenseService;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Согласование расходов, журнал работ, история изменений, файлы, ссылки покупателям */
class OpsController extends Controller
{
    /* ---------- согласование ---------- */

    public function approvals(Request $request)
    {
        $ws = $this->ws();
        $only = $request->user()->allowedObjects();
        $q = Expense::with(['user', 'decider'])->where('workspace_id', $ws->id)->when($only !== null, fn ($x) => $x->whereIn('project_id', $only));

        return view('company.approvals', [
            'ws' => $ws,
            'pending' => (clone $q)->where('status', 'pending')->latest()->get(),
            'done' => (clone $q)->where('status', '!=', 'pending')->latest('updated_at')->limit(50)->get(),
            'projects' => StateWriter::projects($ws),
        ]);
    }

    public function decide(Request $request, Expense $expense, ExpenseService $svc)
    {
        abort_unless($request->user()->isAdmin() && $expense->workspace_id === $this->ws()->id, 403);
        $ok = $request->input('action') === 'approve' ? $svc->approve($expense, $request->user()) : $svc->reject($expense, $request->user(), $request->input('reason'));

        return back()->with($ok ? 'ok' : 'warn', $ok ? __('Сохранено') : __('Уже решено'));
    }

    /** Расход из программы, который требует согласования (сумма не меньше лимита) */
    public function apiExpense(Request $request, ExpenseService $svc)
    {
        abort_unless($request->user()->canEdit(), 403);
        $d = $request->validate([
            'project_id' => ['required', 'string', 'max:40'], 'amount' => ['required', 'numeric', 'min:1'], 'cat' => ['required', 'string', 'max:60'],
            'desc' => ['nullable', 'string', 'max:300'], 'who' => ['nullable', 'string', 'max:120'], 'date' => ['nullable', 'date'],
        ]);
        $ws = $this->ws();
        $allowed = $request->user()->allowedObjects();
        abort_unless(isset(StateWriter::projects($ws, $allowed)[$d['project_id']]), 404);
        $e = $svc->create($ws, $request->user(), $d['project_id'], $d, 'web');

        return response()->json(['id' => $e->id, 'status' => $e->status]);
    }

    public function apiPending()
    {
        return response()->json(['pending' => Expense::where('workspace_id', $this->ws()->id)->where('status', 'pending')->count()]);
    }

    /* ---------- журнал работ ---------- */

    public function journal(Request $request)
    {
        $ws = $this->ws();
        $only = $request->user()->allowedObjects();
        $date = $request->query('date');
        $logs = SiteLog::with('user')->where('workspace_id', $ws->id)->when($only !== null, fn ($x) => $x->whereIn('project_id', $only))
            ->when(is_string($date) && $date !== '', fn ($x) => $x->whereDate('date', $date))->latest('date')->latest('id')->paginate(40)->withQueryString();

        return view('company.journal', ['ws' => $ws, 'logs' => $logs, 'projects' => StateWriter::projects($ws), 'date' => $date]);
    }

    /* ---------- история изменений ---------- */

    public function audit(Request $request)
    {
        $ws = $this->ws();
        $q = trim((string) $request->query('q'));
        $logs = AuditLog::with('user')->where('workspace_id', $ws->id)
            ->when($q !== '', fn ($x) => $x->where(fn ($y) => $y->where('summary', 'like', "%$q%")->orWhere('project', 'like', "%$q%")))
            ->when($request->query('user'), fn ($x, $u) => $x->where('user_id', (int) $u))
            ->latest('created_at')->latest('id')->paginate(60)->withQueryString();

        return view('company.audit', ['ws' => $ws, 'logs' => $logs, 'q' => $q, 'users' => $ws->users()->orderBy('name')->get()]);
    }

    /* ---------- файлы ---------- */

    public function receipt(Request $request, Expense $expense)
    {
        abort_unless($expense->workspace_id === $this->ws()->id && $expense->receipt && Storage::disk('local')->exists($expense->receipt), 404);

        return response()->file(Storage::disk('local')->path($expense->receipt), ['Cache-Control' => 'private, max-age=86400']);
    }

    public function logPhoto(Request $request, SiteLog $log, int $i)
    {
        $path = $log->photos[$i] ?? null;
        abort_unless($log->workspace_id === $this->ws()->id && $path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=86400']);
    }

    /* ---------- кабинет покупателя ---------- */

    public function apiBuyerLink(Request $request)
    {
        $d = $request->validate(['project_id' => ['required', 'string', 'max:40'], 'apt_id' => ['required', 'string', 'max:40']]);
        $ws = $this->ws();
        abort_unless(isset(StateWriter::projects($ws, $request->user()->allowedObjects())[$d['project_id']]), 404);
        $bl = BuyerLink::firstOrCreate(['workspace_id' => $ws->id, 'project_id' => $d['project_id'], 'apt_id' => $d['apt_id']], ['token' => Str::lower(Str::random(24))]);

        return response()->json(['url' => $bl->url()]);
    }
}
