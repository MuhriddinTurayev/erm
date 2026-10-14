<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>3D — {{ $p['name'] ?? '' }}</title>
{{-- городские сцены сжаты gltfpack (EXT_meshopt_compression) — декодер лежит у нас --}}
<script>self.ModelViewerElement = self.ModelViewerElement || {}; self.ModelViewerElement.meshoptDecoderLocation = @json(asset('assets/vendor/meshopt_decoder.js'));</script>
<script type="module" src="https://cdn.jsdelivr.net/npm/@google/model-viewer@3.5.0/dist/model-viewer.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/pannellum/2.5.6/pannellum.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/pannellum/2.5.6/pannellum.js"></script>
<style>
  html,body{height:100%;margin:0;overflow:hidden;background:var(--bg)}
  .v-shell{display:grid;grid-template-columns:300px 1fr;height:100vh}
  .v-side{background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;min-height:0}
  .v-side .v-h{padding:14px 16px;border-bottom:1px solid var(--border-2)}
  .v-side .v-h a{color:var(--muted);text-decoration:none;font-size:13px;display:inline-flex;gap:6px;align-items:center}
  .v-side .v-h b{display:block;font-size:16px;margin-top:6px}
  .v-list{overflow:auto;padding:10px;flex:1}
  .v-sec{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;padding:12px 6px 6px}
  .v-item{display:flex;gap:10px;align-items:center;padding:8px;border-radius:10px;text-decoration:none;color:var(--text);border:1px solid transparent;margin-bottom:4px}
  .v-item:hover{background:var(--hover)}
  .v-item.on{border-color:var(--accent);background:var(--accent-soft)}
  .v-item .th{width:54px;height:40px;border-radius:7px;background:var(--sunken);display:grid;place-items:center;overflow:hidden;flex:none;color:var(--muted)}
  .v-item .th img{width:100%;height:100%;object-fit:cover}
  .v-item small{display:block;color:var(--muted);font-size:12px}
  .v-stage{position:relative;min-width:0;background:radial-gradient(circle at 50% 30%,color-mix(in srgb,var(--accent) 10%,var(--bg)),var(--bg) 70%)}
  model-viewer,#pano{width:100%;height:100%;display:block;--poster-color:transparent;--progress-bar-color:var(--accent)}
  .v-bar{position:absolute;left:50%;bottom:18px;transform:translateX(-50%);display:flex;gap:6px;background:color-mix(in srgb,var(--surface) 88%,transparent);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:14px;padding:6px;box-shadow:var(--sh-lg);z-index:5;flex-wrap:wrap;justify-content:center;max-width:calc(100% - 24px)}
  .v-bar button,.v-bar label{height:36px;border:0;background:none;color:var(--text);border-radius:9px;padding:0 10px;display:inline-flex;gap:6px;align-items:center;font:500 13px var(--font);cursor:pointer;white-space:nowrap}
  .v-bar button:hover{background:var(--hover)}
  .v-bar button.on{background:var(--accent);color:#fff}
  .v-bar input[type=range]{width:90px}
  .v-bar .seg{display:inline-flex;background:var(--sunken);border-radius:10px;padding:2px}
  .v-title{position:absolute;left:18px;top:16px;z-index:5;background:color-mix(in srgb,var(--surface) 88%,transparent);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:12px;padding:10px 14px;max-width:60%}
  .v-title b{display:block}.v-title small{color:var(--muted)}
  .v-empty{position:absolute;inset:0;display:grid;place-items:center;text-align:center;color:var(--muted);padding:24px}
  .v-load{position:absolute;inset:0;display:grid;place-items:center;pointer-events:none;color:var(--muted);font-size:14px}
  .v-toast{position:absolute;right:18px;top:16px;z-index:6;background:var(--ok);color:#fff;border-radius:10px;padding:10px 14px;font-weight:600;display:none}
  .ar-btn{position:absolute;right:16px;bottom:80px}
  /* комнаты внутри 3D-интерьера: кнопки слева, по нажатию камера переходит в комнату */
  .v-rooms{position:absolute;left:18px;top:86px;z-index:5;display:flex;flex-direction:column;gap:6px;max-height:calc(100% - 180px);overflow:auto;padding:2px}
  .v-rooms button{height:36px;border:1px solid var(--border);background:color-mix(in srgb,var(--surface) 90%,transparent);backdrop-filter:blur(10px);color:var(--text);border-radius:10px;padding:0 12px;font:500 13.5px var(--font);cursor:pointer;display:inline-flex;gap:8px;align-items:center;white-space:nowrap;text-align:left}
  .v-rooms button:hover{border-color:var(--accent)}.v-rooms button.on{background:var(--accent);border-color:var(--accent);color:#fff}
  .v-rooms button.add{border-style:dashed;color:var(--muted)}
  .v-rooms .x{margin-left:4px;opacity:.55;font-size:12px}.v-rooms .x:hover{opacity:1}
  .v-sub{font-size:11.5px;color:var(--muted);padding:6px 8px 2px;font-weight:600}
  @media (max-width:820px){.v-rooms{top:auto;bottom:84px;flex-direction:row;max-width:calc(100% - 36px);overflow-x:auto}}
  @media (max-width:820px){.v-shell{grid-template-columns:1fr;grid-template-rows:auto 1fr}.v-side{max-height:34vh;border-right:0;border-bottom:1px solid var(--border)}.v-list{display:flex;gap:6px;overflow-x:auto}.v-sec{display:none}.v-item{flex:none}.v-title{max-width:calc(100% - 36px)}}
</style>
</head>
<body>
<div class="v-shell">
  <aside class="v-side">
    <div class="v-h">
      @unless ($public)<a href="/company/objects/{{ $pid }}?tab=media">{!! ic('arrow-left', '', 14) !!}{{ __('К объекту') }}</a>@endunless
      <b>{{ $p['name'] ?? '' }}</b>@if (! empty($p['address']))<small class="muted">{{ $p['address'] }}</small>@endif
    </div>
    <div class="v-list">
      @php
        $groups = ['exterior' => __('Здание и фасад'), 'interior' => __('Интерьеры квартир'), 'pano' => __('Панорамы 360°')];
        $types = \App\Http\Controllers\Company\ObjectController::layouts($p);
        $keep = array_filter(request()->only(['o', 't']));   // в открытой шахматке — тот же объект и планировка
        $q = fn ($m) => '?'.http_build_query($keep + ['m' => $m->id]);
      @endphp
      @foreach ($groups as $k => $gn)
        @php $items = $media->where('kind', $k)->sortBy(fn ($m) => ($m->layout ? array_search($m->layout, array_keys($types)) : -1).'|'.$m->sort.'|'.$m->id); $lastL = false; @endphp
        @if ($items->count())<div class="v-sec">{{ $gn }}</div>@endif
        @foreach ($items as $m)
          @if ($k !== 'exterior' && $m->layout !== $lastL && $items->pluck('layout')->unique()->count() > 1)<div class="v-sub">{{ $m->layout && isset($types[$m->layout]) ? $types[$m->layout]['name'] : __('все планировки') }}</div>@endif
          @php $lastL = $m->layout; @endphp
          <a class="v-item {{ $cur && $cur->id === $m->id ? 'on' : '' }}" href="{{ $q($m) }}">
            <span class="th">@if ($m->poster)<img src="{{ $src($m, true) }}" alt="">@elseif ($m->kind === 'pano')<img src="{{ $src($m) }}" alt="" loading="lazy">@else{!! ic($m->kind === 'exterior' ? 'building-2' : 'sofa', '', 20) !!}@endif</span>
            <span><b style="font-size:14px">{{ $m->kind === 'pano' && ! empty($m->meta['room']) ? $m->meta['room'] : $m->title }}</b><small>{{ $m->layout && isset($types[$m->layout]) ? $types[$m->layout]['name'] : ($m->rooms ? $m->rooms.__('-комн.') : ($m->kind === 'exterior' ? __('весь дом') : __('все планировки'))) }}@if ($m->is3d()) · {{ file_size($m->size) }} @endif @if (! empty($m->meta['views'])) · {{ __('комнат') }}: {{ count($m->meta['views']) }}@endif</small></span>
          </a>
        @endforeach
      @endforeach
    </div>
  </aside>
  <main class="v-stage">
    @if (! $cur)
      <div class="v-empty"><div>{!! ic('box', '', 44) !!}<h2 style="color:var(--text)">{{ __('3D-моделей пока нет') }}</h2><p>{{ __('Загрузите модель здания в формате GLB в карточке объекта.') }}</p></div></div>
    @else
      <div class="v-title"><b>{{ $cur->title }}</b><small>{{ __(\App\Models\ObjectMedia::KINDS[$cur->kind][0]) }}{{ $cur->rooms ? ' · '.$cur->rooms.__('-комн.') : '' }}</small></div>
      <div class="v-toast" id="toast"></div>
      @if ($cur->is3d())
        @php $sky = $cur->kind === 'exterior' && ($cur->meta['sky'] ?? false); @endphp
        <model-viewer id="mv" src="{{ $src($cur) }}" @if ($cur->poster) poster="{{ $src($cur, true) }}" @endif camera-controls touch-action="pan-y" auto-rotate auto-rotate-delay="1500" rotation-per-second="18deg"
          shadow-intensity="1" shadow-softness="0.8" exposure="{{ $cur->meta['exposure'] ?? 1 }}" @if ($sky) skybox-image="{{ asset('assets/sky/day.hdr') }}" environment-image="{{ asset('assets/sky/day.hdr') }}" @else environment-image="neutral" @endif tone-mapping="aces" interaction-prompt="auto"
          @if (! empty($cur->meta['camera'])) camera-orbit="{{ $cur->meta['camera'] }}" @endif @if (! empty($cur->meta['target'])) camera-target="{{ $cur->meta['target'] }}" @endif
          @if ($cur->kind === 'interior') min-camera-orbit="auto auto 0.05m" max-camera-orbit="auto 180deg 400m" interpolation-decay="140" @else min-camera-orbit="auto auto 2m" max-camera-orbit="auto 95deg 3000m" @endif
          ar ar-modes="webxr scene-viewer quick-look" ar-scale="auto" alt="{{ $cur->title }}">
          <button slot="ar-button" class="btn btn-p ar-btn">{!! ic('smartphone') !!}{{ __('Посмотреть в AR') }}</button>
          <div class="v-load" slot="progress-bar" id="prog"></div>
        </model-viewer>
        @if ($cur->kind === 'interior' && (! empty($cur->meta['views']) || $canEdit))
        <div class="v-rooms" id="v-rooms">
          @foreach ((array) ($cur->meta['views'] ?? []) as $i => $v)<button type="button" data-view="{{ $i }}">{!! ic('door-open', '', 15) !!}{{ $v['name'] }}@if ($canEdit)<span class="x" data-delview="{{ $i }}" title="{{ __('Удалить') }}">✕</span>@endif</button>@endforeach
          @if ($canEdit)<button type="button" class="add" id="v-addroom" title="{{ __('Наведите камеру на комнату (колесо мыши — ближе) и сохраните её как кнопку') }}">+ {{ __('Комната (текущий вид)') }}</button>@endif
        </div>
        @endif
        <div class="v-bar">
          <button id="b-rot" class="on" title="{{ __('Вращение') }}">{!! ic('refresh-cw', '', 16) !!}{{ __('Вращение') }}</button>
          <button id="b-reset" title="{{ __('Исходный вид') }}">{!! ic('house', '', 16) !!}{{ __('Исходный вид') }}</button>
          <label title="{{ __('Освещение') }}">{!! ic('sun', '', 16) !!}<input type="range" id="b-exp" min="0.3" max="2.5" step="0.05" value="{{ $cur->meta['exposure'] ?? 1 }}"></label>
          @if (! empty($cur->meta['finished']))<span class="seg"><button id="st-cur" class="on">{!! ic('hard-hat', '', 16) !!}{{ __('Сейчас') }}</button><button id="st-fin">{!! ic('building-2', '', 16) !!}{{ __('Готовый дом') }}</button></span>@endif
          @if (! empty($cur->meta['daynight']))<span class="seg"><button id="dn-day" class="on">{!! ic('sun', '', 16) !!}{{ __('День') }}</button><button id="dn-night">{!! ic('moon', '', 16) !!}{{ __('Ночь') }}</button></span>@endif
          @if ($cur->kind === 'exterior' && empty($cur->meta['daynight']))<button id="b-sky" class="{{ $sky ? 'on' : '' }}" title="{{ __('Небо и солнечный свет') }}">{!! ic('sun', '', 16) !!}{{ __('Атмосфера') }}</button>@endif
          <button id="b-full" title="{{ __('На весь экран') }}">{!! ic('monitor', '', 16) !!}</button>
          @if ($canEdit)<button id="b-save" title="{{ __('Сохранить этот вид как главный и сделать превью') }}">{!! ic('save', '', 16) !!}{{ __('Сохранить вид') }}</button>@endif
        </div>
      @else
        <div id="pano"></div>
        <div class="v-bar"><button id="b-full">{!! ic('monitor', '', 16) !!}{{ __('На весь экран') }}</button></div>
      @endif
    @endif
  </main>
</div>
@if ($cur)
<script>
const stage = document.querySelector('.v-stage'), toast = t => { const el = document.getElementById('toast'); el.textContent = t; el.style.display = 'block'; setTimeout(() => el.style.display = 'none', 2200); };
document.getElementById('b-full')?.addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : stage.requestFullscreen());
@if ($cur->is3d())
const mv = document.getElementById('mv'), prog = document.getElementById('prog');
mv.addEventListener('progress', e => { const p = Math.round(e.detail.totalProgress * 100); prog.textContent = p < 100 ? @json(__('Загрузка модели')) + ' ' + p + '%' : ''; });
mv.addEventListener('error', () => { prog.textContent = @json(__('Не удалось открыть модель. Проверьте, что это файл GLB.')); });
document.getElementById('b-rot').addEventListener('click', e => { mv.autoRotate = !mv.autoRotate; e.currentTarget.classList.toggle('on', mv.autoRotate); });
document.getElementById('b-reset').addEventListener('click', () => { mv.cameraOrbit = @json($cur->meta['camera'] ?? 'auto auto auto'); mv.cameraTarget = @json($cur->meta['target'] ?? 'auto auto auto'); mv.fieldOfView = 'auto'; mv.jumpCameraToGoal(); });
document.getElementById('b-exp').addEventListener('input', e => mv.exposure = e.target.value);
const SKY = { day: @json(asset('assets/sky/day.hdr')), night: @json(asset('assets/sky/dusk.hdr')) }, EXP = { day: {{ $cur->meta['exposure'] ?? 0.75 }}, night: 0.32 };
const seg = (a, b) => { a.classList.add('on'); b.classList.remove('on'); };
// деревья, машины и опоры хранятся экземплярами («inst-…» материалы без вариантов) — ночью притеняем их сами
const dimInst = t => { for (const m of (mv.model?.materials || [])) { if (!m.name.startsWith('inst-')) continue; const pb = m.pbrMetallicRoughness; m._day ??= pb.baseColorFactor.slice(); pb.setBaseColorFactor(t === 'night' ? m._day.map((v, i) => i < 3 ? v * 0.22 : v) : m._day); } };
// model-viewer 3.5 не знает материал у InstancedMesh (нет связи с glTF) и падает при смене варианта — проставляем связь по имени материала
const fixInst = () => { const md = mv.model; if (!md) return; for (const sym of Object.getOwnPropertySymbols(md)) { const arr = md[sym]; if (!Array.isArray(arr)) continue;
  for (const p of arr) { if (!p?.mesh?.isInstancedMesh || !(p.materials instanceof Map) || p.materials.size) continue; const mm = md.materials.find(m => m.name === p.mesh.material.name); if (mm) { p.materials.set(mm.index, mm); p.initialMaterialIdx = p.activeMaterialIdx = mm.index; } } } };
mv.addEventListener('load', fixInst);
const VN = { day: @json($cur->meta['vday'] ?? 'day'), night: @json($cur->meta['vnight'] ?? 'night') };
const setTime = t => { fixInst(); mv.variantName = VN[t]; mv.skyboxImage = SKY[t]; mv.environmentImage = SKY[t]; mv.exposure = EXP[t]; document.getElementById('b-exp').value = EXP[t]; dimInst(t); };
let timeNow = 'day';
document.getElementById('dn-day')?.addEventListener('click', e => { seg(e.currentTarget, document.getElementById('dn-night')); timeNow = 'day'; setTime('day'); });
document.getElementById('dn-night')?.addEventListener('click', e => { seg(e.currentTarget, document.getElementById('dn-day')); timeNow = 'night'; setTime('night'); });
const orbitKeep = () => { const o = mv.getCameraOrbit(); return `${o.theta}rad ${o.phi}rad ${o.radius}m`; };
const setState = fin => { const orb = orbitKeep(), tg = mv.getCameraTarget(); mv.variantName = null; mv.src = @json($src($cur)) + (fin ? (@json($src($cur)).includes('?') ? '&' : '?') + 'v=finished' : '');
  mv.addEventListener('load', () => { mv.cameraOrbit = orb; mv.cameraTarget = `${tg.x}m ${tg.y}m ${tg.z}m`; mv.jumpCameraToGoal(); if (timeNow === 'night') setTime('night'); }, { once: true }); };
document.getElementById('st-cur')?.addEventListener('click', e => { seg(e.currentTarget, document.getElementById('st-fin')); setState(false); });
document.getElementById('st-fin')?.addEventListener('click', e => { seg(e.currentTarget, document.getElementById('st-cur')); setState(true); });
document.getElementById('b-sky')?.addEventListener('click', e => { const on = !e.currentTarget.classList.contains('on'), hdr = @json(asset('assets/sky/day.hdr'));
  e.currentTarget.classList.toggle('on', on); mv.skyboxImage = on ? hdr : null; mv.environmentImage = on ? hdr : 'neutral'; });
// комнаты интерьера: камера переходит внутрь выбранной комнаты
const VIEWS = @json(array_values((array) ($cur->meta['views'] ?? [])));
document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', e => {
  if (e.target.closest('[data-delview]')) return;
  const v = VIEWS[+b.dataset.view]; if (!v) return;
  mv.autoRotate = false; document.getElementById('b-rot').classList.remove('on');
  mv.cameraTarget = v.target; mv.cameraOrbit = v.orbit; if (v.fov) mv.fieldOfView = v.fov;
  document.querySelectorAll('[data-view]').forEach(x => x.classList.toggle('on', x === b));
}));
@if ($canEdit)
const saveViews = async list => {
  const fd = new FormData(); fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
  fd.append('title', @json($cur->title)); fd.append('rooms', @json($cur->rooms ?? '')); fd.append('sort', @json($cur->sort)); fd.append('is_public', @json($cur->is_public ? 1 : 0));
  fd.append('views', JSON.stringify(list));
  const r = await fetch('/company/media/{{ $cur->id }}', { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
  if (r.ok) location.reload(); else toast(@json(__('Не удалось сохранить')));
};
document.getElementById('v-addroom')?.addEventListener('click', async () => {
  const name = await ui.prompt(@json(__('Название комнаты')), { placeholder: @json(__('Гостиная, кухня, спальня…')), ok: @json(__('Сохранить')) });
  if (!name || !name.trim()) return;
  const o = mv.getCameraOrbit(), t = mv.getCameraTarget();
  await saveViews(VIEWS.concat([{ name: name.trim(), orbit: `${o.theta}rad ${o.phi}rad ${o.radius}m`, target: `${t.x}m ${t.y}m ${t.z}m`, fov: `${mv.getFieldOfView()}deg` }]));
});
document.querySelectorAll('[data-delview]').forEach(x => x.addEventListener('click', async e => {
  e.stopPropagation();
  if (await ui.confirm(@json(__('Удалить эту комнату из списка?')))) await saveViews(VIEWS.filter((v, i) => i !== +x.dataset.delview));
}));
document.getElementById('b-save').addEventListener('click', async () => {
  const o = mv.getCameraOrbit(), cam = `${o.theta}rad ${o.phi}rad ${o.radius}m`;
  const blob = await mv.toBlob({ mimeType: 'image/jpeg', qualityArgument: 0.82, idealAspect: true });
  const poster = await new Promise(r => { const fr = new FileReader(); fr.onload = () => r(fr.result); fr.readAsDataURL(blob); });
  const fd = new FormData(); fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
  fd.append('title', @json($cur->title)); fd.append('rooms', @json($cur->rooms ?? '')); fd.append('sort', @json($cur->sort)); fd.append('is_public', @json($cur->is_public ? 1 : 0));
  fd.append('camera', cam); fd.append('exposure', mv.exposure); fd.append('poster', poster);
  const r = await fetch('/company/media/{{ $cur->id }}', { method: 'POST', body: fd, headers: { Accept: 'application/json' } });
  toast(r.ok ? @json(__('Вид и превью сохранены')) : @json(__('Не удалось сохранить')));
});
@endif
@else
pannellum.viewer('pano', { type: 'equirectangular', panorama: @json($src($cur)), autoLoad: true, autoRotate: -2, showControls: true, compass: false, hfov: 105 });
@endif
</script>
@endif
</body>
</html>
