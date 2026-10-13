<?php

namespace App\Http\Controllers\Company;

use App\Models\AuditLog;
use App\Models\ServiceOrder;
use App\Models\Task;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Задачи сотрудникам и сервисные заявки */
class WorkController extends ErpController
{
    public const STATUSES = ['todo' => 'К выполнению', 'doing' => 'В работе', 'review' => 'На проверке', 'done' => 'Выполнено'];

    public function tasks(Request $request)
    {
        $ws = $this->ws();
        $u = $request->user();
        $f = $request->only(['who', 'project', 'prog', 'view', 'q']);
        $q = Task::of($ws->id)->with(['assignee', 'author'])->tap(fn ($x) => $this->scopeProjects($x))
            ->when(($f['who'] ?? '') === 'me', fn ($x) => $x->where('assignee_id', $u->id))
            ->when(($f['who'] ?? '') === 'by', fn ($x) => $x->where('author_id', $u->id))
            ->when($f['project'] ?? null, fn ($x, $v) => $x->where('project_id', $v))
            ->when($f['q'] ?? null, fn ($x, $v) => $x->where('title', 'like', "%$v%"))
            ->when($f['prog'] ?? null, fn ($x, $v) => match ($v) {
                'overdue' => $x->where('status', '!=', 'done')->whereDate('due_date', '<', now()),
                'low' => $x->where('status', '!=', 'done')->where('progress', '<', 50),
                'high' => $x->where('status', '!=', 'done')->where('progress', '>=', 50),
                default => $x,
            });
        $open = (clone $q)->where('status', '!=', 'done')->orderByRaw("priority = 'high' desc")->orderByRaw('due_date is null')->orderBy('due_date')->get();
        $done = (clone $q)->where('status', 'done')->latest('done_at')->limit(60)->get();

        return view('company.erp.tasks', ['ws' => $ws, 'open' => $open, 'done' => $done, 'f' => $f, 'projects' => $this->projects(), 'staff' => $this->staff()]);
    }

    public function taskShow(Task $task)
    {
        $this->mine($task);

        return view('company.erp.task', ['ws' => $this->ws(), 't' => $task->load(['assignee', 'author', 'approver']), 'projects' => $this->projects(), 'staff' => $this->staff()]
            + $this->thread('task', $task->id));
    }

