<dialog class="modal" id="dlg-task"><form method="post" action="/company/tasks">@csrf<input type="hidden" name="id" value="{{ $t?->id }}">
  <div class="modal-h"><h3>{!! ic('list-checks') !!}{{ $t ? __('Изменить задачу') : __('Новая задача') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Что нужно сделать') }}</span><input name="title" value="{{ $t?->title }}" required maxlength="200"></label>
    <label class="field"><span>{{ __('Исполнитель') }}</span><select name="assignee_id"><option value="">—</option>@foreach ($staff as $s)<option value="{{ $s->id }}" @selected($t?->assignee_id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Срок') }}</span><input type="date" name="due_date" value="{{ $t?->due_date?->toDateString() }}"></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected($t?->project_id === $pid)>{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Приоритет') }}</span><select name="priority">@foreach (['normal' => __('обычный'), 'high' => __('высокий'), 'low' => __('низкий')] as $k => $v)<option value="{{ $k }}" @selected(($t?->priority ?? 'normal') === $k)>{{ $v }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Описание') }}</span><textarea name="description" rows="4">{{ $t?->description }}</textarea></label>
    <small class="muted full">{{ __('Исполнитель получит уведомление в Telegram.') }}</small>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
