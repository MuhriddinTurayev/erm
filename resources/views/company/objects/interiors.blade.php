@php
  $T = \App\Services\Locate::innerTexts();
  $media = '/company/objects/'.$pid.'?tab=media';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>{{ __('Интерьеры квартир') }} — {{ $p['name'] ?? '' }}</title>
<style>
  body{margin:0;background:var(--bg)}
  .ii{max-width:1200px;margin:0 auto;padding:22px 20px 40px;display:grid;gap:14px}
  body.embed .ii{padding:14px 16px 24px}
  .ii-h a{color:var(--muted);text-decoration:none;font-size:13px;display:inline-flex;gap:6px;align-items:center}
  .ii-h h1{margin:6px 0 4px;font:700 22px var(--font-head)}
  body.embed .ii-h h1{font-size:18px;margin-top:0;padding-right:44px}
  .ii-h p{margin:0;color:var(--text-2);font-size:13.5px;max-width:820px}
  .ii-room{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
  .ii-room .field{flex:0 1 340px;margin:0}
  .ii-room small{color:var(--muted);font-size:12px;padding-bottom:8px}
  .ii-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;align-items:start}
  .ii-prog{position:sticky;bottom:10px;background:var(--surface);border:1px solid var(--border-2);border-radius:12px;padding:10px 12px;box-shadow:var(--sh);display:grid;gap:6px}
  .ii-prog[hidden]{display:none}
  .ii-prog .bar{height:6px;border-radius:9px;background:var(--sunken);overflow:hidden}.ii-prog .bar i{display:block;height:100%;width:0;background:var(--accent);transition:width .2s}
  .ii-prog small{font-size:12px;color:var(--muted)}
  .ii-ov{position:fixed;inset:0;z-index:20;background:rgba(17,17,39,.55);backdrop-filter:blur(3px);display:grid;place-items:center;padding:20px}
  .ii-ov[hidden]{display:none}
  body.embed .ii-ov{padding:8px}
  .ii-ov-in{position:relative;width:min(1200px,100%);height:min(820px,100%);background:var(--surface);border-radius:16px;overflow:hidden;box-shadow:var(--sh-lg)}
  .ii-ov-in iframe,.ii-ov-in img{width:100%;height:100%;border:0;display:block;object-fit:contain;background:var(--bg)}
  .ii-ov-x{position:absolute;right:12px;top:12px;z-index:2;width:40px;height:40px;border-radius:50%;border:1px solid var(--border-2);background:var(--surface);cursor:pointer;display:grid;place-items:center;color:var(--text);box-shadow:var(--sh)}
</style>
</head>
<body class="{{ $embed ? 'embed' : '' }}">
<div class="ii">
  <div class="ii-h">
    @unless ($embed)<a href="{{ url($media) }}">{!! ic('arrow-left', '', 14) !!} {{ $p['name'] ?? '' }}</a>@endunless
    <h1>{{ __('Интерьеры квартир') }}@if ($embed) · {{ $p['name'] ?? '' }}@endif</h1>
    <p>{{ $canEdit ? __('Для каждой планировки — 3D-интерьер, панорамы 360° комнат, рендеры и план. Загруженное увидят у всех квартир этой планировки: в программе, в кабинете покупателя и на сайте. Ошиблись файлом — наведите на него: карандаш поправит, корзина удалит.') : __('Интерьеры квартир по планировкам: 3D, панорамы 360° комнат, рендеры и план.') }}</p>
  </div>
  @if ($canEdit)
  <div class="ii-room">
    <label class="field"><span>{{ __('Комната на панораме 360°') }}</span><input id="iRoom" list="iRooms" maxlength="60" placeholder="{{ __('название берётся из имени файла') }}">
      <datalist id="iRooms">@foreach ([__('Гостиная'), __('Кухня'), __('Спальня'), __('Детская'), __('Ванная'), __('Прихожая'), __('Балкон'), __('Кабинет')] as $rn)<option value="{{ $rn }}">@endforeach</datalist></label>
    <small>{{ __('Впишите комнату перед загрузкой одной панорамы — так её подпишут у покупателя.') }}</small>
  </div>
  @endif
  <div id="iList" class="ii-list"></div>
  <div class="ii-prog" id="iProg" hidden><div class="bar"><i></i></div><small></small></div>
