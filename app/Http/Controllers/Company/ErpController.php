<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Counterparty;
use App\Models\User;
use App\Services\StateWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Общее для разделов ERP: объекты пользователя, проверки, комментарии и файлы */
abstract class ErpController extends Controller
{
    /** Объекты, доступные пользователю: [id => название] */
    protected function projects(): array
    {
        return StateWriter::projects($this->ws(), auth()->user()->allowedObjects());
    }

    /** Ограничить запрос объектами пользователя */
    protected function scopeProjects($q, string $col = 'project_id')
    {
        $only = auth()->user()->allowedObjects();

        return $only === null ? $q : $q->where(fn ($x) => $x->whereIn($col, $only)->orWhereNull($col));
    }

    protected function checkProject(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        abort_unless(isset($this->projects()[$id]), 403, __('Нет доступа к этому объекту'));

        return $id;
    }

    protected function editor(): User
    {
        $u = auth()->user();
        abort_unless($u->canEdit(), 403, __('У вас доступ только для просмотра'));

        return $u;
    }

    /**
     * Запись своей компании и (для сотрудника объекта) своего объекта.
     * Прораб одного объекта не откроет по ссылке договор, заявку, задачу или фото другого объекта.
     */
    protected function mine($model): void
    {
        abort_unless($model && (int) $model->workspace_id === (int) $this->ws()->id, 404);
        $only = auth()->user()->allowedObjects();
        $pid = $model->getAttribute('project_id');
        if ($only !== null && $pid !== null && $pid !== '' && ! in_array((string) $pid, $only, true)) {
            abort(404);
        }
    }

    protected function counterparties()
    {
        return Counterparty::of($this->ws()->id)->orderBy('name')->get(['id', 'name', 'status', 'kind']);
    }

    protected function staff()
    {
        return $this->ws()->users()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role']);
    }

    /* ---------- комментарии и файлы для любой записи ---------- */

    public const SUBJECTS = [
        'task' => \App\Models\Task::class, 'service' => \App\Models\ServiceOrder::class, 'contract' => \App\Models\Contract::class,
        'payment' => \App\Models\PaymentRequest::class, 'counterparty' => \App\Models\Counterparty::class, 'tender' => \App\Models\Tender::class,
        'supply' => \App\Models\SupplyRequest::class, 'log' => \App\Models\SiteLog::class,
    ];

    protected function subject(string $type, int $id)
    {
        $cls = self::SUBJECTS[$type] ?? abort(404);
        $m = $cls::find($id);
        $this->mine($m);

        return $m;
    }

    public function comment(Request $request, string $type, int $id)
    {
        $this->subject($type, $id);
        $this->editor();
        $d = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        Comment::create(['workspace_id' => $this->ws()->id, 'subject' => $type, 'subject_id' => $id, 'user_id' => $request->user()->id, 'text' => $d['text']]);

        return back()->with('ok', __('Комментарий добавлен'));
    }

    public function upload(Request $request, string $type, int $id)
    {
        $this->subject($type, $id);
        $this->editor();
        $request->validate(['files' => ['required', 'array', 'max:10'], 'files.*' => ['file', 'max:20480',
            'mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,csv,txt,zip,rar,dwg']]);
        foreach ($request->file('files') as $f) {
            $path = $f->store('att/'.$this->ws()->id, 'local');
            Attachment::create(['workspace_id' => $this->ws()->id, 'subject' => $type, 'subject_id' => $id, 'path' => $path,
                'name' => mb_substr($f->getClientOriginalName(), 0, 200), 'size' => $f->getSize(), 'user_id' => $request->user()->id]);
        }

        return back()->with('ok', __('Файлы загружены'));
    }

    public function file(Attachment $att)
    {
        $this->mine($att);
        $this->subject($att->subject, (int) $att->subject_id);   // файл видит тот, кто видит саму запись
        abort_unless(Storage::disk('local')->exists($att->path), 404);

        return response()->file(Storage::disk('local')->path($att->path), [
            'Content-Disposition' => (str_starts_with((string) Storage::disk('local')->mimeType($att->path), 'image/') || str_ends_with($att->name, '.pdf') ? 'inline' : 'attachment')
                .'; filename*=UTF-8\'\''.rawurlencode($att->name),
        ]);
    }

    public function fileDelete(Attachment $att)
    {
        $this->mine($att);
        $this->subject($att->subject, (int) $att->subject_id);
        abort_unless(auth()->user()->isAdmin() || $att->user_id === auth()->id(), 403);
        Storage::disk('local')->delete($att->path);
        $att->delete();

        return back()->with('ok', __('Файл удалён'));
    }

    /** Комментарии и файлы записи — для карточки */
    protected function thread(string $type, int $id): array
    {
        return [
            'comments' => Comment::with('user')->where('workspace_id', $this->ws()->id)->where('subject', $type)->where('subject_id', $id)->orderBy('id')->get(),
            'files' => Attachment::with('user')->where('workspace_id', $this->ws()->id)->where('subject', $type)->where('subject_id', $id)->orderBy('id')->get(),
            'subjectType' => $type, 'subjectId' => $id,
        ];
    }
}
