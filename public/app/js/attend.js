'use strict';
/* ===== Приход сотрудников: таймер смены с телефона и GPS — на объекте или нет ===== */
const GPS = { map: null, layer: null, route: null, tick: null, url: '', busy: false };
const gpsTok = () => { const a = new Uint8Array(12); crypto.getRandomValues(a); return [...a].map(x => x.toString(16).padStart(2, '0')).join(''); };
const gpsActive = p => p.staff.filter(s => s.active !== false && !s.isNew);
const gpsTime = iso => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
const gpsDist = d => d == null ? '—' : d < 1000 ? fmt(d) + ' м' : fmt(d / 1000, 1) + ' км';
const gpsHM = ms => { const m = Math.max(0, Math.round(ms / 60000)); return `${Math.floor(m / 60)} ч ${String(m % 60).padStart(2, '0')} мин`; };
const gpsLink = s => GPS.url && s.wtok ? `${GPS.url}/w?t=${s.wtok}` : '';

/* Смены из отметок: пришёл → сигналы каждые 5 минут → ушёл */
function gpsShifts(p) {
  const by = {}, out = [], now = Date.now();
  p.geoEv.slice().sort((a, b) => a.t.localeCompare(b.t)).forEach(e => (by[e.sid] = by[e.sid] || []).push(e));
  for (const sid in by) {
    let cur = null;
    for (const e of by[sid]) {
      if (e.type === 'in') { if (cur) out.push(cur); cur = { sid, in: e, out: null, evs: [e] }; }
      else if (cur) { cur.evs.push(e); if (e.type === 'out') { cur.out = e; out.push(cur); cur = null; } }
    }
    if (cur) out.push(cur);
  }
  out.forEach(sh => {
    sh.s = p.staff.find(x => x.id === sh.sid) || { name: '?', role: '' };
    sh.last = sh.evs[sh.evs.length - 1]; sh.date = isoDate(sh.in.t);
    sh.open = !sh.out && now - new Date(sh.in.t) < 18 * 3600e3; sh.forgot = !sh.out && !sh.open;
    sh.ms = (sh.out ? new Date(sh.out.t) : sh.open ? now : new Date(sh.last.t)) - new Date(sh.in.t);
    sh.offs = sh.evs.filter(e => e.on === false); sh.maxDist = Math.max(0, ...sh.offs.map(e => e.dist || 0));
    sh.silent = sh.open ? (now - new Date(sh.last.t)) / 60000 : 0;
  });
  return out.sort((a, b) => b.in.t.localeCompare(a.in.t));
}
function gpsCalc(p) {
  const S = gpsShifts(p), t = today(), open = S.filter(s => s.open);
  const todayS = S.filter(s => s.date === t), came = new Set(todayS.map(s => s.sid));
  return { S, open, on: open.filter(s => s.last.on !== false), off: open.filter(s => s.last.on === false), silent: open.filter(s => s.silent > 15), forgot: S.filter(s => s.forgot && s.date >= addDays(t, -3)), todayS, absent: gpsActive(p).filter(s => !came.has(s.id)), todayMs: sum(todayS, s => s.ms) };
}
function gpsToTs(p, date, sid) {
  const ms = sum(gpsShifts(p).filter(s => s.sid === sid && s.date === date && s.out), s => s.ms), h = Math.round(ms / 1800000) / 2;
  if (h > 0) { (p.ts[sid] = p.ts[sid] || {})[date] = h; return h; } return 0;
}
function gpsAudit(p, add) {
  if (!p.geoEv.length) return;
  const C = gpsCalc(p);
  if (C.off.length) add('bad', 'Сотрудники и GPS', 'attend', `Сейчас на смене, но НЕ на объекте: ${C.off.map(s => `${esc(s.s.name)} (${gpsDist(s.last.dist)})`).join(', ')}`);
  const offIn = C.todayS.filter(s => s.in.on === false); if (offIn.length) add('warn', 'Сотрудники и GPS', 'attend', `Начали смену не на объекте: ${offIn.map(s => esc(s.s.name)).join(', ')}`);
  if (C.forgot.length) add('warn', 'Сотрудники и GPS', 'attend', `Не закрыли смену (забыли нажать «Ушёл»): ${[...new Set(C.forgot.map(s => esc(s.s.name)))].join(', ')} — проверьте часы в табеле`);
  if (!C.off.length && !offIn.length) add('ok', 'Сотрудники и GPS', 'attend', `Все на объекте · на смене ${C.open.length} чел.`);
}

