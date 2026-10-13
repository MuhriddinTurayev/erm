@php $p = $p ?? null; $uid = 'd'.($p?->id ?? 'new'); @endphp
<div class="form">
  <label class="field"><span>{{ __('Сервис') }}</span>
    <select name="driver" id="{{ $uid }}" onchange="aiDrv(this)">
      @foreach (\App\Models\AiProvider::DRIVERS as $k => [$n, $m])<option value="{{ $k }}" data-model="{{ $m }}" data-name="{{ $n }}" @selected(($p?->driver ?? 'gemini') === $k)>{{ __($n) }}</option>@endforeach
    </select></label>
  <label class="field"><span>{{ __('Название (видят компании)') }}</span><input name="name" value="{{ $p?->name ?? 'Google Gemini' }}" required data-f="name"></label>
  <label class="field"><span>{{ __('Модель') }}</span><input name="model" value="{{ $p?->model ?? 'gemini-2.5-flash' }}" required data-f="model"></label>
  <label class="field"><span>{{ __('Ключ API') }}</span><input type="password" name="api_key" autocomplete="off" @required(! $p) placeholder="{{ $p ? __('не менять (:k)', ['k' => $p->maskedKey()]) : 'sk-… / AIza…' }}"></label>
  <label class="field"><span>{{ __('Адрес API (только для совместимых)') }}</span><input name="base_url" value="{{ $p?->base_url }}" placeholder="https://api.example.com/v1"></label>
  <label class="field"><span>{{ __('Макс. длина ответа (токены)') }}</span><input type="number" name="max_tokens" value="{{ $p?->max_tokens ?? 4000 }}" min="256" max="64000" required></label>
  <label class="field"><span>{{ __('Порядок') }}</span><input type="number" name="sort" value="{{ $p?->sort ?? 0 }}"></label>
  <div class="field wide flex" style="gap:22px">
    <label class="switch"><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Включено') }}</label>
    <label class="switch"><input type="checkbox" name="is_default" value="1" @checked($p?->is_default ?? false)> {{ __('По умолчанию') }}</label>
  </div>
</div>
@once
@push('scripts')
<script>
function aiDrv(s) { const f = s.closest('form'), o = s.selectedOptions[0]; if (f.querySelector('input[name=api_key]').required) { f.querySelector('[data-f=model]').value = o.dataset.model; f.querySelector('[data-f=name]').value = o.dataset.name; } }
</script>
@endpush
@endonce
