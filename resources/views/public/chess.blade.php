@extends('layouts.public')
@section('title', ($p['name'] ?? __('Объект')).' — '.__('квартиры и цены').' — '.$ws->name)
@section('robots', 'index,follow')
@section('content')
@php
  $free = collect($apts)->where('status', 'free');
  $sections = collect($apts)->groupBy('section');
  $st = ['free' => __('Свободна'), 'booked' => __('Бронь'), 'sold' => __('Продана')];
  $KS = ['commercial' => 'K', 'office' => 'O', 'parking' => 'P', 'storage' => 'S'];
  $KN = array_map(fn ($k) => __($k), \App\Services\Inventory::KINDS);
  // дом в 3D привязан — выбранная квартира подсвечивается прямо на модели
  $locModel = \App\Services\Locate::model($ws->id, (string) $p['id'], null, true);
  $loc = $locModel && ! empty($locModel->meta['locate']['blocks']);
  $label = fn ($a) => $a['rooms'] ? __('Кв.').' '.$a['num'].' · '.$a['rooms'].__('-комн.') : ($KN[$a['kind'] ?? 'commercial'] ?? __('Коммерция')).' '.$a['num'];
@endphp
@if (count($projects) > 1)
<div class="tabs">@foreach ($projects as $x)<a href="?o={{ $x['id'] }}" class="{{ ($x['id'] ?? '') === ($p['id'] ?? '') ? 'on' : '' }}">{{ $x['name'] ?? '' }}</a>@endforeach</div>
@endif
<section class="pub-hero">
  <div>
    <h1>{{ $p['name'] ?? '' }}</h1>
    @if (! empty($p['address']))<p class="muted">{!! ic('map-pin') !!} {{ $p['address'] }}</p>@endif
    @if (\App\Models\ObjectMedia::of($ws->id)->where('project_id', (string) $p['id'])->where('is_public', true)->whereIn('kind', ['exterior', 'interior', 'pano'])->exists())<a class="btn btn-p" href="/s/{{ $ws->public_key }}/3d?o={{ $p['id'] }}" target="_blank">{!! ic('box') !!}{{ __('Смотреть дом в 3D') }}</a>@endif
  </div>
  <div class="kpis">
    <div class="kpi"><div class="k-l">{{ __('Свободно квартир') }}</div><div class="k-v">{{ $free->where('rooms', '>', 0)->count() }}</div></div>
    <div class="kpi"><div class="k-l">{{ __('Цена от') }}</div><div class="k-v">{{ $free->count() ? money_s($free->min('price'), $currency) : '—' }}</div></div>
    <div class="kpi"><div class="k-l">{{ __('Готовность') }}</div><div class="k-v">{{ $progress }}%</div><div class="prog"><i style="width:{{ $progress }}%"></i></div></div>
  </div>
</section>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('layout-grid') !!}{{ __('Шахматка') }}</h3>
    <div class="flex chess-legend"><span><i class="st-free"></i>{{ $st['free'] }}</span><span><i class="st-booked"></i>{{ $st['booked'] }}</span><span><i class="st-sold"></i>{{ $st['sold'] }}</span></div></div>
  <div class="pub-sections">
  @foreach ($sections as $sec => $list)
    <div class="pub-sec">
      <div class="muted" style="margin-bottom:8px">{{ \App\Services\Inventory::secLabel($list->first() + ['entr' => $sec]) }}</div>
      @foreach ($list->groupBy('floor')->sortKeysDesc() as $fl => $row)
      <div class="pub-floor"><span class="fl">{{ $fl }}</span>
        @foreach ($row as $a)
          <button type="button" class="pub-apt st-{{ $a['status'] }}" @if ($a['status'] === 'free') data-id="{{ $a['id'] }}" data-apt="{{ $label($a) }} · {{ $a['area'] }} {{ __('м²') }} · {{ money_s($a['price'], $currency) }}" data-rooms="{{ $a['rooms'] }}"
            data-plan="{{ $plans[$a['type'] ?? ''] ?? '' }}" data-tour="{{ ! empty($tours[$a['type'] ?? '']) ? '/s/'.$ws->public_key.'/3d?o='.$p['id'].'&t='.$a['type'] : '' }}" data-type="{{ $a['typeName'] ?? '' }}" @else disabled @endif
            title="{{ $label($a) }} · {{ $a['area'] }} {{ __('м²') }}{{ $a['status'] === 'free' ? ' · '.money_s($a['price'], $currency) : '' }}">
            <b>{{ $a['rooms'] ?: ($KS[$a['kind'] ?? 'commercial'] ?? 'K') }}</b><small>{{ $a['area'] }}</small>
          </button>
        @endforeach
      </div>
      @endforeach
    </div>
  @endforeach
  </div>