    public function taskSave(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        $d = $request->validate(['id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'project_id' => ['nullable', 'string', 'max:40'], 'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('workspace_id', $ws->id)],
            'due_date' => ['nullable', 'date'], 'priority' => ['required', Rule::in(['low', 'normal', 'high'])]]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        if (! empty($d['id'])) {
            $t = Task::of($ws->id)->findOrFail($d['id']);
            abort_unless($u->isAdmin() || $t->author_id === $u->id, 403);
            $t->update($d);
        } else {
            $t = Task::create($d + ['workspace_id' => $ws->id, 'author_id' => $u->id]);
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'project' => $this->projects()[$t->project_id] ?? null, 'action' => 'task.add',
                'summary' => 'Задача: '.$t->title.($t->assignee ? ' → '.$t->assignee->name : '')]);
            $this->tell($t->assignee, '📌 <b>'.__('Новая задача').'</b>'."\n".e($t->title).($t->due_date ? "\n📅 ".$t->due_date->format('d.m.Y') : '')."\n👤 ".e($u->name), $t);
        }

        return back()->with('ok', __('Сохранено'));
    }

    /** Статус и прогресс: исполнитель двигает, автор/руководитель принимает */
    public function taskStatus(Request $request, Task $task)
    {
        $this->mine($task);
        $u = $this->editor();
        $d = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUSES))], 'progress' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $boss = $u->isAdmin() || $task->author_id === $u->id;
        abort_unless($boss || $task->assignee_id === $u->id, 403);
        if ($d['status'] === 'done' && ! $boss) {
            $d['status'] = 'review';   // исполнитель сдаёт на проверку
        }
        $task->fill(['status' => $d['status'], 'progress' => $d['status'] === 'done' ? 100 : ($d['progress'] ?? $task->progress)]);
        if ($d['status'] === 'done') {
            $task->fill(['done_at' => now(), 'approved_by' => $u->id]);
        }
        $task->save();
        if ($task->status === 'review') {
            $this->tell($task->author, '👀 <b>'.__('Задача на проверке').'</b>'."\n".e($task->title)."\n👤 ".e($u->name), $task);
        }

        return back()->with('ok', __('Сохранено'));
    }

    /** Массово принять задачи, сданные на проверку */
    public function taskBulk(Request $request)
    {
        $u = $this->editor();
        $n = 0;
        foreach (Task::of($this->ws()->id)->whereIn('id', (array) $request->input('ids', []))->where('status', 'review')->get() as $t) {
            if ($u->isAdmin() || $t->author_id === $u->id) {
                $t->update(['status' => 'done', 'progress' => 100, 'done_at' => now(), 'approved_by' => $u->id]);
                $n++;
            }
        }

        return back()->with('ok', __('Принято задач: :n', ['n' => $n]));
    }

    public function taskDelete(Task $task)
    {
        $this->mine($task);
        abort_unless(auth()->user()->isAdmin() || $task->author_id === auth()->id(), 403);
        $task->delete();

        return redirect('/company/tasks')->with('ok', __('Задача удалена'));
    }

    private function tell($user, string $html, Task $t): void
    {
        if (! $user || ! $user->telegram_chat_id || $user->id === auth()->id() || ! Telegram::enabled()) {
            return;
        }
        try {
            Telegram::message($user->telegram_chat_id, $html, [[['text' => __('Открыть'), 'url' => url('/company/tasks/'.$t->id)]]]);
        } catch (\Throwable) {
        }
    }

    /* ---------------- Сервисные заявки ---------------- */

    public function services(Request $request)
    {
        $ws = $this->ws();
        $st = $request->query('status');

        return view('company.erp.services', ['ws' => $ws, 'st' => $st, 'projects' => $this->projects(), 'staff' => $this->staff(), 'counterparties' => $this->counterparties(),
            'list' => ServiceOrder::of($ws->id)->with(['counterparty', 'assignee'])->when($st, fn ($q, $v) => $q->where('status', $v))
                ->tap(fn ($q) => $this->scopeProjects($q))->latest('id')->paginate(40)->withQueryString(),
            'counts' => ServiceOrder::of($ws->id)->select('status', DB::raw('count(*) n'))->groupBy('status')->pluck('n', 'status')]);
    }

    public function service(ServiceOrder $so)
    {
        $this->mine($so);

        return view('company.erp.service', ['ws' => $this->ws(), 'so' => $so->load(['counterparty', 'assignee', 'user']), 'projects' => $this->projects(),
            'staff' => $this->staff(), 'counterparties' => $this->counterparties(),
            'history' => AuditLog::with('user')->where('workspace_id', $so->workspace_id)->where('action', 'like', 'service.%')->where('summary', 'like', 'Сервис №'.$so->number.':%')->latest('id')->get()]
            + $this->thread('service', $so->id));
    }

    public function serviceSave(Request $request)
    {
        $u = $this->editor();
        $ws = $this->ws();
        if ($request->boolean('quick')) {   // кнопка «Взять в работу» / «Выполнено»
            $so = ServiceOrder::of($ws->id)->findOrFail((int) $request->input('id'));
            $st = $request->validate(['status' => ['required', Rule::in(['work', 'done'])]])['status'];
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'service.edit', 'summary' => 'Сервис №'.$so->number.': статус '.$so->status.' → '.$st]);
            $so->update(['status' => $st]);

            return back()->with('ok', __('Сохранено'));
        }
        $d = $request->validate(['id' => ['nullable', 'integer'], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'project_id' => ['nullable', 'string', 'max:40'], 'counterparty_id' => ['nullable', Rule::exists('counterparties', 'id')->where('workspace_id', $ws->id)],
            'assignee_id' => ['nullable', Rule::exists('users', 'id')->where('workspace_id', $ws->id)], 'amount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'], 'status' => ['nullable', Rule::in(['new', 'work', 'done', 'cancelled'])]]);
        $d['project_id'] = $this->checkProject($d['project_id'] ?? null);
        $d['amount'] = (float) ($d['amount'] ?? 0);
        if (! empty($d['id'])) {
            $so = ServiceOrder::of($ws->id)->findOrFail($d['id']);
            $old = $so->status;
            $so->update(array_merge($d, ['status' => $d['status'] ?? $so->status]));
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'service.edit',
                'summary' => 'Сервис №'.$so->number.': '.($old !== $so->status ? 'статус '.$old.' → '.$so->status : 'изменена')]);
        } else {
            $so = DB::transaction(fn () => ServiceOrder::create($d + ['workspace_id' => $ws->id, 'user_id' => $u->id, 'status' => 'new',
                'number' => (int) ServiceOrder::of($ws->id)->lockForUpdate()->max('number') + 1]));
            AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'action' => 'service.add', 'summary' => 'Сервис №'.$so->number.': создана · '.$so->title]);

            return redirect('/company/services/'.$so->id)->with('ok', __('Заявка создана'));
        }

        return back()->with('ok', __('Сохранено'));
    }
}
