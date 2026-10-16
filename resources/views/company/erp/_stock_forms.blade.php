@php $allItems = $allItems ?? \App\Models\Item::of($ws->id)->orderBy('name')->get(); $allWh = \App\Models\Warehouse::of($ws->id)->where('is_active', true)->get(); @endphp
<dialog class="modal" id="dlg-mv"><form method="post" action="/company/stock/move" id="mv-form">@csrf
  <div class="modal-h"><h3>{!! ic('package') !!}{{ __('Складская операция') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Операция') }}</span><select name="op"><option value="in">{{ __('Приход') }}</option><option value="out">{{ __('Выдача на объект') }}</option><option value="transfer">{{ __('Перемещение') }}</option><option value="adjust">{{ __('Инвентаризация (факт)') }}</option></select></label>
    <label class="field"><span>{{ __('Товар') }}</span><select name="item_id" required>@foreach ($allItems as $it)<option value="{{ $it->id }}" @selected(isset($item) && $item->id === $it->id)>{{ $it->name }} ({{ __($it->unit ?? '') }})</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Склад') }}</span><select name="warehouse_id" required>@foreach ($allWh as $w)<option value="{{ $w->id }}">{{ __($w->name) }}</option>@endforeach</select></label>
    <label class="field" data-op="transfer"><span>{{ __('На склад') }}</span><select name="to_warehouse_id"><option value="">—</option>@foreach ($allWh as $w)<option value="{{ $w->id }}">{{ __($w->name) }}</option>@endforeach</select></label>
    <label class="field"><span data-l-qty>{{ __('Количество') }}</span><input type="number" step="0.001" min="0" name="qty" required></label>
    <label class="field" data-op="in"><span>{{ __('Цена за единицу') }}</span><input type="number" step="0.01" min="0" name="price"></label>
    <label class="field" data-op="in out transfer"><span>{{ __('Партия') }}</span><input name="batch" maxlength="40" placeholder="{{ __('авто') }}"></label>
    <label class="field" data-op="out"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field" data-op="in"><span>{{ __('Поставщик') }}</span><select name="counterparty_id"><option value="">—</option>@foreach ($counterparties ?? [] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Дата') }}</span><input type="date" name="date" value="{{ $ws->localNow()->toDateString() }}"></label>
    <label class="field full"><span>{{ __('Комментарий') }}</span><input name="note" maxlength="300" placeholder="{{ __('Кому выдано, номер накладной…') }}"></label>
    <label class="switch full" data-op="in"><input type="checkbox" name="make_pr" value="1" checked> {{ __('Создать заявку на оплату поставщику (сумма = количество × цена)') }}</label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Провести') }}</button></div>
</form></dialog>

<dialog class="modal" id="dlg-item"><form method="post" action="/company/stock/items">@csrf<input type="hidden" name="id">
  <div class="modal-h"><h3>{!! ic('package') !!}{{ __('Товар / материал') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Название') }}</span><input name="name" required maxlength="160" placeholder="{{ __('Цемент М400, мешок 50 кг') }}"></label>
    <label class="field"><span>{{ __('Ед. изм.') }}</span><input name="unit" value="шт" required maxlength="16" list="units"><datalist id="units">@foreach (\App\Services\Lists::get($ws, 'units') as $x)<option value="{{ $x }}">{{ __($x) }}</option>@endforeach</datalist></label>
    <label class="field"><span>{{ __('Артикул') }}</span><input name="sku" maxlength="40"></label>
    <label class="field"><span>{{ __('Группа') }}</span><input name="group" maxlength="60" placeholder="{{ __('Сыпучие, Металл, Электрика…') }}"></label>
    <label class="field"><span>{{ __('Минимальный остаток') }}</span><input type="number" step="0.001" min="0" name="min_qty"></label>
    <label class="field"><span>{{ __('Цена') }}</span><input type="number" step="0.01" min="0" name="price"></label>
    <div class="full muted" style="font-size:13px">{{ __('Начальный остаток (необязательно)') }}:</div>
    <label class="field"><span>{{ __('Количество') }}</span><input type="number" step="0.001" min="0" name="qty"></label>
    <label class="field"><span>{{ __('На склад') }}</span><select name="warehouse_id">@foreach ($allWh as $w)<option value="{{ $w->id }}">{{ __($w->name) }}</option>@endforeach</select></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>

@if (auth()->user()->isAdmin())
<dialog class="modal" id="dlg-wh"><form method="post" action="/company/stock/warehouses">@csrf<input type="hidden" name="id">
  <div class="modal-h"><h3>{!! ic('warehouse') !!}{{ __('Склад') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Название') }}</span><input name="name" required maxlength="80"></label>
    <label class="field"><span>{{ __('Склад объекта') }}</span><select name="project_id"><option value="">{{ __('Общий') }}</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field"><span>{{ __('Кладовщик') }}</span><select name="keeper_id"><option value="">—</option>@foreach ($staff ?? [] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form></dialog>
@endif
@push('scripts')
<script>
(() => {
  const f = document.getElementById('mv-form'); if (!f) return;
  const sync = () => { const op = f.op.value;
    f.querySelectorAll('[data-op]').forEach(el => el.style.display = el.dataset.op.split(' ').includes(op) ? '' : 'none');
    f.querySelector('[data-l-qty]').textContent = op === 'adjust' ? @json(__('Фактический остаток')) : @json(__('Количество'));
    f.to_warehouse_id.required = op === 'transfer'; };
  f.op.addEventListener('change', sync); sync();
})();
</script>
@endpush
