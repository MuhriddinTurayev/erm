@php $stl = ['new' => 'новая', 'work' => 'в работе', 'done' => 'выполнена', 'cancelled' => 'отменена']; @endphp
<dialog class="modal" id="dlg-so"><form method="post" action="/company/services">@csrf<input type="hidden" name="id" value="{{ $so?->id }}">
  <div class="modal-h"><h3>{!! ic('wrench') !!}{{ $so ? __('Сервисная заявка').' №'.$so->number : __('Новая сервисная заявка') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Что случилось / что сделать') }}</span><input name="title" value="{{ $so?->title }}" required maxlength="200"></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected($so?->project_id === $pid)>{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Подрядчик / сервис') }}</span><select name="counterparty_id"><option value="">—</option>@foreach ($counterparties as $c)<option value="{{ $c->id }}" @selected($so?->counterparty_id === $c->id)>{{ $c->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Ответственный') }}</span><select name="assignee_id"><option value="">—</option>@foreach ($staff as $s)<option value="{{ $s->id }}" @selected($so?->assignee_id === $s->id)>{{ $s->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Срок') }}</span><input type="date" name="due_date" value="{{ $so?->due_date?->toDateString() }}"></label>
    <label class="field"><span>{{ __('Стоимость') }}</span><input type="number" step="0.01" min="0" name="amount" value="{{ $so?->amount ?: '' }}"></label>
    @if ($so)<label class="field"><span>{{ __('Статус') }}</span><select name="status">@foreach ($stl as $k => $v)<option value="{{ $k }}" @selected($so->status === $k)>{{ __($v) }}</option>@endforeach</select></label>@endif
    <label class="field full"><span>{{ __('Описание') }}</span><textarea name="description" rows="4">{{ $so?->description }}</textarea></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