</div>

<div class="card mt" id="lead">
  <div class="card-h"><h3>{!! ic('phone') !!}{{ __('Оставить заявку') }}</h3></div>
  @if ($loc)<div class="ap-3d" id="pick-loc" hidden><iframe id="pick-fr" title="{{ __('Квартира в 3D') }}" allow="fullscreen"></iframe></div>@endif
  <div id="pick" class="pub-pick" hidden>
    <img id="pick-plan" alt="{{ __('Планировка') }}" hidden>
    <div><b id="pick-type"></b><a class="btn btn-s" id="pick-3d" target="_blank" hidden>{!! ic('box') !!}{{ __('Квартира в 3D и 360°') }}</a></div>
  </div>
  <form method="post" class="form">
    @csrf
    <label class="field wide"><span>{{ __('Квартира') }}</span><input name="apt" id="apt" readonly placeholder="{{ __('Выберите свободную квартиру в шахматке') }}"></label>
    <input type="hidden" name="rooms" id="rooms">
    <label class="field"><span>{{ __('Ваше имя') }}</span><input name="name" required maxlength="80"></label>
    <label class="field"><span>{{ __('Телефон') }}</span><input name="phone" required maxlength="40" inputmode="tel" placeholder="+998"></label>
    <div class="wide"><button class="btn btn-p btn-l">{!! ic('send') !!}{{ __('Отправить заявку') }}</button></div>
  </form>
</div>
@endsection
@push('scripts')
<script>
document.addEventListener('click', e => {
  const b = e.target.closest('[data-apt]'); if (!b) return;
  document.querySelectorAll('.pub-apt.sel').forEach(x => x.classList.remove('sel')); b.classList.add('sel');
  document.getElementById('apt').value = b.dataset.apt; document.getElementById('rooms').value = b.dataset.rooms;
  // планировка выбранной квартиры: чертёж и 3D / 360°
  const plan = document.getElementById('pick-plan'), tour = document.getElementById('pick-3d');
  plan.hidden = !b.dataset.plan; if (b.dataset.plan) plan.src = b.dataset.plan;
  tour.hidden = !b.dataset.tour; if (b.dataset.tour) tour.href = b.dataset.tour;
  document.getElementById('pick-type').textContent = b.dataset.type || '';
  document.getElementById('pick').hidden = !(b.dataset.plan || b.dataset.tour || b.dataset.type);
  // где квартира в доме: модель грузится один раз, дальше квартиры переключаются сообщением
  const fr = document.getElementById('pick-fr');
  if (fr && b.dataset.id) {
    document.getElementById('pick-loc').hidden = false; want = b.dataset.id;
    if (!fr.src) fr.src = `/s/{{ $ws->public_key }}/locate?embed=1&o={{ $p['id'] }}&u=${encodeURIComponent(want)}`;
    else if (ready) fr.contentWindow.postMessage({ type: 'oftob-locate', unit: want }, location.origin);
  }
  document.getElementById('lead').scrollIntoView({behavior: 'smooth'});
});
let ready = false, want = null;
window.addEventListener('message', e => {
  if (e.origin !== location.origin || !e.data || e.data.type !== 'oftob-locate-ready') return;
  ready = true; if (want) document.getElementById('pick-fr').contentWindow.postMessage({ type: 'oftob-locate', unit: want }, location.origin);
});
</script>
@endpush
