'use strict';
/* ===== Связь с сервером Laravel: данные компании на сервере, совместная работа нескольких пользователей ===== */
const SV = window.OFTOB || null;
const SV_DIRTY = SV ? 'oftob_dirty_' + SV.ws : '';
const SV_LANG = SV ? 'oftob_lang_u' + SV.user.id : '';
const svGet = k => { try { return localStorage.getItem(k); } catch (e) { return null; } };
const svSet = (k, v) => { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { } };

if (SV) {
  /* 1. Все запросы к своему серверу — с CSRF-токеном; истёк вход — на страницу входа */
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const nativeFetch = window.fetch.bind(window);
  window.fetch = (url, opt = {}) => {
    const u = typeof url === 'string' ? url : (url && url.url) || '';
    if (/^\/(?!\/)/.test(u)) {
      const h = new Headers(opt.headers || {}); h.set('X-CSRF-TOKEN', csrf()); h.set('X-Requested-With', 'XMLHttpRequest'); if (!h.has('Accept')) h.set('Accept', 'application/json');
      opt = Object.assign({}, opt, { headers: h, credentials: 'same-origin' });
    }
    return nativeFetch(url, opt).then(r => { if ((r.status === 401 || r.status === 419) && /^\/api\//.test(u)) location.href = '/login'; return r; });
  };

  /* 2. До запуска программы кладём данные с сервера туда, откуда программа их читает */
  let local = svGet(STORE_KEY), dirty = svGet(SV_DIRTY);
  SV.pushOnStart = false; SV.pushAll = false;
  if (dirty !== null && +dirty === SV.version && local) SV.pushOnStart = true;          // есть несохранённые на сервере изменения
  else if (SV.state) { if (dirty !== null && local) SV.lostLocal = true; local = SV.state; }   // берём свежие данные с сервера
  // данные другой компании или старой версии в этом браузере никогда не подхватываются: у каждой компании свой ключ
  else { SV.pushOnStart = SV.pushAll = true; }
  if (dirty !== null && +dirty !== SV.version) svSet(SV_DIRTY, null);
  if (local) {
    try {
      const d = JSON.parse(local); d.settings = d.settings || {};
      if (d.settings.serverWs !== SV.ws) { d.settings.serverWs = SV.ws; delete d.settings.inboxSeq; delete d.settings.geoSeq; delete d.settings.gpsRevoke; }
      d.settings.company = SV.company;
      const lang = (SV.user && SV.user.locale) || svGet(SV_LANG); if (lang) { d.settings.lang = lang; svSet(SV_LANG, lang); }
      svSet(STORE_KEY, JSON.stringify(d));
    } catch (e) { }
  } else svSet(STORE_KEY, null);
}

/* 3. Сохранение: сразу в браузер, через 1,5 сек — на сервер */
let svPushT = null, svPushing = false;
const svTouched = new Set();
function svMark(t) { const s = $('#saveState'); if (!s) return; s.textContent = T(t); clearTimeout(s._t); if (t.startsWith('✓')) s._t = setTimeout(() => s.textContent = '', 2000); }
if (SV) {
  const localSave = save;
  save = function () {
    localSave.apply(this, arguments);
    if (!SV.canEdit) return;
    svSet(SV_DIRTY, String(SV.version)); if (DB && DB.currentId) svTouched.add(DB.currentId);
    clearTimeout(svPushT); svPushT = setTimeout(svPush, 1500); svMark('… сохраняю');
  };
}
async function svPush() {
  if (!SV || !SV.canEdit || !DB) return;
  if (svPushing) { clearTimeout(svPushT); svPushT = setTimeout(svPush, 800); return; }
  svPushing = true; const sent = new Set(svTouched); svTouched.clear();
  try {
    const r = await fetch('/api/state', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ version: SV.version, data: JSON.stringify(DB) }) });
    if (r.status === 409) {   // кто-то сохранил раньше: объединяем и отправляем ещё раз
      const j = await r.json(); svMerge(j, sent); SV.version = j.version; sent.forEach(x => svTouched.add(x)); svTouched.add('*');
      svPushing = false; return svPush();
    }
    if (r.status === 402 || r.status === 403) {   // тариф: лимит объектов, подписка закончилась, только просмотр — повтор не поможет
      const j = await r.json().catch(() => ({})); sent.forEach(x => svTouched.add(x));
      const msg = j.error || j.message || T('Нет прав на сохранение');
      svMark('⚠ ' + msg); svPushing = false;
      if (j.billing) { location.href = '/company/billing'; return; }
      openModal(`<h2>⚠️ ${T('Изменения не сохранены')}</h2><p>${esc(msg)}</p><div class="flex mt">${j.limit ? `<a class="btn btn-p" href="/company/billing">💎 ${T('Выбрать тариф')}</a>` : ''}<button data-act="closeModal">${T('Закрыть')}</button></div>`);
      return;
    }
    if (!r.ok) throw new Error(r.status);
    const j = await r.json(); SV.version = j.version;
    // сервер поправил данные (права сотрудника, сделки по квартирам) — показываем итог, если за это время ничего не меняли
    if (j.data && !svTouched.size) svApply(j.data);
    svSet(SV_DIRTY, svTouched.size ? String(SV.version) : null);
    svMark('✓ сохранено на сервере');
  } catch (e) {
    sent.forEach(x => svTouched.add(x)); svMark('⚠ нет связи — сохраню позже');
    clearTimeout(svPushT); svPushT = setTimeout(svPush, 6000);
  }
  svPushing = false;
}
function svMerge(j, mine) {
  let S; try { S = JSON.parse(j.data); } catch (e) { return; }
  if (!S || !Array.isArray(S.projects)) return;
  const ours = new Map(DB.projects.map(p => [p.id, p]));
  S.projects = S.projects.map(p => (mine.has(p.id) || mine.has('*')) && ours.has(p.id) ? ours.get(p.id) : p);
  DB.projects.forEach(p => { if (!S.projects.some(x => x.id === p.id)) S.projects.push(p); });
  S.settings = Object.assign({}, S.settings, DB.settings); S.currentId = DB.currentId;
  S.projects.forEach(migrate); DB = S;
  svSet(STORE_KEY, JSON.stringify(DB));
}
/* Принять данные с сервера целиком (текущий объект и язык — свои) */
function svApply(raw) {
  let S; try { S = JSON.parse(raw); } catch (e) { return false; }
  if (!S || !Array.isArray(S.projects) || !S.projects.length) return false;
  const cur = DB.currentId; S.settings = S.settings || {}; S.settings.lang = DB.settings.lang; S.settings.serverWs = SV.ws;
  ['inboxSeq', 'geoSeq'].forEach(k => { if (DB.settings[k] > (S.settings[k] || 0)) S.settings[k] = DB.settings[k]; });
  S.currentId = S.projects.some(p => p.id === cur) ? cur : S.projects[0].id; S.projects.forEach(migrate);
  DB = S; svSet(STORE_KEY, JSON.stringify(DB));
  const busy = !$('#modal').classList.contains('hidden') || /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '');
  if (!busy) rerender(); else SV.needRender = true;
  return true;
}
/* 4. Изменения других пользователей подтягиваются каждые 15 секунд */
async function svPull() {
  if (!SV || svPushing || svTouched.size || document.hidden || !DB) return;
  try {
    const v = (await (await fetch('/api/state/version')).json()).version;
    if (!(v > SV.version)) return;
    const j = await (await fetch('/api/state')).json();
    if (svTouched.size || svPushing) return;
    if (!svApply(j.data)) return;
    SV.version = j.version;
    svMark('✓ данные обновлены');
  } catch (e) { }
}
/* немедленная отправка (смена языка и т. п.) */
function svFlush() { svSet(STORE_KEY, JSON.stringify(DB)); svSet(SV_LANG, DB.settings.lang); if (!SV || !SV.canEdit) return Promise.resolve(); svTouched.add('*'); clearTimeout(svPushT); return svPush(); }
if (SV) {
  setInterval(svPull, 15000);
  setInterval(() => { if (SV.needRender && $('#modal').classList.contains('hidden') && !/INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '')) { SV.needRender = false; rerender(); } }, 2000);
  window.addEventListener('beforeunload', () => { if (DB) svSet(STORE_KEY, JSON.stringify(DB)); if (svTouched.size) svSet(SV_DIRTY, String(SV.version)); });
  setTimeout(() => {
    if (!DB) return;
    if (SV.lostLocal) toast('Загружены свежие данные с сервера');
    if (SV.pushOnStart && SV.canEdit) { DB.projects.forEach(p => svTouched.add(p.id)); svTouched.add('*'); svPush(); }
  }, 1200);
}
