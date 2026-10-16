<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
@include('partials.theme-init')
<title>{{ \App\Models\PlatformSetting::brand() }} — смена</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('assets/tokens.css') }}?v={{ @filemtime(public_path('assets/tokens.css')) }}">
<script src="{{ asset('assets/dialog.js') }}?v={{ @filemtime(public_path('assets/dialog.js')) }}" defer></script>
<style>
*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased}
.wrap{max-width:460px;margin:0 auto;padding:16px 16px 40px;min-height:100vh;display:flex;flex-direction:column;gap:12px}
.top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.brand{display:flex;align-items:center;gap:9px;font-weight:650;font-size:15.5px}
.mark{width:28px;height:28px;border-radius:8px;background:var(--accent);color:#fff;display:grid;place-items:center}.mark svg{width:18px;height:18px}
.tools{display:flex;gap:6px;align-items:center}
.langs{display:inline-flex;background:var(--sunken);border:1px solid var(--border);border-radius:8px;padding:2px;gap:2px}
.langs button{background:none;border:0;color:var(--muted);border-radius:6px;height:28px;padding:0 8px;font:500 12px Inter,sans-serif;cursor:pointer}.langs button.on{background:var(--surface);color:var(--text);box-shadow:0 0 0 1px var(--border)}
.tbtn{width:34px;height:34px;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);display:grid;place-items:center;cursor:pointer}
[data-theme=light] .only-dark,[data-theme=dark] .only-light{display:none}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px}
.who{font-size:19px;font-weight:600}.mu{color:var(--muted);font-size:14px}
.timer{font-size:54px;font-weight:600;text-align:center;letter-spacing:-.02em;font-variant-numeric:tabular-nums;margin:4px 0 10px}
.state{text-align:center;font-weight:500;font-size:15px;padding:10px;border-radius:10px;background:var(--sunken);color:var(--text-2)}
.state.on{background:var(--ok-soft);color:var(--ok-text)}.state.off{background:var(--bad-soft);color:var(--bad-text)}
.big{width:100%;border:0;border-radius:16px;height:84px;font:600 20px Inter,sans-serif;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:12px;transition:transform .1s}
.big:active{transform:scale(.98)}.big svg{width:24px;height:24px}
.big.start{background:var(--ok)}.big.stop{background:var(--bad)}.big:disabled{opacity:.6}
.note{font-size:13px;color:var(--muted);text-align:center;line-height:1.55}.err{color:var(--bad-text);text-align:center;font-size:14px}
.row{display:flex;justify-content:space-between;font-size:15px;padding:6px 0;color:var(--text-2)}.row+.row{border-top:1px solid var(--border-2)}.row b{font-variant-numeric:tabular-nums;color:var(--text);font-weight:600}
.sec{width:100%;border:1px solid var(--border);border-radius:12px;height:50px;background:var(--surface);color:var(--text);font:600 16px Inter,sans-serif;cursor:pointer}
.sec:active{transform:scale(.99)}
#map{height:260px;border-radius:10px;margin-bottom:8px;z-index:0}
.help{background:var(--warn-soft);color:var(--warn-text);border-color:color-mix(in srgb,var(--warn) 30%,transparent);font-size:14px;line-height:1.55}
.help b{display:block;margin-bottom:4px}
.tgbtn{display:flex;align-items:center;justify-content:center;gap:8px;height:50px;border-radius:12px;background:#229ED9;color:#fff;font-weight:600;text-decoration:none;margin-top:10px}
.ok-t{color:var(--ok-text);font-weight:600}
[hidden]{display:none!important}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><div class="brand"><span class="mark"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="14" r="5" fill="currentColor"/><path d="M12 3v2.5M4.2 6.7l1.8 1.8M19.8 6.7 18 8.5M2 14h2.5M19.5 14H22M3 21h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>{{ \App\Models\PlatformSetting::brand() }}</div>
    <div class="tools"><div class="langs"><button data-l="ru">RU</button><button data-l="uz">UZ</button><button data-l="tg">TJ</button></div><button class="tbtn" onclick="toggleTheme()" aria-label="Theme">{!! ic('moon', 'only-light', 16) !!}{!! ic('sun', 'only-dark', 16) !!}</button></div></div>
  <div class="card"><div class="who" id="who">…</div><div class="mu" id="site"></div></div>
  <div class="card">
    <div class="mu" style="text-align:center" id="lblT"></div>
    <div class="timer" id="timer">00:00:00</div>
    <div class="state" id="state"></div>
  </div>
  <button class="big start" id="btn" disabled>…</button>
  <div class="err" id="err"></div>
  {{-- почему GPS не работает и как включить — показываем, когда телефон не дал местоположение --}}
  <div class="card help" id="help" hidden></div>
  {{-- «где я»: карта с моей точкой и зоной объекта --}}
  <button class="sec" id="locBtn">📍 …</button>
  <div class="card" id="mapBox" hidden><div id="map"></div><div class="mu" id="mapInfo"></div></div>
  {{-- Telegram: GPS без https и при выключенном экране (трансляция геопозиции) --}}
  <div class="card" id="tgBox" hidden></div>
  <div class="card"><div class="row"><span id="lblToday"></span><b id="today">0:00</b></div><div class="row"><span id="lblLast"></span><b id="last">—</b></div></div>
  <div class="note" id="note"></div>
</div>

<script>
'use strict';
const D = {
  ru: { start: 'Пришёл — начать смену', stop: 'Ушёл — закончить смену', timer: 'Время на смене', off: 'Смена не начата', onSite: 'Вы на объекте', offSite: 'Вы НЕ на объекте', far: 'до объекта', today: 'Отработано сегодня', last: 'Последняя отметка', gps: 'Определяю местоположение…', bad: 'Ссылка недействительна. Попросите новую у руководителя.', net: 'Нет связи с сервером. Проверьте интернет.', sure: 'Закончить смену?', m: 'м', km: 'км',
    note: 'Местоположение передаётся во время смены: при начале, при окончании и каждые 5 минут, пока страница открыта.',
    showMe: 'Показать, где я', me: 'Вы здесь', zone: 'Зона объекта', noSite: 'Руководитель ещё не отметил объект на карте.',
    insecT: 'GPS на этой странице сейчас не работает', insec: 'Сайт открыт без защищённого соединения (http) — телефоны дают GPS только сайтам с https, настройки телефона тут не помогут. Отмечайтесь через Telegram (кнопка ниже) — он берёт GPS сам. Когда администратор включит https, заработает и здесь.',
    deniedT: 'Нет доступа к местоположению', deniedIos: 'iPhone: Настройки → Конфиденциальность → Службы геолокации — включите; ниже Safari (или Chrome) → «При использовании». В Safari: «аА» в адресной строке → Настройки веб-сайта → Геопозиция → Разрешить. Затем обновите страницу.', deniedAnd: 'Android: нажмите на значок замка в адресной строке → Разрешения → Местоположение → Разрешить. Включите «Местоположение» в шторке телефона. Затем обновите страницу.',
    unavailT: 'Местоположение выключено', unavail: 'Включите GPS: проведите по экрану сверху вниз и нажмите «Местоположение» (Геолокация), затем нажмите кнопку ещё раз.', timeout: 'GPS не нашёл сигнал. Выйдите на открытое место и нажмите ещё раз.',
    tgT: 'Отметки через Telegram', tgText: 'Telegram сам берёт GPS телефона: на объекте нажмите в боте «Пришёл», уходя — «Ушёл». Местоположение принимается только этой кнопкой.', tgBtn: 'Подключить Telegram', tgOn: 'Telegram подключён. Пришёл / Ушёл — только кнопкой внизу в боте.', tgOpen: 'Открыть бота' },
  uz: { start: 'Keldim — smenani boshlash', stop: 'Ketdim — smenani tugatish', timer: 'Smenadagi vaqt', off: 'Smena boshlanmagan', onSite: 'Siz obyektdasiz', offSite: 'Siz obyektda EMASSIZ', far: 'obyektgacha', today: 'Bugun ishlangan', last: 'Oxirgi belgi', gps: 'Joylashuv aniqlanmoqda…', bad: "Havola yaroqsiz. Rahbardan yangisini so'rang.", net: "Server bilan aloqa yo'q. Internetni tekshiring.", sure: 'Smena tugatilsinmi?', m: 'm', km: 'km',
    note: "Joylashuv smena davomida yuboriladi: boshida, oxirida va sahifa ochiq turganda har 5 daqiqada.",
    showMe: "Qayerdaligimni ko'rsatish", me: 'Siz shu yerdasiz', zone: 'Obyekt hududi', noSite: "Rahbar obyektni xaritada hali belgilamagan.",
    insecT: "Bu sahifada GPS hozir ishlamaydi", insec: "Sayt himoyalanmagan ulanish (http) orqali ochilgan — telefonlar GPS'ni faqat https saytlarga beradi, telefon sozlamalari bu yerda yordam bermaydi. Telegram orqali belgilang (pastdagi tugma) — u GPS'ni o'zi oladi. Administrator https'ni yoqqanda bu yerda ham ishlaydi.",
    deniedT: "Joylashuvga ruxsat yo'q", deniedIos: "iPhone: Sozlamalar → Maxfiylik → Joylashuv xizmatlari — yoqing; pastda Safari (yoki Chrome) → «Ilovadan foydalanganda». Safari'da: manzil satridagi «aA» → Sayt sozlamalari → Joylashuv → Ruxsat berish. Keyin sahifani yangilang.", deniedAnd: "Android: manzil satridagi qulf belgisini bosing → Ruxsatlar → Joylashuv → Ruxsat berish. Telefon pardasidan «Joylashuv»ni yoqing. Keyin sahifani yangilang.",
    unavailT: "Joylashuv o'chiq", unavail: "GPS'ni yoqing: ekranni yuqoridan pastga suring va «Joylashuv» (Geolokatsiya)ni bosing, keyin tugmani yana bosing.", timeout: "GPS signal topmadi. Ochiq joyga chiqing va yana bosing.",
    tgT: 'Telegram orqali belgilash', tgText: "Telegram telefon GPS'ini o'zi oladi: obyektga kelganda botda «Keldim», ketayotganda «Ketdim» tugmasini bosing. Joylashuv faqat shu tugma orqali qabul qilinadi.", tgBtn: 'Telegramni ulash', tgOn: "Telegram ulangan. Keldim / Ketdim — faqat botdagi pastki tugma bilan.", tgOpen: 'Botni ochish' },
  tg: { start: 'Омадам — оғози баст', stop: 'Рафтам — анҷоми баст', timer: 'Вақт дар баст', off: 'Баст оғоз нашудааст', onSite: 'Шумо дар объект ҳастед', offSite: 'Шумо дар объект НЕСТЕД', far: 'то объект', today: 'Имрӯз кор карда шуд', last: 'Охирин қайд', gps: 'Ҷойгиршавӣ муайян карда мешавад…', bad: 'Пайванд беэътибор аст. Аз роҳбар пайванди нав пурсед.', net: 'Бо сервер алоқа нест. Интернетро санҷед.', sure: 'Бастро анҷом диҳем?', m: 'м', km: 'км',
    note: 'Ҷойгиршавӣ дар вақти баст фиристода мешавад: дар оғоз, дар анҷом ва ҳар 5 дақиқа, то саҳифа кушода аст.',
    showMe: 'Нишон додани ҷои ман', me: 'Шумо дар ин ҷо', zone: 'Минтақаи объект', noSite: 'Роҳбар объектро дар харита ҳанӯз қайд накардааст.',
    insecT: 'GPS дар ин саҳифа ҳоло кор намекунад', insec: 'Сайт бе пайвасти ҳифзшуда (http) кушода шудааст — телефонҳо GPS-ро танҳо ба сайтҳои https медиҳанд, танзимоти телефон ин ҷо ёрӣ намедиҳад. Тавассути Telegram қайд кунед (тугмаи поён) — вай GPS-ро худаш мегирад. Вақте маъмур https-ро фаъол кунад, ин ҷо ҳам кор мекунад.',
    deniedT: 'Ба ҷойгиршавӣ иҷозат нест', deniedIos: 'iPhone: Танзимот → Махфият → Хидматҳои ҷойгиршавӣ — фаъол кунед; дар поён Safari (ё Chrome) → «Ҳангоми истифода». Дар Safari: «аА» дар сатри суроға → Танзимоти сайт → Ҷойгиршавӣ → Иҷозат. Баъд саҳифаро нав кунед.', deniedAnd: 'Android: аломати қулфро дар сатри суроға пахш кунед → Иҷозатҳо → Ҷойгиршавӣ → Иҷозат. «Ҷойгиршавӣ»-ро дар пардаи телефон фаъол кунед. Баъд саҳифаро нав кунед.',
    unavailT: 'Ҷойгиршавӣ хомӯш аст', unavail: 'GPS-ро фаъол кунед: экранро аз боло ба поён кашед ва «Ҷойгиршавӣ»-ро пахш кунед, баъд тугмаро боз пахш кунед.', timeout: 'GPS сигнал наёфт. Ба ҷои кушода бароед ва боз пахш кунед.',
    tgT: 'Қайд тавассути Telegram', tgText: 'Telegram GPS-и телефонро худаш мегирад: дар объект дар бот «Омадам», ҳангоми рафтан «Рафтам»-ро пахш кунед. Ҷойгиршавӣ танҳо бо ҳамин тугма қабул карда мешавад.', tgBtn: 'Пайваст кардани Telegram', tgOn: 'Telegram пайваст аст. Омадам / Рафтам — танҳо бо тугмаи поёни бот.', tgOpen: 'Кушодани бот' },
};
const ICO = { play: @json(ic('play', '', 24)), stop: @json(ic('square', '', 24)) };
const $ = s => document.querySelector(s), tok = new URLSearchParams(location.search).get('t') || '';
let L = localStorage.getItem('w_lang') || '', S = null, lastPos = null, pingT = null, busy = false;
const t = k => (D[L] || D.ru)[k];
const hms = ms => { const s = Math.max(0, Math.floor(ms / 1000)); return [s / 3600 | 0, (s / 60 | 0) % 60, s % 60].map((v, i) => i ? String(v).padStart(2, '0') : String(v).padStart(2, '0')).join(':'); };
const hm = ms => { const m = Math.max(0, Math.round(ms / 60000)); return (m / 60 | 0) + ':' + String(m % 60).padStart(2, '0'); };
const fmtD = d => d == null ? '' : d < 1000 ? d + ' ' + t('m') : (d / 1000).toFixed(1).replace('.', ',') + ' ' + t('km');
async function api(path, body) {
  const r = await fetch(path + (body ? '' : (path.includes('?') ? '&' : '?') + 't=' + encodeURIComponent(tok)), body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ t: tok }, body)) } : {});
  const j = await r.json().catch(() => ({})); if (!r.ok) throw Object.assign(new Error(j.error || r.status), { http: r.status }); return j;
}
// GPS в браузере есть только на https: на http телефон даже не спросит разрешения
const SECURE = window.isSecureContext !== false;
function pos() {
  return new Promise((ok, no) => {
    if (!SECURE) return no({ code: 'insecure' });
    if (!navigator.geolocation) return no({ code: 2 });
    // запрос местоположения — телефон сам спросит «Разрешить?», если ещё не спрашивал
    navigator.geolocation.getCurrentPosition(p => ok(p.coords), no, { enableHighAccuracy: true, timeout: 20000, maximumAge: 15000 });
  });
}
/* почему не получилось взять GPS и что сделать — понятно и для iPhone, и для Android */
function gpsHelp(e) {
  const h = $('#help'), ios = /iPhone|iPad|iPod/i.test(navigator.userAgent);
  const [title, text] = e.code === 'insecure' ? [t('insecT'), t('insec')] : e.code === 1 ? [t('deniedT'), t(ios ? 'deniedIos' : 'deniedAnd')]
    : e.code === 3 ? ['', t('timeout')] : [t('unavailT'), t('unavail')];
  h.innerHTML = (title ? '<b></b>' : '') + '<span></span>'; if (title) h.querySelector('b').textContent = title; h.querySelector('span').textContent = text;
  h.hidden = false; if (e.code === 'insecure') $('#tgBox').hidden = !S || !S.bot;
  h.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
/* «Показать, где я»: моя точка и зона объекта на карте, расстояние до объекта */
let MAP = null;
function leaflet() {   // карта — window.L (у страницы своя переменная L — язык)
  if (window.L && window.L.map) return Promise.resolve();
  return new Promise((ok, no) => {
    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(l);
    const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; s.onload = ok; s.onerror = no; document.head.appendChild(s);
  });
}
const distM = (a, b, c, d) => { const r = x => x * Math.PI / 180, s = Math.sin(r(c - a) / 2) ** 2 + Math.cos(r(a)) * Math.cos(r(c)) * Math.sin(r(d - b) / 2) ** 2; return Math.round(6371000 * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s))); };
async function showMe() {
  const b = $('#locBtn'); b.disabled = true; b.textContent = t('gps'); $('#help').hidden = true;
  try {
    const c = await pos(); await leaflet();
    const LF = window.L;
    $('#mapBox').hidden = false;
    if (MAP) { MAP.remove(); MAP = null; }
    MAP = LF.map('map', { zoomControl: true, attributionControl: false });
    LF.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(MAP);
    const me = [c.latitude, c.longitude], pts = [me];
    LF.circle(me, { radius: Math.min(c.accuracy || 0, 300), color: '#2563eb', weight: 1, fillOpacity: .1 }).addTo(MAP);
    LF.circleMarker(me, { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(MAP).bindTooltip(t('me'), { permanent: true, direction: 'top', offset: [0, -8] });
    let info = '';
    if (S && S.hasSite && S.lat != null) {
      const site = [S.lat, S.lng], r = S.r || 150, d = distM(me[0], me[1], site[0], site[1]);
      LF.circle(site, { radius: r, color: '#16a34a', weight: 2, fillOpacity: .12 }).addTo(MAP).bindTooltip(t('zone') + (S.site ? ': ' + S.site : ''));
      pts.push(site);
      info = (d <= r ? '✅ ' + t('onSite') : '⚠️ ' + t('offSite')) + ' · ' + fmtD(d) + ' ' + t('far');
    } else info = t('noSite');
    $('#mapInfo').textContent = info;
    pts.length > 1 ? MAP.fitBounds(pts, { padding: [30, 30], maxZoom: 17 }) : MAP.setView(me, 17);
    $('#mapBox').scrollIntoView({ behavior: 'smooth', block: 'center' });
  } catch (e) { e && e.code !== undefined ? gpsHelp(e) : ($('#err').textContent = t('net')); }
  b.disabled = false; b.textContent = '📍 ' + t('showMe');
}
/* смена идёт — экран не гаснет, пока страница открыта (браузер передаёт GPS только при включённом экране) */
let WL = null;
async function keepAwake() {
  try {
    if (S && S.open && !document.hidden && 'wakeLock' in navigator) { if (!WL) { WL = await navigator.wakeLock.request('screen'); WL.addEventListener('release', () => { WL = null; }); } }
    else if (WL && (!S || !S.open)) { await WL.release(); WL = null; }
  } catch (e) { }
}
function tgPaint() {
  const box = $('#tgBox'); if (!S || !S.bot) { box.hidden = true; return; }
  box.hidden = false;
  const link = 'https://t.me/' + encodeURIComponent(S.bot) + (S.tg ? '' : '?start=w_' + encodeURIComponent(tok));
  box.innerHTML = '<b></b><div class="mu" style="margin-top:4px"></div><a class="tgbtn" target="_blank" rel="noopener"></a>';
  box.querySelector('b').textContent = '✈️ ' + t('tgT');
  box.querySelector('div').innerHTML = S.tg ? '<span class="ok-t"></span>' : ''; (S.tg ? box.querySelector('.ok-t') : box.querySelector('div')).textContent = t(S.tg ? 'tgOn' : 'tgText');
  const a = box.querySelector('a'); a.href = link; a.textContent = t(S.tg ? 'tgOpen' : 'tgBtn');
}
function paint() {
  document.querySelectorAll('.langs button').forEach(b => b.classList.toggle('on', b.dataset.l === L));
  $('#lblT').textContent = t('timer'); $('#lblToday').textContent = t('today'); $('#lblLast').textContent = t('last'); $('#note').textContent = t('note');
  if (!$('#locBtn').disabled) $('#locBtn').textContent = '📍 ' + t('showMe');
  tgPaint();
  if (!S) return;
  $('#who').textContent = S.name; $('#site').textContent = [S.role, S.site].filter(Boolean).join(' · ');
  const open = !!S.open, b = $('#btn'); b.disabled = busy; b.className = 'big ' + (open ? 'stop' : 'start'); b.innerHTML = busy ? t('gps') : (open ? ICO.stop : ICO.play) + '<span>' + t(open ? 'stop' : 'start') + '</span>';
  const st = $('#state');
  if (!open) { st.className = 'state'; st.textContent = t('off'); }
  else if (lastPos && lastPos.on != null) { st.className = 'state ' + (lastPos.on ? 'on' : 'off'); st.textContent = t(lastPos.on ? 'onSite' : 'offSite') + (lastPos.dist != null ? ' · ' + fmtD(lastPos.dist) + ' ' + t('far') : ''); }
  else { st.className = 'state'; st.textContent = '…'; }
  $('#last').textContent = S.last ? new Date(S.last.t).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';
  tick();
}
function tick() { if (!S) return; const run = S.open ? Date.now() - new Date(S.open) : 0; $('#timer').textContent = hms(run); $('#today').textContent = hm(S.todayMs + run); }
async function send(type) {
  const c = await pos();
  const j = await api('/api/w/event', { type, lat: c.latitude, lng: c.longitude, acc: c.accuracy });
  S = Object.assign(S || {}, j); lastPos = { on: j.on, dist: j.dist }; return j;
}
async function press() {
  if (busy || !S) return;
  window.LANG = L;   // язык кнопок окна подтверждения
  if (S.open && !(await ui.confirm(t('sure'), { danger: false }))) return;
  busy = true; $('#err').textContent = ''; $('#help').hidden = true; paint();
  try { await send(S.open ? 'out' : 'in'); if (navigator.vibrate) navigator.vibrate(120); }
  catch (e) { if (e.http) $('#err').textContent = e.http === 403 ? t('bad') : e.message; else if (e instanceof TypeError) $('#err').textContent = t('net'); else gpsHelp(e); }
  busy = false; paint(); schedule(); keepAwake();
}
function schedule() { clearInterval(pingT); if (S && S.open) pingT = setInterval(() => { if (!document.hidden) send('ping').then(paint).catch(() => { }); }, 5 * 60e3); }
async function load() {
  try { S = await api('/api/w/state'); if (!L) L = S.lang || 'ru'; if (S.open) send('ping').then(paint).catch(() => { }); }
  catch (e) { $('#err').textContent = e.http === 403 ? t('bad') : t('net'); }
  paint(); schedule(); keepAwake();
  if (S && !SECURE) gpsHelp({ code: 'insecure' });   // сразу объясняем, а не после неудачного нажатия
}
document.querySelectorAll('.langs button').forEach(b => b.onclick = () => { L = b.dataset.l; try { localStorage.setItem('w_lang', L); } catch (e) { } paint(); if (!$('#help').hidden && !SECURE) gpsHelp({ code: 'insecure' }); });
$('#btn').onclick = press;
$('#locBtn').onclick = showMe;
document.addEventListener('visibilitychange', () => { if (!document.hidden && S && S.open) send('ping').then(paint).catch(() => { }); keepAwake(); });
setInterval(tick, 1000);
if (!L) L = (navigator.language || 'ru').slice(0, 2) === 'uz' ? 'uz' : 'ru';
paint(); load();
</script>
</body>
</html>
