@php
  $public = $public ?? false;
  $auto = $auto ?? false;
  $aiUrl = $aiUrl ?? null;
  $cal = (array) ($model?->meta['locate']['blocks'] ?? []);
  $blocks = $data['blocks'];
  $multi = count($blocks) > 1;
  $T = [
    'kv' => __('Кв.'), 'fl' => __('этаж'), 'en' => __('подъезд'), 'rooms' => __('-комн.'), 'm2' => __('м²'), 'k' => __('Коммерция'),
    'approx' => __('Примерное место: корпус ещё не привязан к 3D-модели.'), 'setup' => __('Привязать'), 'saved' => __('Сохранено'),
    'err' => __('Не удалось сохранить'), 'load' => __('Загружаю 3D-модель…'), 'fail' => __('Не удалось открыть 3D-модель'), 'none' => __('Выберите квартиру'), 'pickHint' => __('Наведите на квартиру на фасаде — нажмите, чтобы выбрать.'),
    'st' => ['free' => __('Свободна'), 'booked' => __('Бронь'), 'sold' => __('Продана'), 'reserve' => __('Не в продаже')],
    // автопривязка
    'aiBusy' => __('ИИ изучает модель: где дом, этажи, входы в подъезды…'), 'aiRun' => __('ИИ расставляет квартиры на модели…'),
    'aiDone' => __('Квартиры расставил ИИ. Проверьте номера на фасаде; если что-то не так — поправьте и сохраните.'),
    'geoDone' => __('Квартиры расставлены по форме модели. Проверьте номера на фасаде и сохраните.'),
    'aiOff' => __('ИИ не подключён — квартиры расставлены по форме модели.'), 'aiLimit' => __('Лимит ИИ на месяц исчерпан — квартиры расставлены по форме модели.'),
    'aiFail' => __('ИИ сейчас не ответил — квартиры расставлены по форме модели. Повторите позже кнопкой «Расставить квартиры с ИИ».'),
    'aiAuto' => __('Квартиры расставил ИИ.'), 'check' => __('Проверить привязку'), 'autoSaved' => __('Привязка сохранена'),
    'wFloors' => __('В модели :m эт., а в объекте :n эт. Квартиры показаны на этаже модели той же высоты (несколько этажей объекта — на одном этаже модели). Для точного показа загрузите модель именно этого дома.'),
    'wDoors' => __('Входов на фасаде: :d, подъездов в объекте: :e. Подъезды поделены поровну — передвиньте синие точки к дверям.'),
    'wNotBuilding' => __('Похоже, в модели не один дом (район, земля или интерьер). Лучше загрузить модель только самого здания.'),
    'wBlocks' => __('В модели найдено меньше домов, чем корпусов в объекте — поставьте рамки остальных корпусов вручную.'),
    'fail2' => __('Не получилось разобрать модель — поставьте рамку вручную.'),
  ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>{{ __('Квартира в 3D') }} — {{ $p['name'] ?? '' }}</title>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"}}</script>
<style>
  html,body{height:100%;margin:0;overflow:hidden;background:var(--bg)}
  .lc{position:fixed;inset:0;background:radial-gradient(circle at 50% 25%,color-mix(in srgb,var(--accent) 10%,var(--bg)),var(--bg) 70%)}
  .lc canvas{display:block;touch-action:none}
  .lc-labels{position:absolute;inset:0;pointer-events:none}
  .lc-pin{display:flex;flex-direction:column;align-items:center;gap:1px;background:var(--accent);color:#fff;border-radius:12px;padding:7px 12px;box-shadow:0 10px 26px -8px rgba(76,47,214,.7);font:600 12px var(--font);white-space:nowrap;position:relative}
  .lc-pin b{font:700 14px var(--font-head)}
  .lc-pin span{opacity:.9;font-weight:500}
  .lc-pin::after{content:'';position:absolute;left:50%;bottom:-6px;width:12px;height:12px;background:var(--accent);transform:translateX(-50%) rotate(45deg);border-radius:2px}
  .lc-entr{font:700 13px var(--font-head);color:#fff;background:var(--accent-2);border-radius:99px;padding:4px 12px;box-shadow:0 6px 16px -6px rgba(59,130,246,.7);white-space:nowrap;pointer-events:none}
  .lc-leg{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;font-size:12.5px;color:var(--text-2)}.lc-leg span{display:inline-flex;gap:6px;align-items:center}.lc-leg i{width:11px;height:11px;border-radius:3px}
  .lc-num{font:700 11px var(--font);color:#fff;background:rgba(31,29,54,.72);border-radius:6px;padding:1px 5px;pointer-events:none}
  .lc-card{position:absolute;left:16px;top:16px;z-index:5;max-width:min(420px,calc(100% - 32px));background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(12px);border:1px solid var(--border-2);border-radius:14px;padding:12px 16px;box-shadow:var(--sh)}
  .lc-card a{font-size:13px;color:var(--muted);display:inline-flex;gap:6px;align-items:center;text-decoration:none;margin-bottom:6px}
  .lc-card b{display:block;font:700 16px var(--font-head)}
  .lc-card .lc-u{margin-top:6px;font-size:14px;color:var(--text-2)}
  .lc-card .lc-u strong{color:var(--accent-text);font-family:var(--font-head)}
  .lc-note{margin-top:8px;font-size:12.5px;color:var(--warn-text);background:var(--warn-soft);border-radius:9px;padding:6px 9px}
  .lc-note.ai{color:var(--accent-text);background:var(--accent-soft)}
  .lc-note a{color:inherit;font-weight:700;text-decoration:underline;margin:0}
  .lc-bar{position:absolute;left:50%;bottom:16px;transform:translateX(-50%);z-index:5;display:flex;gap:4px;background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(12px);border:1px solid var(--border-2);border-radius:14px;padding:5px;box-shadow:var(--sh);max-width:calc(100% - 24px);overflow-x:auto}
  .lc-bar button,.lc-bar a{height:36px;border:0;background:none;color:var(--text);border-radius:10px;padding:0 12px;display:inline-flex;gap:7px;align-items:center;font:600 13px var(--font);cursor:pointer;white-space:nowrap;text-decoration:none}
  .lc-bar button:hover,.lc-bar a:hover{background:var(--hover);color:var(--accent-text)}
  .lc-bar button.on{background:var(--accent);color:#fff}
  .lc-load{position:absolute;inset:0;display:grid;place-items:center;color:var(--muted);font-size:14px;pointer-events:none;z-index:4}
  .lc-load div{display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;max-width:320px}
  .lc-load i{width:30px;height:30px;border-radius:50%;border:3px solid var(--accent-soft);border-top-color:var(--accent);animation:spin .8s linear infinite}
  .lc-load em{font-style:normal;font-variant-numeric:tabular-nums}
  .lc-busy{position:absolute;inset:0;z-index:7;display:grid;place-items:center;background:color-mix(in srgb,var(--bg) 45%,transparent);backdrop-filter:blur(2px)}
  .lc-busy>div{display:flex;flex-direction:column;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border-2);border-radius:16px;padding:20px 26px;box-shadow:var(--sh-lg);max-width:340px;text-align:center;font-size:14px;color:var(--text-2)}
  .lc-busy i{width:30px;height:30px;border-radius:50%;border:3px solid var(--accent-soft);border-top-color:var(--accent);animation:spin .8s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .lc-empty{position:absolute;inset:0;display:grid;place-items:center;text-align:center;padding:24px;color:var(--muted)}
  .lc-empty>div{max-width:420px;display:grid;gap:12px;justify-items:center}
  .lc-empty .ic{width:40px;height:40px;color:var(--faint)}
  /* настройка рамки */
  .lc-side{position:absolute;right:16px;top:16px;bottom:16px;z-index:6;width:330px;overflow:auto;background:var(--surface);border:1px solid var(--border-2);border-radius:16px;box-shadow:var(--sh-lg);padding:16px;display:flex;flex-direction:column;gap:14px}
  .lc-side h3{font-size:15.5px;display:flex;gap:8px;align-items:center;margin:0}.lc-side h3 .ic{color:var(--accent)}
  .lc-side p{margin:0;font-size:13px;color:var(--muted);line-height:1.5}
  .lc-side .seg{display:flex;gap:3px;background:var(--sunken);border-radius:11px;padding:3px}
  .lc-side .seg button{flex:1;height:32px;border:0;background:none;border-radius:8px;font:600 12.5px var(--font);color:var(--muted);cursor:pointer;padding:0 6px}
  .lc-side .seg button.on{background:var(--surface);color:var(--accent-text);box-shadow:var(--sh-sm),0 0 0 1px var(--border)}
  .lc-side .row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
  .lc-side .row2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .lc-side .field input,.lc-side .field select{height:36px}
  .lc-side .field>span{font-size:12px}
  .lc-side .btn{height:auto;min-height:36px;padding:6px 10px;white-space:normal;line-height:1.25;text-align:center}
  .lc-side .row2.one{grid-template-columns:1fr}
  .lc-sec{display:grid;gap:8px;padding-top:12px;border-top:1px solid var(--border)}
  .lc-lab{font:700 12px var(--font);color:var(--text-2);text-transform:uppercase;letter-spacing:.04em}
  .lc-ai{display:grid;gap:8px;padding:12px;border-radius:12px;background:linear-gradient(135deg,color-mix(in srgb,var(--accent) 12%,var(--surface)),var(--surface));border:1px solid color-mix(in srgb,var(--accent) 22%,var(--border))}
  .lc-ai-h{display:flex;gap:8px;align-items:center;font:700 14px var(--font-head);color:var(--accent-text)}
  .lc-ai p{color:var(--text-2)}
  .lc-warn{margin:0;padding:0;list-style:none;display:grid;gap:6px}
  .lc-warn li{font-size:12.5px;line-height:1.45;color:var(--warn-text);background:var(--warn-soft);border-radius:9px;padding:7px 9px}
  .lc-hint{font-size:12.5px!important;color:var(--text-2)!important}
  .lc-hint i{display:inline-block;width:10px;height:10px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px var(--border-2);vertical-align:-1px;margin:0 3px 0 2px}
  .lc-step{display:flex;gap:4px}.lc-step input{min-width:0;text-align:center}
  .lc-step button{width:34px;flex:none;height:36px;border:1px solid var(--border-2);background:var(--surface);border-radius:9px;font:700 16px var(--font);color:var(--text);cursor:pointer}
  .lc-step button:hover{border-color:var(--accent);color:var(--accent-text)}
  .lc-more summary{cursor:pointer;font:600 13px var(--font);color:var(--muted)}
  .lc-more[open] summary{margin-bottom:8px}
  .lc-foot{margin-top:auto;display:grid;gap:8px;padding-top:12px;border-top:1px solid var(--border)}
  @media (max-width:760px){.lc-side{left:8px;right:8px;top:auto;bottom:70px;width:auto;max-height:50vh}.lc-card{left:8px;top:8px;padding:9px 12px}.lc-card b{font-size:14px}}
  body.embed .lc-card a.back,body.embed .lc-card>b{display:none}
  body.embed .lc-card{left:10px;top:10px;padding:8px 12px;border-radius:12px}
  body.embed .lc-card .lc-u{margin:0;font-size:13px}
  body.embed .lc-bar{bottom:10px;padding:4px}
  body.embed .lc-bar button,body.embed .lc-bar a{height:32px;padding:0 10px;font-size:12.5px}
</style>
</head>
<body class="{{ $embed ? 'embed' : '' }}">
<div class="lc" id="lc"></div>

<div class="lc-card">
  @unless ($embed)<a class="back" href="{{ $back }}">{!! ic('arrow-left', '', 14) !!}{{ $public ? __('Назад') : __('К объекту') }}</a>@endunless
  <b>{{ $p['name'] ?? '' }}</b>
  <div class="lc-u" id="lcU">{{ $calibrate ? __('Привязка корпусов к 3D-модели') : '' }}</div>
  <div class="lc-note" id="lcNote" hidden></div>
</div>

@if (! $model)
  <div class="lc-empty"><div>{!! ic('box') !!}
    @if ($public)
      <b>{{ __('3D-модель дома скоро появится') }}</b><span>{{ __('Пока посмотрите расположение квартиры на шахматке.') }}</span>
    @else
      <b>{{ __('3D-модель здания ещё не загружена') }}</b><span>{{ __('Загрузите её: Объекты и 3D → объект → «3D, панорамы, планировки» → «Фасад и здание 3D». После этого квартиры можно будет показывать прямо на доме.') }}</span>
      <a class="btn btn-p" href="/company/objects/{{ $pid }}?tab=media" target="_top">{!! ic('upload') !!}{{ __('Загрузить 3D-модель') }}</a>
    @endif
  </div></div>
@else
  <div class="lc-load" id="lcLoad"><div><i></i><span>{{ $T['load'] }}</span><em id="lcPct"></em></div></div>
  <div class="lc-busy" id="lcBusy" hidden><div><i></i><span id="lcBusyT">{{ $T['aiBusy'] }}</span></div></div>
  <div class="lc-bar" id="lcBar">
    <button type="button" data-act="unit" title="{{ __('Показать квартиру') }}">{!! ic('target', '', 16) !!}<span>{{ __('Квартира') }}</span></button>
    <button type="button" data-act="house" title="{{ __('Весь дом') }}">{!! ic('building-2', '', 16) !!}<span>{{ __('Весь дом') }}</span></button>
    @if ($canEdit && ! $calibrate)<a href="/company/objects/{{ $pid }}/locate?calibrate=1&m={{ $model->id }}" target="_top">{!! ic('sliders-horizontal', '', 16) !!}<span>{{ __('Настроить привязку') }}</span></a>@endif
    @if ($embed)<a href="#" data-act="full" target="_blank">{!! ic('external-link', '', 16) !!}<span>{{ __('Во весь экран') }}</span></a>@endif
  </div>
  @if ($calibrate)
  <aside class="lc-side" id="lcSide">
    <h3>{!! ic('layers') !!}{{ __('Привязка корпуса к 3D-модели') }}</h3>
    @if ($multi)
    <label class="field"><span>{{ __('Корпус') }}</span><select id="cBlock">@foreach ($blocks as $bid => $b)<option value="{{ $bid }}">{{ $b['name'] ?: __('Корпус') }} · {{ count($b['entr']) }} {{ __('подъезд') }}@if (isset($cal[$bid])) ✓@endif</option>@endforeach</select></label>
    @endif
    <div class="lc-ai">
      <div class="lc-ai-h">{!! ic('sparkles', '', 17) !!}{{ __('Автопривязка ИИ') }}</div>
      <p id="cAiText">{{ __('ИИ изучит модель: где дом, сколько в нём этажей, где входы в подъезды — и сам расставит квартиры. Вам останется проверить номера на фасаде.') }}</p>
      <ul class="lc-warn" id="cAiWarn" hidden></ul>
      <button type="button" class="btn btn-p btn-s" id="cAuto">{!! ic('sparkles', '', 15) !!}<span>{{ __('Расставить квартиры с ИИ') }}</span></button>
    </div>
    <div class="lc-sec">
      <span class="lc-lab">{{ __('Ракурс') }}</span>
      <div class="seg" id="cView"><button type="button" data-v="3d" class="on">3D</button><button type="button" data-v="front">{{ __('Спереди') }}</button><button type="button" data-v="side">{{ __('Сбоку') }}</button><button type="button" data-v="top">{{ __('Сверху') }}</button></div>
    </div>
    <div class="lc-sec">
      <span class="lc-lab">{{ __('Поправить вручную') }}</span>
      <p class="lc-hint">{{ __('Тяните точки на рамке') }}: <i style="background:var(--accent)"></i>{{ __('стороны рамки') }}, <i style="background:var(--accent-2)"></i>{{ __('границы подъездов') }}, <i style="background:#f59e0b"></i>{{ __('верх 1-го этажа') }}. {{ __('Двойной щелчок по дому — перенести рамку туда.') }}</p>
      <div class="seg" id="cMode"><button type="button" data-m="translate" class="on">{{ __('Двигать') }}</button><button type="button" data-m="rotate">{{ __('Поворот') }}</button></div>
      <div class="row2">
        <button type="button" class="btn btn-s" id="cSnap" title="{{ __('Рамка встанет по стенам дома там, где она сейчас: без крыши и земли') }}">{!! ic('scan-line', '', 15) !!}{{ __('Прижать к дому') }}</button>
        <button type="button" class="btn btn-s" id="cUndo" disabled>{!! ic('undo-2', '', 15) !!}{{ __('Отменить') }}</button>
      </div>
    </div>
    <div class="lc-sec">
      <div class="row2">
        <label class="field"><span>{{ __('Этажей в рамке') }}</span><div class="lc-step"><button type="button" data-d="-1" aria-label="−">−</button><input type="number" step="1" min="1" max="200" id="cF"><button type="button" data-d="1" aria-label="+">+</button></div></label>
        <label class="field"><span>{{ __('Высота 1-го этажа, м') }}</span><input type="number" step="0.1" min="0" max="30" id="cG" placeholder="{{ __('как остальные') }}"></label>
      </div>
      <div class="row2">
        <label class="field"><span>{{ __('Квартиры на этаже') }}</span><select id="cRows"><option value="2">{{ __('с двух сторон') }}</option><option value="1">{{ __('на одну сторону') }}</option></select></label>
        <label class="field"><span>{{ __('Задняя сторона') }}</span><select id="cBack"><option value="rev">{{ __('нумерация по кругу') }}</option><option value="fwd">{{ __('как лицевая') }}</option></select></label>
      </div>
      <div class="row2">
        <button type="button" class="btn btn-s" id="cFlipX">{!! ic('arrow-left-right', '', 15) !!}{{ __('Подъезды наоборот') }}</button>
        <button type="button" class="btn btn-s" id="cFlipZ">{!! ic('refresh-cw', '', 15) !!}{{ __('Сменить фасад') }}</button>
      </div>
      @if (collect($blocks)->contains(fn ($b) => count($b['entr']) > 1))
      <button type="button" class="btn btn-s btn-ghost" id="cEven">{!! ic('layout-grid', '', 15) !!}{{ __('Подъезды поровну') }}</button>
      @endif
      <details class="lc-more"><summary>{{ __('Точные размеры') }}</summary>
        <div class="row">
          <label class="field"><span>{{ __('Длина, м') }}</span><input type="number" step="0.1" min="0.1" id="cL"></label>
          <label class="field"><span>{{ __('Высота, м') }}</span><input type="number" step="0.1" min="0.1" id="cH"></label>
          <label class="field"><span>{{ __('Глубина, м') }}</span><input type="number" step="0.1" min="0.1" id="cD"></label>
        </div>
        <label class="field" style="margin-top:8px"><span>{{ __('Поворот, °') }}</span><input type="number" step="1" id="cR"></label>
      </details>
    </div>
    <div class="lc-foot">
      <button type="button" class="btn btn-p" id="cSave">{!! ic('check') !!}{{ __('Сохранить привязку') }}</button>
      <a class="btn" href="/company/objects/{{ $pid }}/locate?m={{ $model->id }}">{!! ic('eye', '', 16) !!}{{ __('Проверить на квартире') }}</a>
    </div>
  </aside>
  @endif
@endif

@if ($model)
<script type="module">
import { start } from '{{ asset('assets/locate3d.js') }}?v={{ @filemtime(public_path('assets/locate3d.js')) }}';
const T = @json($T), DATA = @json($data), CAL = @json((object) $cal), UNIT = @json($unit), CALIB = @json((bool) $calibrate);
const CAN = @json((bool) $canEdit), AUTO = @json((bool) $auto), AI_URL = @json($aiUrl), SAVE_URL = @json($saveUrl);
const SETUP = @json($canEdit ? '/company/objects/'.$pid.'/locate?calibrate=1&m='.$model->id : null);
const $ = (s) => document.querySelector(s);
const CSRF = document.querySelector('meta[name=csrf-token]').content;
// подписи помещений на языке интерфейса
DATA.units.forEach((u) => {
  const B = DATA.blocks[u.b] || { name: '', entr: [] }, en = B.entr[u.e] || (u.e + 1);
  u.label = u.r ? `${T.kv} ${u.num}` : `${T.k} ${u.num}`;
  u.sub = [(B.name && Object.keys(DATA.blocks).length > 1 ? B.name + ' · ' : '') + `${en}-${T.en}`, `${u.f}-${T.fl}`, u.r ? `${u.r}${T.rooms}` : '', u.a ? `${u.a} ${T.m2}` : ''].filter(Boolean).join(' · ');
});
let L, aiNote = null;
try {
  L = await start(document.getElementById('lc'), {
    src: @json($src), data: DATA, cal: CAL, calibrate: CALIB, T,
    autoSrc: '{{ asset('assets/locate-auto.js') }}?v={{ @filemtime(public_path('assets/locate-auto.js')) }}',
    onProgress: (k) => { const e = $('#lcPct'); if (e) e.textContent = Math.round(k * 100) + '%'; },
    onShow: (u, ok, isAuto) => {
      $('#lcU').innerHTML = `<strong>${u.label}</strong> · ${u.sub}` + (u.s && T.st[u.s] ? ` · ${T.st[u.s]}` : '');
      note(ok, isAuto);
    },
    onChange: (c) => fill(c),
    onUndo: (n) => { const b = $('#cUndo'); if (b) b.disabled = !n; },
    // весь дом: наведение — номер и статус, клик — квартира (в программе откроется её карточка)
    onHover: (u) => { if (u) $('#lcU').innerHTML = `<strong>${u.label}</strong> · ${u.sub}` + (u.s && T.st[u.s] ? ` · ${T.st[u.s]}` : ''); },
    onPick: (u) => { if (!u) return; L.show(u.id); if (window.parent !== window) window.parent.postMessage({ type: 'oftob-locate-pick', unit: u.id }, location.origin); },
  });
} catch (e) {
  $('#lcLoad').innerHTML = `<div>${T.fail}</div>`; throw e;
}
$('#lcLoad').remove();
window.oftobLocate = L;
// в карточке продаж: сообщаем, что модель загружена — можно переключать квартиры
if (window.parent !== window) window.parent.postMessage({ type: 'oftob-locate-ready' }, location.origin);
const first = UNIT && L.units[UNIT] ? UNIT : null;
document.querySelectorAll('#lcBar [data-act]').forEach((b) => b.addEventListener('click', (e) => {
  const a = b.dataset.act;
  if (a === 'unit') L.focusSelected();
  if (a === 'house') { if (CALIB) L.overview(); else { L.statusView(); $('#lcU').innerHTML = T.pickHint + legend(); $('#lcNote').hidden = true; } }
  if (a === 'full') { e.preventDefault(); const u = new URL(location.href); u.searchParams.delete('embed'); window.open(u, '_blank'); }
}));
// продажи: одна загрузка модели, квартиры переключаются сообщением из карточки
let shown = first;
window.addEventListener('message', (e) => {
  if (e.origin !== location.origin || !e.data) return;
  if (e.data.type === 'oftob-locate-pause') { L.pause(!!e.data.on); return; }
  if (e.data.type !== 'oftob-locate') return;
  L.pause(false);
  if (L.units[e.data.unit]) { shown = e.data.unit; L.show(e.data.unit); const u = new URL(location.href); u.searchParams.set('u', e.data.unit); history.replaceState(null, '', u); }
});

/* ---------- автопривязка: форма модели + ИИ смотрит на фасады ---------- */
// ИИ спрашивает только тот, кто может править привязку; ответ — этажи, двери подъездов, лицевая сторона
const askAi = AI_URL ? async (image, entr) => {
  const r = await fetch(AI_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify({ image, entr }) }).catch(() => null);
  if (!r || !r.ok) { aiNote = 'fail'; return null; }
  const j = await r.json().catch(() => ({}));
  aiNote = j.note || null;
  return j.ok ? j.a : null;
} : null;
async function autoFit(ids, withAi) {
  try { return await L.autoFit({ only: ids, ai: withAi ? askAi : null }); } catch (e) { console.error(e); return null; }
}
async function save(bid, isAuto) {
  const c = L.cal(bid);
  if (!isAuto) L.confirm(bid);
  const body = { block: bid, c: c.c, s: c.s, r: c.r, floors: c.floors, gf: c.gf || 0, rows: c.rows, back: c.back, flipX: !!c.flipX, flipZ: !!c.flipZ, ex: c.ex || [] };
  if (isAuto && c.auto) body.auto = { by: c.auto.by, mf: c.auto.mf ?? undefined, doors: c.auto.doors ?? undefined, warn: c.auto.warn || [] };
  const r = await fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(body) });
  if (!r.ok) throw new Error(r.status);
  const j = await r.json();
  if (!j.skipped) L.markSaved(bid);
  return j;
}
function note(ok, isAuto) {
  const n = $('#lcNote');
  if (!n) return;
  n.classList.toggle('ai', !!(ok && isAuto));
  if (CALIB || (ok && !(isAuto && CAN))) { n.hidden = true; return; }
  n.hidden = false;
  if (ok) n.innerHTML = `${T.aiAuto} <a href="${SETUP}" target="_top">${T.check}</a>`;
  else if (CAN) n.innerHTML = `${T.approx} <a href="${SETUP}" target="_top">${T.setup}</a>`;
  else n.textContent = T.approx;
}
const busy = (on, text) => { const b = $('#lcBusy'); if (!b) return; b.hidden = !on; if (text) $('#lcBusyT').textContent = text; };
/** Что сказала автопривязка — в панель настройки */
function report(bid) {
  const t = $('#cAiText'), w = $('#cAiWarn');
  if (!t) return;
  const a = L.cal(bid).auto;
  if (!a) { w.hidden = true; return; }
  t.textContent = a.by === 'ai' ? T.aiDone : aiNote === 'limit' ? T.aiLimit : aiNote === 'off' ? T.aiOff : aiNote === 'fail' ? T.aiFail : T.geoDone;
  const B = DATA.blocks[bid], items = [];
  (a.warn || []).forEach((k) => {
    if (k === 'floors' && a.mf) items.push(T.wFloors.replace(':m', a.mf).replace(':n', B.floors));
    if (k === 'doors') items.push(T.wDoors.replace(':d', a.doors ?? 0).replace(':e', B.entr.length));
    if (k === 'notBuilding') items.push(T.wNotBuilding);
    if (k === 'blocks') items.push(T.wBlocks);
  });
  w.innerHTML = items.map((s) => `<li>${s}</li>`).join('');
  w.hidden = !items.length;
}

const unbound = L.blocks.filter((b) => !L.isCalibrated(b));
if (CALIB) {
  const sel = $('#cBlock');
  const startBlock = first ? L.units[first].b : (sel ? sel.value : L.blocks[0]);
  if (sel) sel.value = startBlock;
  fill(L.calibrate(startBlock)); report(startBlock);
  sel && sel.addEventListener('change', () => { fill(L.calibrate(sel.value)); report(sel.value); });
  const mark = (bid) => { const o = sel && [...sel.options].find((x) => x.value === bid); if (o && !o.textContent.endsWith('✓')) o.textContent += ' ✓'; };
  // сразу после загрузки модели (auto=1) или пока корпус не привязан — ИИ расставляет сам и сохраняет; человек потом проверяет
  const runAuto = async (ids) => {
    busy(true, T.aiBusy);
    const rep = await autoFit(ids, true);
    if (!rep || !Object.keys(rep).length) { busy(false); $('#cAiText').textContent = T.fail2; return; }
    for (const b of Object.keys(rep)) { try { const j = await save(b, true); if (!j.skipped) mark(b); } catch (e) { /* сохранит человек кнопкой */ } }
    const bid = L.block(); fill(L.cal(bid)); report(bid);
    busy(false);
  };
  if (AUTO || unbound.length) await runAuto(AUTO ? L.blocks : unbound);
  $('#cAuto').addEventListener('click', () => runAuto([L.block()]));
  document.querySelectorAll('#cView button').forEach((b) => b.addEventListener('click', () => { document.querySelectorAll('#cView button').forEach((x) => x.classList.toggle('on', x === b)); L.view(b.dataset.v); }));
  document.querySelectorAll('#cMode button').forEach((b) => b.addEventListener('click', () => { document.querySelectorAll('#cMode button').forEach((x) => x.classList.toggle('on', x === b)); L.mode(b.dataset.m); }));
  $('#cSnap').addEventListener('click', async () => { busy(true, '…'); try { fill(await L.snap()); } finally { busy(false); } });
  $('#cUndo').addEventListener('click', () => fill(L.undo()));
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !/INPUT|SELECT|TEXTAREA/.test(e.target.tagName)) { e.preventDefault(); fill(L.undo()); } });
  const num = (id) => parseFloat($(id).value);
  ['#cL', '#cH', '#cD'].forEach((id) => $(id).addEventListener('change', () => { const c = L.get(); fill(L.set({ s: [num('#cL') || c.s[0], num('#cH') || c.s[1], num('#cD') || c.s[2]] })); }));
  $('#cR').addEventListener('change', () => fill(L.set({ r: (num('#cR') || 0) * Math.PI / 180 })));
  const setFloors = (n) => fill(L.set({ floors: Math.max(1, Math.min(200, n || 1)) }));
  $('#cF').addEventListener('change', () => setFloors(parseInt($('#cF').value)));
  document.querySelectorAll('.lc-step button').forEach((b) => b.addEventListener('click', () => setFloors((parseInt($('#cF').value) || 1) + +b.dataset.d)));
  $('#cG').addEventListener('change', () => fill(L.set({ gf: Math.max(0, parseFloat($('#cG').value) || 0) })));
  $('#cRows').addEventListener('change', () => fill(L.set({ rows: +$('#cRows').value })));
  $('#cBack').addEventListener('change', () => fill(L.set({ back: $('#cBack').value })));
  $('#cFlipX').addEventListener('click', () => fill(L.set({ flipX: !L.get().flipX })));
  $('#cFlipZ').addEventListener('click', () => fill(L.set({ flipZ: !L.get().flipZ })));
  $('#cEven')?.addEventListener('click', () => fill(L.even()));
  $('#cSave').addEventListener('click', async () => {
    const btn = $('#cSave'); btn.disabled = true;
    try {
      await save(L.block(), false);
      if (window.ui && ui.toast) ui.toast(T.saved); else btn.lastChild.textContent = ' ' + T.saved;
      mark(L.block()); report(L.block());
      $('#cAiWarn').hidden = true;
    } catch (e) { alert(T.err); } finally { btn.disabled = false; }
  });
} else {
  if (first) L.show(first); else { L.statusView(); $('#lcU').innerHTML = T.pickHint + legend(); }
  // корпус не привязан: руководитель/администратор объектов — ИИ расставляет и сохраняет; остальным — по форме модели, без сохранения
  if (unbound.length) {
    const n = $('#lcNote');
    if (CAN && n) { n.hidden = false; n.classList.add('ai'); n.textContent = T.aiRun; }
    const rep = await autoFit(unbound, CAN);
    if (rep && CAN) for (const bid of Object.keys(rep)) { try { await save(bid, true); } catch (e) { /* не страшно: в следующий раз */ } }
    if (shown) L.show(shown); else { L.statusView(); if (n) n.hidden = true; }
  }
}
function legend() {
  return `<div class="lc-leg">${[['free', '#22c55e'], ['booked', '#f59e0b'], ['sold', '#ef4444']].map(([k, c]) => `<span><i style="background:${c}"></i>${T.st[k]}</span>`).join('')}</div>`;
}
function fill(c) {
  if (!c || !$('#cL')) return;
  $('#cL').value = c.s[0].toFixed(1); $('#cH').value = c.s[1].toFixed(1); $('#cD').value = c.s[2].toFixed(1);
  $('#cR').value = Math.round(c.r * 180 / Math.PI); $('#cF').value = c.floors; $('#cG').value = c.gf > 0 ? c.gf : ''; $('#cRows').value = c.rows; $('#cBack').value = c.back;
  $('#cFlipX').classList.toggle('btn-soft', !!c.flipX); $('#cFlipZ').classList.toggle('btn-soft', !!c.flipZ);
}
</script>
@endif
</body>
</html>
