@php $p = $p ?? null; $L = config('oftob.locales'); @endphp
<div class="form">
  <label class="field"><span>{{ __('Код (латиницей)') }}</span><input name="slug" value="{{ $p?->slug }}" required pattern="[A-Za-z0-9_\-]+"></label>
  <label class="field"><span>{{ __('Цена в месяц') }}</span><input type="number" name="price_month" value="{{ $p ? (int) $p->price_month : '' }}" min="0" step="1000" required></label>
  <label class="field"><span>{{ __('Цена за год') }}</span><input type="number" name="price_year" value="{{ $p ? (int) $p->price_year : '' }}" min="0" step="1000"><small>{{ __('0 — не продаётся на год') }}</small></label>
  <label class="field"><span>{{ __('Валюта') }}</span><input name="currency" value="{{ $p?->currency ?? 'сум' }}" required></label>
  <label class="field"><span>{{ __('Объектов (0 — без лимита)') }}</span><input type="number" name="max_objects" value="{{ $p?->max_objects ?? 1 }}" min="0" required></label>
  <label class="field"><span>{{ __('Пользователей (0 — без лимита)') }}</span><input type="number" name="max_users" value="{{ $p?->max_users ?? 5 }}" min="0" required></label>
  <label class="field"><span>{{ __('Запросов к ИИ в месяц (0 — без лимита)') }}</span><input type="number" name="ai_requests" value="{{ $p?->ai_requests ?? 100 }}" min="0" required></label>
  <label class="field"><span>{{ __('Пробный период, дней') }}</span><input type="number" name="trial_days" value="{{ $p?->trial_days ?? 14 }}" min="0" required></label>
  <label class="field"><span>{{ __('Порядок') }}</span><input type="number" name="sort" value="{{ $p?->sort ?? 0 }}"></label>
  @foreach ($L as $l => [$ln])
    <label class="field"><span>{{ __('Название') }} · {{ $ln }}</span><input name="name[{{ $l }}]" value="{{ $p?->name[$l] ?? '' }}" @required($l === 'ru')></label>
  @endforeach
  @foreach ($L as $l => [$ln])
    <label class="field"><span>{{ __('Описание') }} · {{ $ln }}</span><input name="description[{{ $l }}]" value="{{ $p?->description[$l] ?? '' }}"></label>
  @endforeach
  @foreach ($L as $l => [$ln])
    <label class="field"><span>{{ __('Пункты витрины (по одному в строке)') }} · {{ $ln }}</span><textarea name="features[{{ $l }}]" rows="4">{{ implode("\n", $p?->features[$l] ?? []) }}</textarea></label>
  @endforeach
  <div class="field wide flex" style="gap:22px">
    <label class="switch"><input type="checkbox" name="ai_enabled" value="1" @checked($p?->ai_enabled ?? true)> {{ __('ИИ-консультант') }}</label>
    <label class="switch"><input type="checkbox" name="daily_report" value="1" @checked($p?->daily_report ?? true)> {{ __('Ежедневный отчёт') }}</label>
    <label class="switch"><input type="checkbox" name="is_featured" value="1" @checked($p?->is_featured ?? false)> {{ __('«Популярный»') }}</label>
    <label class="switch"><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Продаётся') }}</label>
  </div>
</div>
