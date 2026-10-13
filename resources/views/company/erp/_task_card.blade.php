<a class="kcard {{ $t->status !== 'done' && $t->due_date?->isPast() ? 'late' : '' }}" href="/company/tasks/{{ $t->id }}">
  <div class="k-t">{{ $t->title }}</div>
  <div class="k-m">
    @if ($t->priority === 'high')<span class="prio-high">{!! ic('flame', '', 13) !!}{{ __('срочно') }}</span>@endif
    @if ($t->due_date)<span class="{{ $t->status !== 'done' && $t->due_date->isPast() ? 'out-c' : '' }}">{!! ic('calendar', '', 13) !!} {{ $t->due_date->format('d.m') }}</span>@endif
    @if ($t->project_id)<span>{!! ic('building-2', '', 13) !!} {{ \Illuminate\Support\Str::limit($projects[$t->project_id] ?? '', 18) }}</span>@endif
  </div>
  @if ($t->progress > 0 && $t->status !== 'done')<div class="prog"><i style="width:{{ $t->progress }}%"></i></div>@endif
  <div class="k-m"><span class="avatar" style="width:22px;height:22px;font-size:10px">{{ initials($t->assignee?->name ?? '?') }}</span>{{ $t->assignee?->name ?? __('не назначено') }}</div>
</a>
