<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
@include('partials.theme-init')
<title>{{ \App\Models\PlatformSetting::brand() }} — смена</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('assets/tokens.css') }}?v={{ @filemtime(public_path('assets/tokens.css')) }}">
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
  <div class="card"><div class="row"><span id="lblToday"></span><b id="today">0:00</b></div><div class="row"><span id="lblLast"></span><b id="last">—</b></div></div>
  <div class="note" id="note"></div>
</div>
<script>
'use strict';
const D = {
  ru: { start: 'Пришёл — начать смену', stop: 'Ушёл — закончить смену', timer: 'Время на смене', off: 'Смена не начата', onSite: 'Вы на объекте', offSite: 'Вы НЕ на объекте', far: 'до объекта', today: 'Отработано сегодня', last: 'Последняя отметка', gps: 'Определяю местоположение…', noGps: 'Включите геолокацию (GPS) и разрешите доступ к местоположению для этой страницы, затем нажмите ещё раз.', bad: 'Ссылка недействительна. Попросите новую у руководителя.', net: 'Нет связи с сервером. Проверьте интернет.', sure: 'Закончить смену?', note: 'Местоположение передаётся только во время смены: при начале, при окончании и каждые 5 минут, пока эта страница открыта. Не закрывайте страницу до конца смены.', m: 'м', km: 'км' },
  uz: { start: 'Keldim — smenani boshlash', stop: 'Ketdim — smenani tugatish', timer: 'Smenadagi vaqt', off: 'Smena boshlanmagan', onSite: 'Siz obyektdasiz', offSite: 'Siz obyektda EMASSIZ', far: 'obyektgacha', today: 'Bugun ishlangan', last: 'Oxirgi belgi', gps: 'Joylashuv aniqlanmoqda…', noGps: "Geolokatsiyani (GPS) yoqing va ushbu sahifaga joylashuvga ruxsat bering, so'ng yana bosing.", bad: "Havola yaroqsiz. Rahbardan yangisini so'rang.", net: "Server bilan aloqa yo'q. Internetni tekshiring.", sure: 'Smena tugatilsinmi?', note: "Joylashuv faqat smena davomida yuboriladi: boshida, oxirida va sahifa ochiq turganda har 5 daqiqada. Smena tugaguncha sahifani yopmang.", m: 'm', km: 'km' },
  tg: { start: 'Омадам — оғози баст', stop: 'Рафтам — анҷоми баст', timer: 'Вақт дар баст', off: 'Баст оғоз нашудааст', onSite: 'Шумо дар объект ҳастед', offSite: 'Шумо дар объект НЕСТЕД', far: 'то объект', today: 'Имрӯз кор карда шуд', last: 'Охирин қайд', gps: 'Ҷойгиршавӣ муайян карда мешавад…', noGps: 'Геолокатсия (GPS)-ро фаъол кунед ва ба ин саҳифа иҷозати ҷойгиршавӣ диҳед, баъд боз пахш кунед.', bad: 'Пайванд беэътибор аст. Аз роҳбар пайванди нав пурсед.', net: 'Бо сервер алоқа нест. Интернетро санҷед.', sure: 'Бастро анҷом диҳем?', note: 'Ҷойгиршавӣ танҳо дар вақти баст фиристода мешавад: дар оғоз, дар анҷом ва ҳар 5 дақиқа, то ин саҳифа кушода аст. То анҷоми баст саҳифаро напӯшед.', m: 'м', km: 'км' },
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
function pos() {
  return new Promise((ok, no) => { if (!navigator.geolocation) return no(new Error('nogeo')); navigator.geolocation.getCurrentPosition(p => ok(p.coords), no, { enableHighAccuracy: true, timeout: 20000, maximumAge: 15000 }); });
}
function paint() {
  document.querySelectorAll('.langs button').forEach(b => b.classList.toggle('on', b.dataset.l === L));
  $('#lblT').textContent = t('timer'); $('#lblToday').textContent = t('today'); $('#lblLast').textContent = t('last'); $('#note').textContent = t('note');
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
  if (S.open && !confirm(t('sure'))) return;
  busy = true; $('#err').textContent = ''; paint();
  try { await send(S.open ? 'out' : 'in'); if (navigator.vibrate) navigator.vibrate(120); }
  catch (e) { $('#err').textContent = e.http ? (e.http === 403 ? t('bad') : e.message) : e instanceof TypeError ? t('net') : t('noGps'); }
  busy = false; paint(); schedule();
}
function schedule() { clearInterval(pingT); if (S && S.open) pingT = setInterval(() => { if (!document.hidden) send('ping').then(paint).catch(() => { }); }, 5 * 60e3); }
async function load() {
  try { S = await api('/api/w/state'); if (!L) L = S.lang || 'ru'; if (S.open) send('ping').then(paint).catch(() => { }); }
  catch (e) { $('#err').textContent = e.http === 403 ? t('bad') : t('net'); }
  paint(); schedule();
}
document.querySelectorAll('.langs button').forEach(b => b.onclick = () => { L = b.dataset.l; try { localStorage.setItem('w_lang', L); } catch (e) { } paint(); });
$('#btn').onclick = press;
document.addEventListener('visibilitychange', () => { if (!document.hidden && S && S.open) send('ping').then(paint).catch(() => { }); });
setInterval(tick, 1000);
if (!L) L = (navigator.language || 'ru').slice(0, 2) === 'uz' ? 'uz' : 'ru';
paint(); load();
</script>
</body>
</html>
