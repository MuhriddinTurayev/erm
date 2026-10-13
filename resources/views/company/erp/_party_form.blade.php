@php $kinds = \App\Http\Controllers\Company\PartyController::KINDS; @endphp
<dialog class="modal" id="dlg-cp"><form method="post" action="{{ $cp ? '/company/parties/'.$cp->id : '/company/parties' }}">@csrf @if ($cp) @method('put') @endif
  <div class="modal-h"><h3>{!! ic('contact') !!}{{ $cp ? __('Карточка контрагента') : __('Новый контрагент') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Название / ФИО') }}</span><input name="name" value="{{ old('name', $cp?->name) }}" required maxlength="160"></label>
    <label class="field"><span>{{ __('Тип') }}</span><select name="kind">@foreach ($kinds as $k => $n)<option value="{{ $k }}" @selected(old('kind', $cp?->kind) === $k)>{{ __($n) }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Папка') }}</span><input name="folder" value="{{ old('folder', $cp?->folder) }}" maxlength="60" list="folders-list" placeholder="{{ __('Бетон, Арматура, Субподряд…') }}">
      <datalist id="folders-list">@foreach (\App\Models\Counterparty::of(auth()->user()->workspace_id)->whereNotNull('folder')->distinct()->pluck('folder') as $fo)<option value="{{ $fo }}">@endforeach</datalist></label>
    <label class="field"><span>{{ __('ИНН (СТИР)') }}</span><input name="inn" value="{{ old('inn', $cp?->inn) }}" maxlength="20" inputmode="numeric"></label>
    <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" value="{{ old('phone', $cp?->phone) }}" maxlength="40"></label>
    <label class="field"><span>{{ __('Контактное лицо') }}</span><input name="contact" value="{{ old('contact', $cp?->contact) }}" maxlength="120"></label>
    <label class="field"><span>{{ __('Адрес') }}</span><input name="address" value="{{ old('address', $cp?->address) }}" maxlength="255"></label>
    <label class="field full"><span>{{ __('Банковские реквизиты') }}</span><input name="bank" value="{{ old('bank', $cp?->bank) }}" maxlength="255" placeholder="{{ __('Банк, МФО, р/с') }}"></label>
    <label class="field full"><span>{{ __('Заметки') }}</span><textarea name="note" rows="2">{{ old('note', $cp?->note) }}</textarea></label>
    @if (session('dup'))<label class="switch full"><input type="checkbox" name="force" value="1"> {{ __('Всё равно создать') }}</label>@endif
    @unless ($cp)<small class="muted full">{{ __('Новый контрагент попадает «на проверку» — руководитель получит уведомление в Telegram.') }}</small>@endunless
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@if ($errors->any() && old('name') !== null)
@push('scripts')<script>document.getElementById('dlg-cp').showModal()</script>@endpush
@endif
