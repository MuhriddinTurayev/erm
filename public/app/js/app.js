'use strict';
/* ===== Навигация, события, запуск ===== */
const NAV = [
  ['home', '🏠', 'Главная'], ['objects', '🏢', 'Все объекты'], ['cash', '💵', 'Касса компании'], ['sell', '🏬', 'Продажи и дизайн'], ['leads', '📞', 'Лиды', 'sell'], ['sales', '🔑', 'Продажи квартир', 'sell'], ['apt', '🧾', 'Квартира для клиента', 'sell'], ['design', '🛋️', 'Дизайн квартиры 3D', 'sell'], ['site', '🏙', 'Объект в 3D', 'sell'],
  ['ai', '🤖', 'ИИ-консультант'], ['dashboard', '📊', 'Панель'],
  ['build', '🏗️', 'Проект и площадка'], ['project', '🏢', 'Проект и здание', 'build'], ['prep', '🚧', 'Подготовка площадки', 'build'],
  ['ground', GEO_ICON, 'Геология и котлован'], ['geology', GEO_ICON, 'Геология грунта', 'ground'], ['pit', '⛏️', 'Котлован и грунт', 'ground'],
  ['finance', '💼', 'Финансы'], ['smeta', '📑', 'Смета и прибыль', 'finance'], ['accounting', '💰', 'Бухгалтерия', 'finance'], ['fleet', '🚛', 'Техника и топливо', 'finance'], ['materials', '🧱', 'Материалы', 'finance'], ['staff', '🪪', 'Сотрудники и табель', 'finance'], ['attend', '📍', 'Приход и GPS', 'finance'], ['labour', '👷', 'Бригады', 'finance'],
  ['hiring', '🤝', 'Найм сотрудников'], ['safety', '🦺', 'Техника безопасности'], ['schedule', '📅', 'График работ'], ['legal', '⚖️', 'Юрист'], ['settings', '⚙️', 'Настройки'],
];
let curPage = 'home', pageArgs = {};

function load() {
  try { DB = JSON.parse(localStorage.getItem(STORE_KEY)); } catch (e) { DB = null; }
  if (!DB || !Array.isArray(DB.projects) || !DB.projects.length) {
    DB = { settings: {}, projects: [], currentId: null };
  }
  DB.settings = Object.assign({ lang: 'ru', aiModel: 'claude-opus-5-5', currency: 'сум', company: 'Моя строительная компания', fuelPrice: 12500, normHours: 8, overtimeK: 1.5 }, DB.settings);
  // новая компания начинает с чистого объекта (без демо и чужих данных); без сервера — демо для знакомства
  if (!DB.projects.length) { const p = window.OFTOB ? newProject(window.OFTOB.firstObject || T('Мой первый объект'), today()) : demoProject(); DB.projects.push(p); DB.currentId = p.id; save(); }
  DB.projects.forEach(migrate);
  DB.projects.filter(p => /демо/i.test(p.name)).forEach(p => { if (!Object.keys(p.safety.brief).length) safetyDemo(p); if (!p.legal.contracts.length) legalDemo(p); if (!p.leads.length) leadsDemo(p); if (!p.geoEv.length) gpsDemo(p); });
  if (!P()) DB.currentId = DB.projects[0].id;
  const ql = new URLSearchParams(location.search).get('lang'); if (ql && LANGS.some(l => l[0] === ql)) DB.settings.lang = ql;
}
function go(page, args = {}) {
  if (!PAGES[page] || !canPage(page)) page = 'home';
  if (PAGES[curPage] && PAGES[curPage].destroy) PAGES[curPage].destroy();
  curPage = page; pageArgs = args;
  if (location.hash.slice(1) !== page) history.replaceState(null, '', '#' + page);
  render(); window.scrollTo(0, 0);
}
function render() {
  const p = P(), pg = PAGES[curPage];
  if (pg.destroy && D3.renderer) pg.destroy();
  const grp = GROUP_OF[curPage] || (GROUPS[curPage] ? curPage : null);
  $('#nav').innerHTML = NAV.filter(([k, , , sub]) => (!sub || sub === grp) && canPage(k)).map(([k, i, t, sub]) => `<a data-act="go" data-page="${k}" class="${k === curPage ? 'on' : ''}${sub ? ' sub' : ''}${GROUPS[k] && grp === k && k !== curPage ? ' open' : ''}"><i>${i}</i>${t}${GROUPS[k] ? `<em class="caret">${grp === k ? '▾' : '▸'}</em>` : ''}</a>`).join('');
  $('#projSel').innerHTML = DB.projects.map(x => `<option value="${x.id}"${x.id === p.id ? ' selected' : ''}>${esc(x.name)}</option>`).join('');
  $('#sideFoot').innerHTML = `${esc(DB.settings.company)}<br>${window.OFTOB ? `${esc((SAAS.plan && SAAS.plan.name) || '')} · ${T('объектов')}: ${objCount()}${SAAS.plan && SAAS.plan.objects ? ' / ' + SAAS.plan.objects : ''}` : 'Данные хранятся в этом браузере'}`;
  $('#pageTitle').textContent = pg.title;
  document.title = T(pg.title) + ' — ' + APP_NAME; $('#aiFab').style.display = (curPage === 'ai' || curPage === 'home') ? 'none' : '';
  $('#langSel').innerHTML = opts(LANGS, curLang()); $('#homeBtn').style.display = curPage === 'home' ? 'none' : '';
  $('#main').innerHTML = planNotice() + (GROUP_OF[curPage] ? groupSubnav(curPage) : '') + pg.render(p, pageArgs);
  if (pg.mounted) pg.mounted(p, pageArgs);
  document.body.classList.remove('menu');
}
function rerender() { const y = window.scrollY; render(); window.scrollTo(0, y); }

