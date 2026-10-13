@php $stl = ['draft' => 'черновик', 'active' => 'действует', 'done' => 'исполнен', 'cancelled' => 'расторгнут']; $cps = $counterparties ?? \App\Models\Counterparty::of(auth()->user()->workspace_id)->orderBy('name')->get(); @endphp
<dialog class="modal" id="dlg-ct"><form method="post" action="{{ $c ? '/company/contracts/'.$c->id : '/company/contracts' }}">@csrf @if ($c) @method('put') @endif
  <div class="modal-h"><h3>{!! ic('file-pen-line') !!}{{ $c ? __('Договор').' №'.$c->number : __('Новый договор') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Номер') }}</span><input name="number" value="{{ old('number', $c?->number) }}" required maxlength="40"></label>
    <label class="field"><span>{{ __('Дата') }}</span><input type="date" name="date" value="{{ old('date', $c?->date?->toDateString() ?? now()->toDateString()) }}" required></label>
    <label class="field full"><span>{{ __('Контрагент') }}</span><select name="counterparty_id" required><option value="">—</option>@foreach ($cps as $x)<option value="{{ $x->id }}" @selected((int) old('counterparty_id', $c?->counterparty_id ?? request('cp')) === $x->id) @disabled($x->status === 'blocked')>{{ $x->name }}{{ $x->status === 'blocked' ? ' — '.__('заблокирован') : '' }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Предмет договора') }}</span><input name="subject" value="{{ old('subject', $c?->subject) }}" required maxlength="300"></label>
    <label class="field"><span>{{ __('Направление') }}</span><select name="direction"><option value="out" @selected(old('direction', $c?->direction) === 'out')>{{ __('Мы платим') }}</option><option value="in" @selected(old('direction', $c?->direction) === 'in')>{{ __('Нам платят') }}</option></select></label>
    <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected(old('project_id', $c?->project_id) === $pid)>{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Сумма') }}</span><input type="number" step="0.01" min="0" name="amount" value="{{ old('amount', $c?->amount) }}" required></label>
    <label class="field"><span>{{ __('Валюта') }}</span><select name="currency">@foreach ($currencies as $x)<option @selected(old('currency', $c?->currency) === $x)>{{ $x }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Срок оплаты / исполнения') }}</span><input type="date" name="due_date" value="{{ old('due_date', $c?->due_date?->toDateString()) }}"></label>
    <label class="field"><span>{{ __('Статус') }}</span><select name="status">@foreach ($stl as $k => $n)<option value="{{ $k }}" @selected(old('status', $c?->status ?? 'active') === $k)>{{ __($n) }}</option>@endforeach</select></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@if ($errors->any() && old('number') !== null)
@push('scripts')<script>document.getElementById('dlg-ct').showModal()</script>@endpush
@endif
