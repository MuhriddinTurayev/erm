@extends('layouts.panel')
@section('title', __('Журнал работ'))
@section('actions')
<form method="get" class="flex"><input class="input" type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">@if ($date)<a class="btn btn-s" href="/company/journal">{{ __('Все дни') }}</a>@endif</form>
@if (auth()->user()->canEdit())<button class="btn btn-p btn-s" data-open="dlg-photo">{!! ic('camera') !!}{{ __('Фотоотчёт') }}</button>@endif
@endsection
@push('scripts')
<dialog class="modal" id="dlg-photo"><form method="post" action="/company/journal/photo" enctype="multipart/form-data" id="ph-form">@csrf
  <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="accuracy">
  <div class="modal-h"><h3>{!! ic('camera') !!}{{ __('Фотоотчёт с объекта') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field full"><span>{{ __('Объект') }}</span><select name="project_id" required>@foreach (\App\Services\StateWriter::projects($ws, auth()->user()->allowedObjects()) as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
    <label class="field full"><span>{{ __('Фото (можно сразу с камеры)') }}</span><input type="file" name="photos[]" accept="image/*" capture="environment" multiple required></label>
    <label class="field full"><span>{{ __('Что сделано') }}</span><textarea name="text" rows="3" maxlength="2000" placeholder="{{ __('Залили плиту 5 этажа, секция 2') }}"></textarea></label>
    <div class="full alert alert-info" style="margin:0" id="geo-st">{!! ic('map-pin') !!}<div>{{ __('Определяем местоположение…') }}</div></div>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('upload') !!}{{ __('Отправить') }}</button></div>
</form></dialog>
<script>
document.querySelector('[data-open="dlg-photo"]')?.addEventListener('click', () => {
  const f = document.getElementById('ph-form'), st = document.querySelector('#geo-st div');
  if (!navigator.geolocation) { st.textContent = @json(__('Геолокация недоступна на этом устройстве')); return; }
  navigator.geolocation.getCurrentPosition(p => {
    f.lat.value = p.coords.latitude.toFixed(7); f.lng.value = p.coords.longitude.toFixed(7); f.accuracy.value = Math.round(p.coords.accuracy);
    st.textContent = @json(__('Геометка добавлена')) + ': ' + f.lat.value + ', ' + f.lng.value + ' (±' + f.accuracy.value + ' м)';
  }, () => { st.textContent = @json(__('Без геометки: разрешите доступ к местоположению')); }, {enableHighAccuracy: true, timeout: 15000});
});
</script>
@endpush
@section('content')
<div class="alert alert-info">{!! ic('camera') !!}<div>{{ __('Прорабы присылают фото и описание работ через Telegram-бота — записи появляются здесь и в кабинете покупателя.') }} <a href="/me/telegram">{{ __('Подключить свой Telegram') }}</a></div></div>
@forelse ($logs->groupBy(fn ($l) => $l->date->format('Y-m-d')) as $day => $items)
<div class="card mt">
  <div class="card-h"><h3>{!! ic('calendar') !!}{{ \Carbon\Carbon::parse($day)->translatedFormat('d F Y') }}, {{ weekday($day) }}</h3><span class="badge">{{ $items->count() }}</span></div>
  @foreach ($items as $l)
  <div class="log-item">
    <div class="log-head"><span class="avatar">{{ initials($l->user?->name ?? '?') }}</span><div><b>{{ $l->user?->name ?? '—' }}</b><br><small>{{ $projects[$l->project_id] ?? $l->project_id }} · {{ $l->created_at->format('H:i') }}@if ($l->lat) · <a href="https://maps.google.com/?q={{ $l->lat }},{{ $l->lng }}" target="_blank" rel="noopener">{{ __('на карте') }}</a>@endif</small></div></div>
    @if ($l->text)<p class="log-text">{{ $l->text }}</p>@endif
    @if ($l->photos)
    <div class="gallery">@foreach ($l->photoUrls() as $src)<a href="{{ $src }}" target="_blank"><img src="{{ $src }}" loading="lazy" alt=""></a>@endforeach</div>
    @endif
  </div>
  @endforeach
</div>
@empty
<div class="card"><div class="empty">{!! ic('image') !!}<div>{{ __('Записей пока нет') }}</div></div></div>
@endforelse
<div class="mt">{{ $logs->links() }}</div>
@endsection
