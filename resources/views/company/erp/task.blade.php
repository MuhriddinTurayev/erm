@extends('layouts.panel')
@section('title', $t->title)
@php
  $u = auth()->user(); $sts = \App\Http\Controllers\Company\WorkController::STATUSES;
  $boss = $u->isAdmin() || $t->author_id === $u->id; $mine = $t->assignee_id === $u->id;
@endphp
@section('actions')
<a class="btn btn-s btn-ghost" href="/company/tasks">{!! ic('arrow-left') !!}{{ __('Все задачи') }}</a>
@if ($boss)<button class="btn btn-s" data-open="dlg-task">{!! ic('pencil') !!}{{ __('Изменить') }}</button>
<form method="post" action="/company/tasks/{{ $t->id }}" data-confirm="{{ __('Удалить задачу?') }}">@csrf @method('delete')<button class="btn btn-s btn-ghost">{!! ic('trash-2') !!}</button></form>@endif
@endsection
@section('content')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{{ $t->title }}</h3><span class="st st-{{ $t->status }}">{{ __($sts[$t->status]) }}</span></div>
    @if ($t->description)<p style="white-space:pre-wrap;margin-top:0">{{ $t->description }}</p>@endif
    <dl class="kv">
      <dt>{{ __('Исполнитель') }}</dt><dd>{{ $t->assignee?->name ?? '—' }}</dd>
      <dt>{{ __('Поставил') }}</dt><dd>{{ $t->author?->name }} · {{ $t->created_at->format('d.m.Y') }}</dd>
      <dt>{{ __('Срок') }}</dt><dd class="{{ $t->status !== 'done' && $t->due_date?->isPast() ? 'out-c' : '' }}">{{ $t->due_date?->format('d.m.Y') ?? '—' }}</dd>
      <dt>{{ __('Объект') }}</dt><dd>{{ $t->project_id ? ($projects[$t->project_id] ?? '—') : '—' }}</dd>
      @if ($t->done_at)<dt>{{ __('Выполнено') }}</dt><dd>{{ $t->done_at->format('d.m.Y H:i') }} · {{ __('принял') }} {{ $t->approver?->name }}</dd>@endif
    </dl>
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('gauge') !!}{{ __('Ход выполнения') }}</h3><b>{{ $t->progress }}%</b></div>
    <div class="prog" style="height:10px;margin-bottom:16px"><i style="width:{{ $t->progress }}%"></i></div>
    @if (($boss || $mine) && $u->canEdit() && $t->status !== 'done')
    <form method="post" action="/company/tasks/{{ $t->id }}/status" style="display:grid;gap:12px">@csrf
      <label class="field"><span>{{ __('Прогресс') }}: <b id="pv">{{ $t->progress }}</b>%</span><input type="range" name="progress" min="0" max="100" step="5" value="{{ $t->progress }}" oninput="pv.textContent=this.value"></label>
      <div class="flex" style="flex-wrap:wrap">
        <button class="btn btn-s" name="status" value="doing">{!! ic('circle-play') !!}{{ __('В работе') }}</button>
        @if ($boss)<button class="btn btn-p btn-s" name="status" value="done">{!! ic('check') !!}{{ __('Принять, выполнено') }}</button>
        @else<button class="btn btn-p btn-s" name="status" value="review">{!! ic('send') !!}{{ __('Сдать на проверку') }}</button>@endif
        @if ($boss && $t->status === 'review')<button class="btn btn-s btn-d" name="status" value="doing">{{ __('Вернуть в работу') }}</button>@endif
      </div>
    </form>
    @elseif ($t->status === 'done' && $boss)
    <form method="post" action="/company/tasks/{{ $t->id }}/status">@csrf<input type="hidden" name="progress" value="80"><button class="btn btn-s" name="status" value="doing">{{ __('Открыть заново') }}</button></form>
    @endif
  </div>
</div>
@include('company.erp._thread')
@include('company.erp._task_form', ['t' => $t])
@endsection