</div>
<div class="ii-ov" id="iOv" hidden><div class="ii-ov-in"><button type="button" class="ii-ov-x" id="iOvX" aria-label="{{ __('Закрыть') }}">{!! ic('x', '', 18) !!}</button><div id="iOvB" style="height:100%"></div></div></div>
<script type="module">
import { innerManager, innerOf } from '{{ asset('assets/inner-media.js') }}?v={{ @filemtime(public_path('assets/inner-media.js')) }}';
const T = {!! json_encode($T, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
const UNITS = {!! json_encode($units, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}, INNER = {!! json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
const PID = {!! json_encode($pid) !!}, FOCUS = {!! json_encode($focus) !!}, CAN = {!! json_encode((bool) $canEdit) !!};
const URLS = { up: CAN ? {!! json_encode(url('/company/objects/'.$pid.'/media')) !!} : null, file: {!! json_encode(url('/company/media/{id}/file')) !!},
  patch: {!! json_encode(url('/company/media/{id}/patch')) !!}, del: {!! json_encode(url('/company/media/{id}')) !!}, view: {!! json_encode(url('/company/objects/'.$pid.'/3d?embed=1&u={u}')) !!} };
const CVT = {!! json_encode(\App\Http\Controllers\Company\ObjectController::convertTexts(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}, MC_URL = {!! json_encode(asset('assets/model-convert.js').'?v='.@filemtime(public_path('assets/model-convert.js'))) !!};
const ICON = {!! json_encode(['interior' => ic('box', '', 22), 'pano' => ic('compass', '', 22), 'photo' => ic('image', '', 22), 'plan' => ic('ruler', '', 22), 'eye' => ic('eye', '', 15), 'box' => ic('box', '', 16), 'compass' => ic('compass', '', 16), 'image' => ic('image', '', 16), 'ruler' => ic('ruler', '', 16), 'edit' => ic('pencil', '', 13), 'del' => ic('trash-2', '', 13)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const prog = (k, text) => {
  const p = $('#iProg');
  p.hidden = k === false;
  if (k !== null && k !== false) p.querySelector('i').style.width = Math.round(k * 100) + '%';
  if (text) p.querySelector('small').textContent = text;
};
/** Посмотреть: 3D и панорамы — в просмотрщике квартиры, рендер/план — картинкой или PDF */
function view(u, m) {
  const mine = innerOf(IM.files(), u);
  m = m || mine.find((x) => x.k === 'interior' || x.k === 'pano') || mine[0];
  if (!m) return;
  const box = $('#iOvB'), file = URLS.file.replace('{id}', m.id);
  box.innerHTML = m.k === 'interior' || m.k === 'pano'
    ? `<iframe src="${esc(URLS.view.replace('{u}', encodeURIComponent(u.id)) + '&m=' + m.id)}" allow="fullscreen; xr-spatial-tracking" title="3D"></iframe>`
    : m.ext === 'pdf' ? `<iframe src="${esc(file)}" title="PDF"></iframe>` : `<img src="${esc(file)}" alt="${esc(m.n)}">`;
  $('#iOv').hidden = false;
}
const close = () => { $('#iOv').hidden = true; $('#iOvB').innerHTML = ''; };
$('#iOvX').addEventListener('click', close);
$('#iOv').addEventListener('click', (e) => { if (e.target.id === 'iOv') close(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#iOv').hidden) close(); });
/** Программа (окно «Дизайн квартиры»): что теперь загружено — она покажет настоящий интерьер вместо нарисованного */
function tell() {
  if (parent === window) return;
  const list = IM.files(), n = {}, seen = new Set(), pairs = [];
  list.forEach((m) => {
    n[m.k] = (n[m.k] || 0) + 1;
    if (m.k !== 'interior' && m.k !== 'pano') return;
    const pr = [m.l || null, m.l ? null : (m.r ?? null)], k = JSON.stringify(pr);
    if (!seen.has(k)) { seen.add(k); pairs.push(pr); }
  });
  const files = list.map((m) => ({ id: m.id, k: m.k, l: m.l || undefined, r: m.r ?? undefined, n: m.n, room: m.room || undefined, ext: m.ext }));
  parent.postMessage({ type: 'oftob-inner-changed', pid: PID, in: pairs, n, files: JSON.parse(JSON.stringify(files)) }, location.origin);
}
const IM = innerManager($('#iList'), { T, ICON, inner: INNER, units: UNITS, csrf: document.querySelector('meta[name=csrf-token]').content, cvt: CVT, mcUrl: MC_URL,
  canEdit: CAN, roomInput: $('#iRoom'), prog, urls: URLS, onView: view, onChange: tell });
if (FOCUS) IM.setCur(FOCUS);
IM.render();
</script>
</body>
</html>