/* Связь с сервером: ссылки сотрудников, координаты объекта, приём отметок */
async function gpsSync() {
  if (!viaServer() || !DB) return;
  let changed = false; const workers = [], sites = {}, revoke = DB.settings.gpsRevoke || [];
  DB.projects.forEach(p => {
    p.staff.filter(s => s.wtok && !gpsActive(p).includes(s)).forEach(s => revoke.push(s.wtok));
    gpsActive(p).forEach(s => { if (!s.wtok) { s.wtok = gpsTok(); changed = true; } workers.push({ t: s.wtok, sid: s.id, pid: p.id, name: s.name, role: s.role }); });
    if (p.gps.lat !== '' && p.gps.lng !== '') sites[p.id] = { lat: +p.gps.lat, lng: +p.gps.lng, r: +p.gps.r || 150, name: p.name };
  });
  if (changed) save();
  try { const r = await fetch('/api/geo/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ workers, sites, revoke, lang: DB.settings.lang }) }); const j = await r.json(); GPS.url = j.publicUrl || ''; if (DB.settings.gpsRevoke) { delete DB.settings.gpsRevoke; save(); } } catch (e) { }
}
async function gpsPoll() {
  if (!viaServer() || GPS.busy || !DB) return; GPS.busy = true;
  try {
    const j = await (await fetch('/api/geo/events?since=' + (DB.settings.geoSeq || 0))).json(); const msgs = [];
    for (const e of j.items) {
      const p = DB.projects.find(x => x.id === e.pid); if (!p || p.geoEv.some(x => x.seq === e.seq)) continue;
      p.geoEv.push({ seq: e.seq, t: e.t, sid: e.sid, type: e.type, lat: e.lat, lng: e.lng, acc: e.acc, dist: e.dist, on: e.on });
      if (p.geoEv.length > 6000) p.geoEv = p.geoEv.slice(-6000);
      const s = p.staff.find(x => x.id === e.sid), nm = s ? s.name : '';
      if (e.type === 'in') msgs.push(e.on === false ? `⚠️ ${nm}: ${T('начал смену НЕ на объекте')} (${gpsDist(e.dist)})` : `🟢 ${nm}: ${T('пришёл на объект')}`);
      if (e.type === 'out') { const h = p.gps.autoTs !== false ? gpsToTs(p, isoDate(e.t), e.sid) : 0; msgs.push(`⏹ ${nm}: ${T('ушёл')}${h ? ` · ${T('в табель')} ${fmt(h, 1)} ${T('ч')}` : ''}`); }
      if (e.type === 'ping' && e.on === false) msgs.push(`⚠️ ${nm}: ${T('ушёл с объекта')} (${gpsDist(e.dist)})`);
    }
    DB.settings.geoSeq = j.seq;
    if (j.items.length) {
      save(); if (msgs.length) toast(msgs.slice(-2).join(' · '));
      const busy = !$('#modal').classList.contains('hidden') || /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '');
      if (curPage === 'attend' && !busy) rerender();
    }
  } catch (e) { }
  GPS.busy = false;
}
setTimeout(() => { gpsSync(); gpsPoll(); setInterval(gpsPoll, 20000); }, 2000);

/* Карта: OpenStreetMap через Leaflet, загружается только на этой странице */
let gpsLeaf = null;
function gpsLoadLeaflet() {
  if (window.L && window.L.map) return Promise.resolve();
  return gpsLeaf = gpsLeaf || new Promise((ok, no) => {
    const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'; document.head.appendChild(l);
    const s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'; s.onload = ok; s.onerror = () => { gpsLeaf = null; no(); }; document.head.appendChild(s);
  });
}
async function gpsMap(p) {
  if (GPS.map) { GPS.map.remove(); GPS.map = null; GPS.route = null; }
  if (!$('#gpsMap')) return;
  try { await gpsLoadLeaflet(); } catch (e) { if ($('#gpsMap')) $('#gpsMap').innerHTML = `<div class="muted small" style="padding:20px">${T('Карта не загрузилась — нужен интернет')}</div>`; return; }
  const el = $('#gpsMap'); if (!el || GPS.map) return;
  const Lf = window.L, C = gpsCalc(p), has = p.gps.lat !== '' && p.gps.lng !== '', pts = [];
  GPS.map = Lf.map(el, { zoomControl: true, attributionControl: true });
  Lf.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(GPS.map);
  if (has) { const c = [+p.gps.lat, +p.gps.lng]; Lf.circle(c, { radius: +p.gps.r || 150, color: '#16a34a', weight: 2, fillOpacity: .12 }).addTo(GPS.map).bindTooltip(esc(p.name)); Lf.marker(c).addTo(GPS.map).bindPopup('🏗 ' + esc(p.name)); pts.push(c); }
  C.open.forEach(sh => { if (sh.last.lat == null) return; const c = [sh.last.lat, sh.last.lng], bad = sh.last.on === false;
    Lf.circleMarker(c, { radius: 8, color: '#fff', weight: 2, fillColor: bad ? '#dc2626' : sh.silent > 15 ? '#94a3b8' : '#16a34a', fillOpacity: 1 }).addTo(GPS.map).bindTooltip(`${esc(sh.s.name)} · ${gpsDist(sh.last.dist)}`, { direction: 'top' }); pts.push(c); });
  if (pts.length > 1) GPS.map.fitBounds(pts, { padding: [40, 40], maxZoom: 17 }); else GPS.map.setView(pts[0] || [41.3111, 69.2797], pts.length ? 16 : 11);
}

PAGES.attend = {
  title: 'Приход сотрудников и GPS',
  render(p, a) {
    const C = gpsCalc(p), date = a.date || today(), isToday = date === today(), has = p.gps.lat !== '' && p.gps.lng !== '';
    const dayS = C.S.filter(s => s.date === date).sort((x, y) => x.in.t.localeCompare(y.in.t));
    const st = sh => sh.last.on === false ? `<span class="pill p-sold">⚠️ ${T('не на объекте')}</span>` : sh.last.on ? `<span class="pill p-free">✅ ${T('на объекте')}</span>` : `<span class="pill">${T('объект не задан')}</span>`;
    const ago = m => m < 1 ? T('только что') : Math.round(m) + ' ' + T('мин назад');
    const links = gpsActive(p);
    return `${viaServer() ? '' : `<div class="card"><b>⚠️ Программа открыта как файл.</b> Отметки с телефонов принимает сервер — запустите «Запустить_localhost.bat».</div>`}
    <div class="kpis">
      ${kpi('Сейчас на смене', C.open.length, 'из ' + gpsActive(p).length + ' сотрудников', 'bl')}
      ${kpi('На объекте', C.on.length, has ? 'в радиусе ' + (p.gps.r || 150) + ' м' : 'укажите координаты объекта', 'ok')}
      ${kpi('Не на объекте', C.off.length, C.off.length ? C.off.map(s => s.s.name.split(' ')[0]).join(', ') : 'нет', C.off.length ? 'bad' : 'ok')}
      ${kpi('Нет сигнала > 15 мин', C.silent.length, 'телефон выключен или страница закрыта', C.silent.length ? 'ac' : 'ok')}
      ${kpi('Отработано сегодня', fmt(C.todayMs / 3600e3, 1) + ' ч', C.todayS.length + ' смен', 'ac')}
    </div>
    <div class="grid g2">
      <div class="card"><h3>🗺 Карта объекта <span class="tools"><span class="small muted">🟢 на объекте · 🔴 не на объекте · ⚪ нет сигнала</span></span></h3><div id="gpsMap" class="gps-map"></div></div>
      <div class="card"><h3>⏱ Сейчас на смене (${C.open.length})</h3>
        <div class="tbl-wrap"><table><thead><tr><th>Сотрудник</th><th>Пришёл</th><th class="n">На смене</th><th>Где</th><th>Сигнал</th></tr></thead><tbody>
        ${C.open.map(sh => `<tr class="gps-row" data-act="gpsRoute" data-sid="${sh.sid}" data-in="${sh.in.t}"><td><b>${esc(sh.s.name)}</b><div class="small muted">${esc(sh.s.role)}</div></td><td>${gpsTime(sh.in.t)}${sh.in.on === false ? '<div class="small bad">' + T('не на объекте') + '</div>' : ''}</td><td class="n"><b class="gtimer" data-in="${sh.in.t}">${gpsHM(sh.ms)}</b></td><td>${st(sh)}<div class="small muted">${gpsDist(sh.last.dist)}${sh.last.acc > 100 ? ' · ±' + sh.last.acc + ' м' : ''}</div></td><td class="small ${sh.silent > 15 ? 'warn-t' : 'muted'}">${ago(sh.silent)}</td></tr>`).join('') || `<tr><td colspan="5" class="muted">${T('Сейчас никто не на смене')}</td></tr>`}
        </tbody></table></div>
        ${isToday && C.absent.length ? `<h4 class="mt">Не отметились сегодня (${C.absent.length})</h4><div class="small muted">${C.absent.map(s => esc(s.name)).join(', ')}</div>` : ''}
      </div>
    </div>
    <div class="card"><h3>📋 Смены за день <span class="tools"><input class="txt" type="date" data-arg="date" value="${date}"><button class="btn-s" data-act="gpsTs" data-date="${date}">⬇ Перенести часы в табель</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Сотрудник</th><th>Пришёл</th><th>Ушёл</th><th class="n">Часов</th><th>Уходил с объекта</th><th class="n">В табеле</th><th></th></tr></thead><tbody>
      ${dayS.map(sh => `<tr><td><b>${esc(sh.s.name)}</b><div class="small muted">${esc(sh.s.role)}</div></td>
        <td>${gpsTime(sh.in.t)} ${sh.in.on === false ? `<span class="bad small">⚠️ ${gpsDist(sh.in.dist)}</span>` : sh.in.on ? '✅' : ''}</td>
        <td>${sh.out ? gpsTime(sh.out.t) + ' ' + (sh.out.on === false ? `<span class="bad small">⚠️ ${gpsDist(sh.out.dist)}</span>` : sh.out.on ? '✅' : '') : sh.open ? `<span class="pill p-free">${T('на смене')}</span>` : `<span class="pill p-sold">${T('не закрыл смену')}</span>`}</td>
        <td class="n"><b>${fmt(sh.ms / 3600e3, 1)}</b></td>
        <td>${sh.offs.length ? `<span class="bad">${sh.offs.length} ${T('раз')}, ${T('до')} ${gpsDist(sh.maxDist)}</span>` : `<span class="ok">${T('нет')}</span>`}</td>
        <td class="n">${(p.ts[sh.sid] || {})[sh.date] ?? '—'}</td><td><button class="btn-s" data-act="gpsRoute" data-sid="${sh.sid}" data-in="${sh.in.t}">🗺 ${T('Маршрут')}</button></td></tr>`).join('') || `<tr><td colspan="7" class="muted">${T('За этот день отметок нет')}</td></tr>`}
      </tbody></table></div></div>
    <div class="grid g2">
      <div class="card"><h3>📍 Где находится объект</h3>
        <div class="small muted mb">Сотрудник считается «на объекте», если телефон в этом радиусе. Координаты: откройте объект в Google Картах, нажмите на точку и скопируйте цифры, или вставьте ссылку.</div>
        <div class="form">
          <label class="fld"><span>Широта</span><input class="txt notr" id="gpsLat" value="${esc(p.gps.lat)}" placeholder="41.311100"></label>
          <label class="fld"><span>Долгота</span><input class="txt notr" id="gpsLng" value="${esc(p.gps.lng)}" placeholder="69.279700"></label>
          <label class="fld"><span>Радиус объекта, м</span><input class="txt" type="number" id="gpsR" value="${p.gps.r || 150}"></label>
          <label class="fld" style="grid-column:1/-1"><span>или ссылка Google / Яндекс Карт</span><input class="txt notr" id="gpsUrl" placeholder="https://maps.google.com/...@41.3111,69.2797,17z"></label></div>
        <div class="flex mt"><button class="btn-p" data-act="gpsSave">Сохранить</button><button class="btn-s" data-act="gpsHere">📍 Я сейчас на объекте — взять моё местоположение</button></div>
        <label class="flex mt small"><input type="checkbox" data-bind="gps.autoTs" ${p.gps.autoTs !== false ? 'checked' : ''}> Автоматически записывать часы в табель, когда сотрудник нажал «Ушёл»</label>
      </div>
      <div class="card"><h3>📱 Ссылки для телефонов сотрудников</h3>
        <div class="small muted mb">У каждого сотрудника своя ссылка. Он открывает её на телефоне, нажимает «Пришёл» — включается таймер и GPS, в конце дня — «Ушёл». Можно добавить ссылку на главный экран телефона.</div>
        ${GPS.url ? '' : `<div class="audit a-warn"><span>⚠️</span><div>Телефонам нужен адрес в интернете с https (GPS в браузере работает только так). Укажите его в разделе <a href="#leads?tab=connect" data-act="gpsConnect">Лиды → Подключения</a>.</div><span></span></div>`}
        <div class="tbl-wrap"><table><tbody>${links.map(s => `<tr><td><b>${esc(s.name)}</b><div class="small muted">${esc(s.role)}</div></td><td style="white-space:nowrap">${GPS.url && s.wtok ? `<button class="btn-s" data-act="gpsQr" data-id="${s.id}">▦ QR</button><button class="btn-s" data-act="gpsCopy" data-id="${s.id}">📋</button>${s.phone ? `<a class="btn btn-s" target="_blank" rel="noopener" href="https://wa.me/${String(s.phone).replace(/\D/g, '')}?text=${encodeURIComponent(T('Ваша ссылка для отметки прихода на работу') + ': ' + gpsLink(s))}">🟢 WhatsApp</a>` : ''}` : '<span class="small muted">—</span>'}<button class="btn-s" data-act="gpsNewTok" data-id="${s.id}" title="${T('Новая ссылка — старая перестанет работать')}">↻</button></td></tr>`).join('')}</tbody></table></div>
      </div>
    </div>`;
  },
  async mounted(p) {
    clearInterval(GPS.tick);
    GPS.tick = setInterval(() => document.querySelectorAll('.gtimer').forEach(el => el.textContent = gpsHM(Date.now() - new Date(el.dataset.in))), 30000);
    const had = GPS.url; await gpsSync(); if (!had && GPS.url && curPage === 'attend') return rerender();
    gpsMap(p);
  },
  destroy() { clearInterval(GPS.tick); if (GPS.map) { GPS.map.remove(); GPS.map = null; } },
};
ACT.gpsConnect = () => go('leads', { tab: 'connect' });
function gpsParse(s) { const m = String(s).match(/(-?\d{1,2}\.\d{3,})[,\s/]+(-?\d{1,3}\.\d{3,})/); return m ? [m[1], m[2]] : null; }
ACT.gpsSave = () => {
  const p = P(), url = $('#gpsUrl').value.trim(), raw = $('#gpsLat').value.trim();
  let u = url ? gpsParse(url) : null; if (url && !u) return toast('В ссылке не найдены координаты');
  if (!u && /\d[.,]\d+\s*[,;\s]\s*-?\d/.test(raw) && !$('#gpsLng').value.trim()) u = gpsParse(raw.replace(/(\d),(\d)/g, '$1.$2'));
  let [lat, lng] = u || [raw.replace(',', '.'), $('#gpsLng').value.trim().replace(',', '.')];
  if ((lat || lng) && (!isFinite(+lat) || !isFinite(+lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180)) return toast('Неверные координаты');
  p.gps.lat = lat ? String(+lat) : ''; p.gps.lng = lng ? String(+lng) : ''; p.gps.r = Math.max(30, num($('#gpsR').value) || 150);
  save(); gpsSync(); rerender(); toast('Координаты объекта сохранены');
};
ACT.gpsHere = () => {
  if (!navigator.geolocation) return toast('Геолокация недоступна');
  toast('Определяю местоположение…');
  navigator.geolocation.getCurrentPosition(c => { $('#gpsLat').value = c.coords.latitude.toFixed(6); $('#gpsLng').value = c.coords.longitude.toFixed(6); $('#gpsUrl').value = ''; ACT.gpsSave(); },
    () => toast('Не удалось получить местоположение — разрешите доступ к геолокации'), { enableHighAccuracy: true, timeout: 20000 });
};
ACT.gpsTs = d => { const p = P(); let n = 0; [...new Set(gpsShifts(p).filter(s => s.date === d.date && s.out).map(s => s.sid))].forEach(sid => { if (gpsToTs(p, d.date, sid)) n++; }); save(); rerender(); toast(n ? `${T('Часы перенесены в табель')}: ${n}` : 'Нет закрытых смен за этот день'); };
ACT.gpsCopy = d => { const v = gpsLink(P().staff.find(s => s.id === d.id)); (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(() => toast('Ссылка скопирована'), () => prompt('', v)); };
ACT.gpsNewTok = async d => { if (!confirm(T('Сделать новую ссылку? Старая перестанет работать.'))) return; const s = P().staff.find(x => x.id === d.id); if (s.wtok) (DB.settings.gpsRevoke = DB.settings.gpsRevoke || []).push(s.wtok); s.wtok = gpsTok(); save(); await gpsSync(); rerender(); toast('Новая ссылка готова'); };
ACT.gpsQr = d => {
  const s = P().staff.find(x => x.id === d.id), link = gpsLink(s);
  openModal(`<h2>📱 ${esc(s.name)}</h2><p class="small">Сотрудник наводит камеру телефона на код и открывает ссылку. Дальше: «Пришёл» утром и «Ушёл» вечером. Нужно разрешить доступ к местоположению.</p><div id="gpsQrBox" class="gps-qr"></div><div class="small notr" style="word-break:break-all">${esc(link)}</div>`);
  const draw = () => { const b = $('#gpsQrBox'); if (b && window.QRCode) new window.QRCode(b, { text: link, width: 240, height: 240 }); };
  if (window.QRCode) return draw();
  const sc = document.createElement('script'); sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'; sc.onload = draw; sc.onerror = () => { const b = $('#gpsQrBox'); if (b) b.textContent = T('QR-код не загрузился — нужен интернет. Отправьте ссылку.'); }; document.head.appendChild(sc);
};
ACT.gpsRoute = d => {
  const p = P(), sh = gpsShifts(p).find(s => s.sid === d.sid && s.in.t === d.in); if (!sh || !GPS.map) return;
  const Lf = window.L, pts = sh.evs.filter(e => e.lat != null).map(e => [e.lat, e.lng]); if (!pts.length) return;
  if (GPS.route) GPS.map.removeLayer(GPS.route);
  GPS.route = Lf.layerGroup([Lf.polyline(pts, { color: '#2563eb', weight: 3, dashArray: '6 6' }), ...sh.evs.filter(e => e.lat != null).map(e => Lf.circleMarker([e.lat, e.lng], { radius: 5, color: e.on === false ? '#dc2626' : '#16a34a', fillOpacity: 1 }).bindTooltip(`${gpsTime(e.t)} · ${{ in: T('пришёл'), out: T('ушёл'), ping: T('сигнал') }[e.type]} · ${gpsDist(e.dist)}`))]).addTo(GPS.map);
  GPS.map.fitBounds(pts.concat(p.gps.lat !== '' ? [[+p.gps.lat, +p.gps.lng]] : []), { padding: [40, 40], maxZoom: 17 });
  $('#gpsMap').scrollIntoView({ behavior: 'smooth', block: 'center' }); toast(`${sh.s.name}: ${T('маршрут за смену')}`);
};

/* Демо: сегодняшние смены для демо-проекта */
function gpsDemo(p) {
  const R = rng(777), lat0 = 41.3111, lng0 = 69.2797, now = Date.now(), M = 60000;
  p.gps = { lat: String(lat0), lng: String(lng0), r: 150, autoTs: true };
  const ev = (sid, type, tms, dm, ang) => { const a = ang ?? R() * 6.28, d = dm ?? R() * 90, la = lat0 + d * Math.cos(a) / 111320, ln = lng0 + d * Math.sin(a) / (111320 * Math.cos(lat0 * Math.PI / 180));
    p.geoEv.push({ seq: 0, demo: true, t: new Date(tms).toISOString(), sid, type, lat: +la.toFixed(6), lng: +ln.toFixed(6), acc: 8 + Math.floor(R() * 25), dist: Math.round(d), on: d <= 150 }); };
  const st = gpsActive(p).slice(0, 12);
  st.forEach((s, i) => {
    if (i === 11) return;                                   // один не пришёл
    const am = new Date(today() + 'T07:40:00').getTime() + R() * 45 * M, tin = now - am > 2 * 3600e3 ? am : now - (1 + R() * 2) * 3600e3;
    ev(s.id, 'in', tin, i === 7 ? 2400 : null);              // один отметился не на объекте
    for (let t = tin + 5 * M; t < now - (i === 9 ? 40 * M : 2 * M); t += 5 * M) ev(s.id, 'ping', t, i === 4 && t > now - 50 * M ? 3200 : null, i === 4 && t > now - 50 * M ? 0.8 : null);
    if (i === 10) ev(s.id, 'out', now - 20 * M);
  });
  st.slice(0, 8).forEach((s, i) => { const d = addDays(today(), -1), t0 = new Date(d + 'T08:0' + (i % 10) + ':00').getTime(); ev(s.id, 'in', t0); ev(s.id, 'out', t0 + (8 + (i % 3)) * 3600e3); });
}
