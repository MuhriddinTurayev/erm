@extends('layouts.panel')
@section('title', __('Блог'))
@php $ln = config('oftob.locales'); @endphp
@section('actions')
<a class="btn btn-s" href="/blog" target="_blank">{!! ic('external-link') !!}{{ __('Открыть блог') }}</a>
@if ($edit)<a class="btn btn-p btn-s" href="/admin/posts">{!! ic('plus') !!}{{ __('Новая статья') }}</a>@endif
@endsection
@section('content')
<div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start">
<form method="post" action="{{ $edit ? '/admin/posts/'.$edit->id : '/admin/posts' }}" enctype="multipart/form-data" class="card">@csrf
  <div class="card-h"><h3>{!! ic('newspaper') !!}{{ $edit ? __('Редактирование статьи') : __('Новая статья') }}</h3>
    <label class="switch"><input type="checkbox" name="is_published" value="1" @checked($edit?->is_published ?? true)> {{ __('Опубликована') }}</label></div>
  <div class="tabs" id="ptabs" style="margin-bottom:14px">@foreach ($ln as $l => [$n])<a href="#" data-l="{{ $l }}" class="{{ $loop->first ? 'on' : '' }}">{{ $n }}</a>@endforeach</div>
  @foreach ($ln as $l => [$n])
  <div class="form plang" data-l="{{ $l }}" @unless ($loop->first) hidden @endunless style="grid-template-columns:1fr">
    <label class="field"><span>{{ __('Заголовок') }} ({{ $n }})</span><input name="title[{{ $l }}]" value="{{ old('title.'.$l, $edit?->title[$l] ?? '') }}" @if ($l === 'ru') required @endif maxlength="200"></label>
    <label class="field"><span>{{ __('Краткое описание (для поиска и соцсетей)') }}</span><textarea name="excerpt[{{ $l }}]" rows="2" maxlength="500">{{ old('excerpt.'.$l, $edit?->excerpt[$l] ?? '') }}</textarea></label>
    <label class="field"><span>{{ __('Текст статьи (Markdown)') }}</span><textarea name="body[{{ $l }}]" rows="16">{{ old('body.'.$l, $edit?->body[$l] ?? '') }}</textarea></label>
  </div>
  @endforeach
  <div class="form mt">
    <label class="field"><span>{{ __('Адрес') }} /blog/…</span><input name="slug" value="{{ old('slug', $edit?->slug) }}" placeholder="{{ __('создастся из заголовка') }}" pattern="[a-z0-9\-]*"></label>
    <label class="field"><span>{{ __('Дата публикации') }}</span><input type="datetime-local" name="published_at" value="{{ $edit?->published_at?->format('Y-m-d\TH:i') }}"></label>
    <label class="field"><span>{{ __('Обложка') }}</span><input type="file" name="cover" accept="image/*">@if ($edit?->cover)<small><a href="{{ $edit->cover }}" target="_blank">{{ __('текущая') }}</a></small>@endif</label>
  </div>
  <div class="mt flex"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button>@if ($edit)<a class="btn" href="/blog/{{ $edit->slug }}" target="_blank">{!! ic('eye') !!}{{ __('Посмотреть') }}</a>@endif</div>
</form>
@if ($edit)<form method="post" action="/admin/posts/{{ $edit->id }}" data-confirm="{{ __('Удалить статью?') }}" id="del-post">@csrf @method('delete')</form>@endif
<div class="card">
  <div class="card-h"><h3>{!! ic('list') !!}{{ __('Статьи') }}</h3><span class="badge">{{ $posts->total() }}</span></div>
  @forelse ($posts as $p)
    <div class="list-row"><div><a href="/admin/posts/{{ $p->id }}"><b>{{ $p->t('title', 'ru') }}</b></a><br><small class="muted">{{ $p->published_at?->format('d.m.Y') }} · {{ implode(' ', array_map('strtoupper', array_keys(array_filter((array) $p->title)))) }}</small></div>
      <span class="badge {{ $p->is_published ? 'b-ok' : '' }}">{{ $p->is_published ? __('опубл.') : __('черновик') }}</span></div>
  @empty<div class="empty">{{ __('Статей пока нет') }}</div>@endforelse
  <div class="mt">{{ $posts->links() }}</div>
  @if ($edit)<button class="btn btn-s btn-d mt" form="del-post">{!! ic('trash-2') !!}{{ __('Удалить эту статью') }}</button>@endif
</div>
</div>
@endsection
@push('scripts')
<script>
document.getElementById('ptabs').addEventListener('click', e => { const a = e.target.closest('a'); if (!a) return; e.preventDefault();
  document.querySelectorAll('#ptabs a').forEach(x => x.classList.toggle('on', x === a));
  document.querySelectorAll('.plang').forEach(f => f.hidden = f.dataset.l !== a.dataset.l); });
</script>
@endpush