document.addEventListener('input', e => {
  const el = e.target; if (!el.dataset || el.dataset.bind == null) return;
  applyBind(el);
  const pg = PAGES[curPage], r = $('#results');
  if (pg.results && r && !r.contains(el)) r.innerHTML = pg.results(P(), pageArgs);
  if (pg.onInput) pg.onInput(el);
});
document.addEventListener('change', e => {
  const el = e.target, pg = PAGES[curPage];
  if (el.id === 'langSel') { DB.settings.lang = el.value; if (SAAS) fetch('/api/locale', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lang: el.value }) }).catch(() => { }); if (window.svFlush) { svFlush().finally(() => location.reload()); return; } try { localStorage.setItem(STORE_KEY, JSON.stringify(DB)); } catch (er) { } location.reload(); return; }
  if (el.id === 'projSel') { DB.currentId = el.value; save(); go(curPage === 'design' ? 'home' : curPage); return; }
  if (el.id === 'importFile' && el.files[0]) { importJson(el.files[0]); el.value = ''; return; }
  if (el.dataset.arg) { pageArgs[el.dataset.arg] = el.value; if (!el.value) delete pageArgs[el.dataset.arg]; if (curPage === 'fleet' && el.dataset.arg === 'month') pageArgs.month = el.value; rerender(); return; }
  if (el.dataset.bind != null) {
    applyBind(el);
    const f = pg.fullOnChange;
    if (el.dataset.re != null || (typeof f === 'function' ? f(el) : f)) rerender();
  }
  if (pg.onChange) pg.onChange(el);
});
document.addEventListener('click', e => {
  const el = e.target.closest('[data-act]'); if (!el) return;
  const fn = ACT[el.dataset.act]; if (fn) { e.preventDefault(); fn(el.dataset, el); }
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
$('#modal').addEventListener('click', e => { if (e.target.id === 'modal') closeModal(); });

ACT.go = d => go(d.page);
ACT.menu = () => document.body.classList.toggle('menu');
ACT.closeModal = closeModal;
ACT.newProject = async () => {
  if (!canAddObject()) return;
  // своя форма вместо системного окна браузера: название, адрес и дата начала
  const f = await ui.form(T('Новый объект'), [
    { name: 'name', label: 'Название объекта (жилого комплекса)', placeholder: T('Например: ЖК «Новый Город»'), required: true },
    { name: 'address', label: 'Адрес', placeholder: T('Город, район, улица') },
    { name: 'start', type: 'date', label: 'Дата начала', value: today(), required: true },
  ], { ok: T('Создать объект') });
  if (!f || !f.name) return;
  const p = newProject(f.name.slice(0, 120), f.start || today()); p.address = (f.address || '').slice(0, 200);
  DB.projects.push(p); DB.currentId = p.id; save(); go('project'); toast('Объект создан — заполните параметры здания');
};
window.addEventListener('hashchange', () => { const h = location.hash.slice(1); if (h && h !== curPage && PAGES[h]) go(h); });

load();
{ const [hp, hq] = location.hash.slice(1).split('?'); const ha = {}; new URLSearchParams(hq || '').forEach((v, k) => ha[k] = v); go(hp || 'home', ha); }
i18nStart();
