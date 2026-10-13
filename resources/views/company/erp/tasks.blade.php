@extends('layouts.panel')
@section('title', __('Задачи'))
@php
  $u = auth()->user(); $sts = \App\Http\Controllers\Company\WorkController::STATUSES;
  $prio = ['low' => __('низкий'), 'normal' => __('обычный'), 'high' => __('высокий')];
  $view = $f['view'] ?? 'board'; $q = fn ($k, $v) => '?'.http_build_query(array_merge($f, [$k => $v]));
  $review = $open->where('status', 'review')->filter(fn ($t) => $u->isAdmin() || $t->author_id === $u->id);
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-task">{!! ic('plus') !!}{{ __('Новая задача') }}</button>@endif
@endsection
@section('content')
<div class="toolbar">
  <div class="chips">
    <a class="chip {{ empty($f['who']) ? 'on' : '' }}" href="{{ $q('who', '') }}">{{ __('Все') }}</a>
    <a class="chip {{ ($f['who'] ?? '') === 'me' ? 'on' : '' }}" href="{{ $q('who', 'me') }}">{{ __('Мне') }}</a>
    <a class="chip {{ ($f['who'] ?? '') === 'by' ? 'on' : '' }}" href="{{ $q('who', 'by') }}">{{ __('Поставлены мной') }}</a>
  </div>
  <div class="chips">
    <a class="chip {{ empty($f['prog']) ? 'on' : '' }}" href="{{ $q('prog', '') }}">{{ __('Любой прогресс') }}</a>
    <a class="chip {{ ($f['prog'] ?? '') === 'low' ? 'on' : '' }}" href="{{ $q('prog', 'low') }}">&lt; 50%</a>
    <a class="chip {{ ($f['prog'] ?? '') === 'high' ? 'on' : '' }}" href="{{ $q('prog', 'high') }}">≥ 50%</a>
    <a class="chip {{ ($f['prog'] ?? '') === 'overdue' ? 'on' : '' }}" href="{{ $q('prog', 'overdue') }}">{!! ic('alarm-clock', '', 14) !!}{{ __('Просрочены') }}</a>
  </div>
  <span class="grow"></span>
  <form method="get" class="flex">@foreach ($f as $k => $v) @if ($k !== 'project' && $k !== 'q')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
    <select class="input" name="project" onchange="this.form.submit()"><option value="">{{ __('Все объекты') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected(($f['project'] ?? '') === $pid)>{{ $pn }}</option>@endforeach</select>
    <input class="input" name="q" value="{{ $f['q'] ?? '' }}" placeholder="{{ __('Поиск') }}" style="min-width:140px"></form>
  <div class="chips"><a class="chip {{ $view === 'board' ? 'on' : '' }}" href="{{ $q('view', 'board') }}">{!! ic('kanban', '', 14) !!}</a><a class="chip {{ $view === 'list' ? 'on' : '' }}" href="{{ $q('view', 'list') }}">{!! ic('list', '', 14) !!}</a></div>
</div>

@if ($review->count())
<form method="post" action="/company/tasks/bulk" class="card" style="margin-bottom:16px">@csrf
  <div class="card-h"><h3>{!! ic('clipboard-check') !!}{{ __('Сданы на проверку — примите разом') }}</h3><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Принять отмеченные') }}</button></div>
  <div class="check-list">
    <label><input type="checkbox" data-all=".tk-ck"> <b>{{ __('Отметить все') }}</b></label>
    @foreach ($review as $t)<label><input type="checkbox" class="tk-ck" name="ids[]" value="{{ $t->id }}"> {{ $t->title }} <small class="muted">— {{ $t->assignee?->name }}</small></label>@endforeach
  </div>
</form>
@endif

@if ($view === 'board')
<div class="kanban">
  @foreach (['todo', 'doing', 'review'] as $s)
    @php $col = $open->where('status', $s); @endphp
    <div class="kcol"><h4>{{ __($sts[$s]) }}<span class="badge">{{ $col->count() }}</span></h4>
      @foreach ($col as $t) @include('company.erp._task_card') @endforeach
    </div>
  @endforeach
  <div class="kcol"><h4>{{ __('Выполнено') }}<span class="badge">{{ $done->count() }}</span></h4>
    @foreach ($done->take(15) as $t) @include('company.erp._task_card') @endforeach
  </div>
</div>
@else
<div class="card">
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Задача') }}</th><th>{{ __('Исполнитель') }}</th><th>{{ __('Объект') }}</th><th>{{ __('Срок') }}</th><th>{{ __('Прогресс') }}</th><th>{{ __('Статус') }}</th></tr></thead><tbody>
  @foreach ($open->concat($done) as $t)
    <tr class="row-link {{ $t->status === 'done' ? 'dim' : '' }}" data-href="/company/tasks/{{ $t->id }}"><td><b>{{ $t->title }}</b>@if ($t->priority !== 'normal') <small class="prio-{{ $t->priority }}">· {{ $prio[$t->priority] }}</small>@endif</td>
      <td>{{ $t->assignee?->name ?? '—' }}</td><td>{{ $t->project_id ? ($projects[$t->project_id] ?? '') : '—' }}</td>
      <td class="{{ $t->status !== 'done' && $t->due_date?->isPast() ? 'out-c' : '' }}">{{ $t->due_date?->format('d.m.Y') ?? '—' }}</td>
      <td style="min-width:110px">{{ $t->progress }}%<div class="prog"><i style="width:{{ $t->progress }}%"></i></div></td><td><span class="st st-{{ $t->status }}">{{ __($sts[$t->status]) }}</span></td></tr>
  @endforeach
  </tbody></table></div>
</div>
@endif

<div class="card mt">
  <div class="card-h"><h3>{!! ic('history') !!}{{ __('История выполненных') }}</h3></div>
  @forelse ($done as $t)
    <div class="list-row"><div><a href="/company/tasks/{{ $t->id }}">{{ $t->title }}</a><br><small>{{ $t->assignee?->name }} · {{ __('выполнено') }} {{ $t->done_at?->format('d.m.Y H:i') }}</small></div>
      @if ($t->due_date && $t->done_at)<span class="badge {{ $t->done_at->toDateString() <= $t->due_date->toDateString() ? 'b-ok' : 'b-bad' }}">{{ $t->done_at->toDateString() <= $t->due_date->toDateString() ? __('в срок') : __('с опозданием') }}</span>@endif</div>
  @empty<div class="empty">{{ __('Пока пусто') }}</div>@endforelse
</div>

@include('company.erp._task_form', ['t' => null])
@endsection
