<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('message-circle') !!}{{ __('Комментарии') }}</h3><span class="badge">{{ $comments->count() }}</span></div>
    <div class="thread">
      @forelse ($comments as $c)
      <div class="cmt"><span class="avatar">{{ initials($c->user?->name ?? '?') }}</span><div class="bubble"><small><b>{{ $c->user?->name ?? '—' }}</b> · {{ $c->created_at->format('d.m.Y H:i') }}</small><p>{{ $c->text }}</p></div></div>
      @empty
      <div class="muted">{{ __('Комментариев пока нет') }}</div>
      @endforelse
    </div>
    <form method="post" action="/company/thread/{{ $subjectType }}/{{ $subjectId }}/comment" class="mt" style="display:grid;gap:8px">
      @csrf
      <textarea class="input" name="text" rows="2" required maxlength="2000" placeholder="{{ __('Написать комментарий…') }}" style="height:auto;padding:10px 12px"></textarea>
      <div><button class="btn btn-s">{!! ic('send') !!}{{ __('Отправить') }}</button></div>
    </form>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('paperclip') !!}{{ __('Файлы') }}</h3><span class="badge">{{ $files->count() }}</span></div>
    <div class="files">
      @forelse ($files as $f)
      <div class="file-row">{!! ic(preg_match('/\.(jpe?g|png|webp|heic)$/i', $f->name) ? 'image' : 'file-text') !!}<a href="/company/files/{{ $f->id }}" target="_blank">{{ $f->name }}</a>
        <small class="muted nowrap">{{ number_format($f->size / 1024, 0, '.', ' ') }} KB · {{ $f->user?->name }}</small>
        @if (auth()->user()->isAdmin() || $f->user_id === auth()->id())
        <form method="post" action="/company/files/{{ $f->id }}" data-confirm="{{ __('Удалить файл?') }}">@csrf @method('delete')<button class="icon-btn" title="{{ __('Удалить') }}">{!! ic('trash-2') !!}</button></form>
        @endif
      </div>
      @empty
      <div class="muted">{{ __('Файлов пока нет') }}</div>
      @endforelse
    </div>
    @if (auth()->user()->canEdit())
    <form method="post" action="/company/thread/{{ $subjectType }}/{{ $subjectId }}/files" enctype="multipart/form-data" class="mt flex">
      @csrf
      <input type="file" name="files[]" multiple required class="input" style="padding:6px">
      <button class="btn btn-s">{!! ic('upload') !!}{{ __('Загрузить') }}</button>
    </form>
    <small class="muted">{{ __('Фото, PDF, Word, Excel, чертежи — до 20 МБ') }}</small>
    @endif
  </div>
</div>
