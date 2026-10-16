'use strict';
/* ===== Платформа: роли, тариф, все объекты компании, касса компании ===== */
const SAAS = window.OFTOB || null;

/* ---------- Доступ по ролям ---------- */
function canPage(k) {
  if (!SAAS || !Array.isArray(SAAS.pages)) return true;
  if (k === 'home' || k === 'settings' && SAAS.isAdmin) return true;
  return SAAS.pages.includes(k);
}
const seesMoney = () => !SAAS || SAAS.seesMoney !== false;
if (SAAS && !seesMoney()) document.body.classList.add('no-money');

/* ---------- Лимит объектов по тарифу ---------- */
const isDemoProj = p => !!p.demo;   // только пометка: название «demo» лимит тарифа не обходит
const objCount = () => DB.projects.filter(p => !isDemoProj(p)).length;
function canAddObject() {
  if (!SAAS) return true;
  if (SAAS.restricted) { toast('Новые объекты добавляет руководитель компании'); return false; }
  if (!SAAS.canEdit) { toast('Только просмотр'); return false; }
  const max = SAAS.plan && SAAS.plan.objects;
  if (max && objCount() >= max) {
    openModal(`<h2>🏗 ${T('Лимит объектов по тарифу')}</h2>
      <p>${T('По тарифу')} «${esc(SAAS.plan.name || '')}» ${T('можно вести не больше')} <b>${max}</b> ${T('объектов')}. ${T('Демо-объект не считается — его можно удалить в настройках.')}</p>
      <div class="flex mt"><a class="btn btn-p" href="/company/billing">💎 ${T('Выбрать тариф выше')}</a><button data-act="closeModal">${T('Закрыть')}</button></div>`);
    return false;
  }
  return true;
}
if (ACT.projCopy) { const pc = ACT.projCopy; ACT.projCopy = d => { if (canAddObject()) pc(d); }; }
if (ACT.projDel) {
  const pd = ACT.projDel;
  // на сервере: удаляет и 3D-модели, интерьеры, ссылки покупателей; не даст удалить объект с бронями, продажами и деньгами
  ACT.projDel = async d => {
    if (!SAAS) return pd(d);
    if (SAAS.restricted || !SAAS.isAdmin) return toast('Объекты удаляет руководитель компании');
    if (DB.projects.length < 2) return toast('Нельзя удалить единственный проект');
    const x = DB.projects.find(z => z.id === d.id); if (!x) return;
    const name = await ui.prompt(T('Удалить объект') + ' «' + x.name + '»', { label: T('Чтобы подтвердить, впишите название объекта:') + ' ' + x.name, ok: T('Удалить навсегда'), required: true });
    if (name == null) return;
    const r = await fetch('/company/objects/' + encodeURIComponent(d.id), { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ confirm: name }) }).catch(() => null);
    const j = r ? await r.json().catch(() => ({})) : {};
    if (!r || !r.ok || !j.ok) return ui.alert ? ui.alert(j.error || j.message || T('Не удалось удалить — проверьте интернет и попробуйте ещё раз.')) : alert(j.error || j.message);
    toast('Объект удалён'); setTimeout(() => location.reload(), 700);   // данные компании изменились на сервере — берём их заново
  };
}

/* ---------- Расчёты по деньгам ---------- */
function moneyOn(p, date) { let out = 0, inc = 0; p.tx.forEach(t => { if (t.date === date && !isTransfer(t)) { if (t.type === 'in') inc += t.amount; else out += t.amount; } }); return { out, inc }; }
function moneyRange(p, from, to) { let out = 0, inc = 0; p.tx.forEach(t => { if (t.date >= from && t.date <= to && !isTransfer(t)) { if (t.type === 'in') inc += t.amount; else out += t.amount; } }); return { out, inc }; }
const yesterday = () => addDays(today(), -1);
function overdueStages(p) { const t = today(); return p.stages.filter(s => s.progress < 100 && addDays(s.start, s.dur) < t).length; }
function periodRange(per) {
  const t = today();
  return { today: [t, t], yesterday: [yesterday(), yesterday()], week: [addDays(t, -6), t], month: [monthNow() + '-01', t], all: ['0000', '9999'] }[per] || [t, t];
}
const PERIODS = [['today', 'Сегодня'], ['yesterday', 'Вчера'], ['week', '7 дней'], ['month', 'Этот месяц'], ['all', 'Всё время']];
const deltaHtml = (a, b) => { if (!b) return ''; const d = Math.round((a - b) / b * 100); return `<span class="delta ${d > 0 ? 'up' : 'down'}">${d > 0 ? '▲' : '▼'} ${Math.abs(d)}%</span> `; };

/* ---------- Все объекты компании ---------- */
PAGES.objects = {
  title: 'Все объекты',
  render() {
    const t = today(), y = yesterday(), mf = monthNow() + '-01', max = SAAS && SAAS.plan ? SAAS.plan.objects : 0;
    const rows = DB.projects.map(p => {
      const F = finance(p), d = moneyOn(p, t), dy = moneyOn(p, y), mo = moneyRange(p, mf, t), A = genApts(p);
      return { p, F, d, dy, mo, pr: progress(p), sold: A.filter(a => a.status === 'sold').length, apts: A.length, od: overdueStages(p), staff: p.staff.filter(s => s.active !== false).length };
    });
    const tot = k => sum(rows, r => k(r));
    const money = seesMoney();
    return `
    ${money ? `<div class="kpis">
      ${kpi('Касса всех объектов', moneyS(tot(r => r.F.bal)), 'поступило ' + moneyS(tot(r => r.F.inc)) + ' · потрачено ' + moneyS(tot(r => r.F.out)), tot(r => r.F.bal) >= 0 ? 'ok' : 'bad')}
      ${kpi('Потрачено сегодня', moneyS(tot(r => r.d.out)), deltaHtml(tot(r => r.d.out), tot(r => r.dy.out)) + 'вчера ' + moneyS(tot(r => r.dy.out)), 'bad')}
      ${kpi('Расходы за месяц', moneyS(tot(r => r.mo.out)), 'поступления ' + moneyS(tot(r => r.mo.inc)), 'bl')}
      ${kpi('Объектов', `${objCount()}${max ? ' / ' + max : ''}`, max ? 'по тарифу «' + esc(SAAS.plan.name || '') + '»' : 'без ограничений', 'ac')}
    </div>` : ''}
    <div class="obj-grid">
      ${rows.map(r => `<div class="obj-card${r.p.id === P().id ? ' cur' : ''}">
        <div class="obj-top"><div><b>${esc(r.p.name)}</b>${isDemoProj(r.p) ? ' <span class="pill p-free">демо</span>' : ''}<div class="small muted">${esc(r.p.address || '—')}</div></div><span class="pill p-${r.p.status === 'Строительство' ? 'booked' : 'free'}">${esc(r.p.status)}</span></div>
        <div class="obj-prog"><div class="flex" style="justify-content:space-between"><span class="small muted">Готовность</span><b>${fmt(r.pr, 1)}%</b></div>${progBar(r.pr)}</div>
        <div class="obj-stats">
          ${money ? `<div><span>Касса</span><b class="${r.F.bal < 0 ? 'bad' : ''}">${moneyS(r.F.bal)}</b></div><div><span>Сегодня</span><b class="bad">${r.d.out ? '−' + moneyS(r.d.out) : '—'}</b></div><div><span>За месяц</span><b>${moneyS(r.mo.out)}</b></div>` : ''}
          <div><span>Продано</span><b>${r.sold} / ${r.apts}</b></div><div><span>Сотрудников</span><b>${r.staff}</b></div><div><span>Просрочено этапов</span><b class="${r.od ? 'bad' : 'ok'}">${r.od || '✓'}</b></div>
        </div>
        <div class="flex">${r.p.id === P().id ? '<span class="pill p-sold">открыт сейчас</span>' : `<button class="btn-p btn-s" data-act="objOpen" data-id="${r.p.id}">Открыть объект</button>`}
          ${money ? `<button class="btn-s" data-act="objCash" data-id="${r.p.id}">💰 Касса</button>` : ''}<button class="btn-s" data-act="objGo" data-id="${r.p.id}" data-page="schedule">📅 График</button></div>
      </div>`).join('')}
      ${!SAAS || (!SAAS.restricted && SAAS.canEdit) ? `<button class="obj-card obj-add" data-act="newProject"><span>＋</span><b>Добавить объект</b><small class="muted">${max ? `${objCount()} из ${max} по тарифу` : 'жилой комплекс, дом или другую стройку'}</small></button>` : ''}
    </div>`;
  }
};
ACT.objOpen = d => { DB.currentId = d.id; save(); go('home'); };
ACT.objGo = d => { DB.currentId = d.id; save(); go(d.page); };
ACT.objCash = d => { go('cash', { obj: d.id }); };

/* ---------- Касса компании: все объекты, сегодня / вчера / на что ---------- */
/* Деньги компании на счету: что лежит в кассах и на счетах (ERP) + записи только в бухгалтерии объектов
   (операции касс в бухгалтерии объектов помечены link.kind = 'cash' — их не считаем второй раз). Без ERP — по бухгалтерии объектов. */
function companyMoney(projs) {
  if (!SAAS || SAAS.cashDesks == null) return sum(projs, p => finance(p).bal);
  return SAAS.cashDesks + sum(projs, p => sum(p.tx.filter(x => !(x.link && x.link.kind === 'cash')), x => x.type === 'in' ? x.amount : -x.amount));
}
PAGES.cash = {
  title: 'Касса компании',
  render(_, a) {
    const per = a.per || 'today', [from, to] = periodRange(per), obj = a.obj || '', cat = a.cat || '';
    const projs = DB.projects.filter(p => !obj || p.id === obj);
    const t = today(), y = yesterday();
    const all = []; projs.forEach(p => p.tx.forEach(x => all.push({ ...x, pid: p.id, pname: p.name })));
    const bal = obj ? sum(projs, p => finance(p).bal) : companyMoney(projs), dT = sum(projs, p => moneyOn(p, t).out), dY = sum(projs, p => moneyOn(p, y).out), iT = sum(projs, p => moneyOn(p, t).inc);
    const wk = sum(projs, p => moneyRange(p, addDays(t, -6), t).out), mo = sum(projs, p => moneyRange(p, monthNow() + '-01', t).out);
    const inPer = all.filter(x => x.date >= from && x.date <= to);
    const outPer = inPer.filter(x => x.type === 'out' && !isTransfer(x)), outSum = sum(outPer, x => x.amount), inSum = sum(inPer.filter(x => x.type === 'in' && !isTransfer(x)), x => x.amount);
    const cats = {}; outPer.forEach(x => cats[x.cat] = (cats[x.cat] || 0) + x.amount);
    const catRows = Object.entries(cats).sort((a, b) => b[1] - a[1]);
    const days = []; for (let i = 29; i >= 0; i--) { const d = addDays(t, -i); days.push([d, sum(all.filter(x => x.type === 'out' && x.date === d && !isTransfer(x)), x => x.amount)]); }
    const mx = Math.max(1, ...days.map(d => d[1]));
    const list = inPer.filter(x => !cat || x.cat === cat).sort((p, q) => q.date.localeCompare(p.date) || q.amount - p.amount);
    const byObj = DB.projects.map(p => ({ p, d: moneyOn(p, t).out, y: moneyOn(p, y).out, r: moneyRange(p, from, to), bal: finance(p).bal })).sort((p, q) => q.r.out - p.r.out);
    const perName = (PERIODS.find(x => x[0] === per) || [])[1];
    return `
    <div class="cash-bar">
      <div class="seg">${PERIODS.map(([k, n]) => `<button class="${k === per ? 'on' : ''}" data-act="cashPer" data-p="${k}">${n}</button>`).join('')}</div>
      <select data-arg="obj"><option value="">Все объекты</option>${opts(DB.projects.map(p => [p.id, p.name]), obj)}</select>
      ${SAAS && SAAS.canEdit ? '<button class="btn-p btn-s" data-act="cashAdd">+ Расход / приход</button>' : ''}
    </div>
    <div class="kpis">
      ${kpi(obj ? 'Приход − расход объекта' : 'Деньги на счету (касса)', money(bal), obj ? esc((DB.projects.find(p => p.id === obj) || {}).name || '') : (SAAS && SAAS.cashDesks != null ? 'во всех кассах и на счетах' : 'по всем объектам'), bal >= 0 ? 'ok' : 'bad')}
      ${kpi('Потрачено сегодня', moneyS(dT), dY ? deltaHtml(dT, dY) + 'к вчера' : '', 'bad')}
      ${kpi('Потрачено вчера', moneyS(dY), fdate(y))}
      ${kpi('Поступило сегодня', moneyS(iT), '', 'ok')}
      ${kpi('За 7 дней', moneyS(wk), 'в среднем ' + moneyS(wk / 7) + ' в день', 'bl')}
      ${kpi('За месяц', moneyS(mo), monthName(monthNow()), 'ac')}
    </div>
    <div class="grid g2">
      <div class="card"><h3>📊 Расходы по дням (30 дней)</h3>
        <div class="cbars">${days.map(([d, v]) => `<div class="cb${d === t ? ' now' : ''}" title="${fdate(d)}: ${money(v)}"><i style="height:${v / mx * 100}%"></i><span>${+d.slice(8) % 5 === 0 || d === t ? d.slice(8) : ''}</span></div>`).join('')}</div>
      </div>
      <div class="card"><h3>🧾 На что ушли деньги <span class="tools"><span class="pill p-free">${perName}</span></span></h3>
        ${catRows.length ? catRows.map(([c, v]) => `<div class="cat-row${c === cat ? ' on' : ''}" data-act="cashCat" data-c="${esc(c)}"><div class="flex" style="justify-content:space-between"><span>${esc(c)}</span><b>${moneyS(v)} · ${fmt(v / outSum * 100)}%</b></div>${progBar(v / outSum * 100, 'linear-gradient(90deg,#f59e0b,#ea580c)')}</div>`).join('') : '<p class="muted">Расходов за этот период нет</p>'}
        <div class="small muted mt">Итого расходов: <b>${money(outSum)}</b> · поступлений: <b class="ok">${money(inSum)}</b></div>
      </div>
    </div>
    ${!obj && DB.projects.length > 1 ? `<div class="card"><h3>🏗 По объектам</h3><div class="tbl-wrap"><table><thead><tr><th>Объект</th><th class="n">Касса</th><th class="n">Сегодня</th><th class="n">Вчера</th><th class="n">Расход: ${perName}</th><th class="n">Приход: ${perName}</th></tr></thead><tbody>
      ${byObj.map(o => `<tr><td><a href="#" data-act="cashObj" data-id="${o.p.id}"><b>${esc(o.p.name)}</b></a></td><td class="n ${o.bal < 0 ? 'bad' : ''}"><b>${money(o.bal)}</b></td><td class="n bad">${o.d ? '−' + money(o.d) : '—'}</td><td class="n">${o.y ? '−' + money(o.y) : '—'}</td><td class="n">${money(o.r.out)}</td><td class="n ok">${money(o.r.inc)}</td></tr>`).join('')}
    </tbody></table></div></div>` : ''}
    <div class="card"><h3>📒 Операции: ${perName}${cat ? ' · ' + esc(cat) : ''} <span class="tools">${cat ? '<button class="btn-s" data-act="cashCat" data-c="">Все статьи</button>' : ''}<button class="btn-s" data-act="cashCsv">⬇ Excel</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Дата</th><th>Объект</th><th>Статья</th><th>Описание</th><th>Контрагент</th><th class="n">Сумма</th></tr></thead><tbody>
      ${list.slice(0, 300).map(x => `<tr><td>${fdate(x.date)}</td><td class="small">${esc(x.pname)}</td><td>${esc(x.cat)}</td><td>${esc(x.desc)}</td><td>${esc(x.who)}</td><td class="n ${x.type === 'in' ? 'ok' : 'bad'}"><b>${x.type === 'in' ? '+' : '−'}${money(x.amount)}</b></td></tr>`).join('') || '<tr><td colspan="6" class="muted">Нет операций за этот период</td></tr>'}
      </tbody></table></div>${list.length > 300 ? '<p class="small muted">Показаны первые 300</p>' : ''}</div>`;
  }
};
ACT.cashPer = d => { pageArgs.per = d.p; delete pageArgs.cat; rerender(); };
ACT.cashCat = d => { if (d.c) pageArgs.cat = d.c; else delete pageArgs.cat; rerender(); };
ACT.cashObj = d => { pageArgs.obj = d.id; rerender(); };
ACT.cashCsv = () => {
  const [from, to] = periodRange(pageArgs.per || 'today'), rows = [];
  DB.projects.filter(p => !pageArgs.obj || p.id === pageArgs.obj).forEach(p => p.tx.filter(x => x.date >= from && x.date <= to).forEach(x => rows.push([fdate(x.date), p.name, x.type === 'in' ? 'Поступление' : 'Расход', x.cat, x.desc, x.who, x.type === 'in' ? x.amount : -x.amount])));
  rows.sort((a, b) => a[0].split('.').reverse().join('').localeCompare(b[0].split('.').reverse().join('')));
  download(`kassa_${today()}.csv`, csv([['Дата', 'Объект', 'Тип', 'Статья', 'Описание', 'Контрагент', 'Сумма'], ...rows]), 'text/csv');
};
ACT.cashAdd = () => {
  openModal(`<h2>💵 Новая операция</h2><div class="form mt">
    <label class="fld"><span>Объект</span><select id="cxObj">${opts(DB.projects.map(p => [p.id, p.name]), pageArgs.obj || P().id)}</select></label>
    <label class="fld"><span>Тип</span><select id="cxType"><option value="out">Расход</option><option value="in">Поступление</option></select></label>
    <label class="fld"><span>Статья</span><select id="cxCat">${opts(TX_OUT)}</select></label>
    <label class="fld"><span>Сумма, ${cur()}</span><input class="txt" type="number" id="cxAmt" min="0"></label>
    <label class="fld"><span>Дата</span><input class="txt" type="date" id="cxDate" value="${today()}"></label>
    <label class="fld"><span>Контрагент</span><input class="txt" id="cxWho"></label>
    <label class="fld" style="grid-column:1/-1"><span>Описание</span><input class="txt" id="cxDesc" placeholder="Что купили / за что получили"></label>
  </div><div class="flex mt"><button class="btn-p" data-act="cashSave">Провести</button><button data-act="closeModal">Отмена</button></div>`);
  $('#cxType').onchange = e => { $('#cxCat').innerHTML = opts(e.target.value === 'in' ? TX_IN : TX_OUT); };
  setTimeout(() => $('#cxAmt').focus(), 50);
};
ACT.cashSave = () => {
  const amount = num($('#cxAmt').value); if (amount <= 0) return toast('Введите сумму');
  const p = DB.projects.find(x => x.id === $('#cxObj').value) || P();
  addTx(p, { date: $('#cxDate').value || today(), type: $('#cxType').value, cat: $('#cxCat').value, amount, who: $('#cxWho').value, desc: $('#cxDesc').value });
  save(); closeModal(); rerender(); toast('Операция проведена');
};

/* ---------- Плашка тарифа / пробного периода ---------- */
function planNotice() {
  if (!SAAS || !SAAS.plan) return '';
  const pl = SAAS.plan;
  if (pl.state === 'trial' && pl.daysLeft != null && pl.daysLeft <= 7) return `<div class="plan-note">⏳ ${T('Пробный период')}: ${T('осталось')} <b>${pl.daysLeft}</b> ${T('дн.')} ${SAAS.isAdmin ? `<a href="/company/billing">${T('Выбрать тариф')} →</a>` : ''}</div>`;
  return '';
}

/* ---------- Настоящие 3D-модели объекта ----------
   Здесь, в программе, «Объект в 3D» и «Дизайн квартиры» — схемы, которые программа рисует сама по параметрам здания.
   Модели архитектора и дизайнера загружаются в панели: «Объекты и 3D» → объект → «3D, панорамы, планировки». */
function real3dBar(p, kind) {
  if (!SAAS) return '';
  const m = (SAAS.media && SAAS.media[p.id]) || {}, inner = kind === 'interior', n = inner ? (m.interior || 0) + (m.pano || 0) : (m.exterior || 0), id = encodeURIComponent(p.id);
  const hint = inner ? 'Здесь — примерная квартира, нарисованная программой. Загрузите интерьер от дизайнера (GLB, FBX, OBJ, SketchUp, 3ds Max…) и панорамы 360° каждой комнаты.'
    : 'Здесь — схема, нарисованная программой по параметрам здания. Загрузите модель архитектора (GLB, FBX, OBJ, SketchUp, Revit или ArchiCAD IFC…) — покупатели увидят настоящий дом.';
  return `<div class="card real3d"><div class="real3d-t"><b>📦 ${inner ? 'Настоящий 3D-интерьер и панорамы комнат' : 'Настоящая 3D-модель здания'}</b>
    <span class="small muted">${n ? `${inner ? 'Загружено файлов интерьера' : 'Загружено моделей'}: <b>${n}</b>. <span>Покупатели видят их в кабинете и на сайте.</span>` : hint}</span></div>
    <div class="flex">${n ? `<a class="btn btn-p" href="/company/objects/${id}/3d" target="_blank">Открыть 3D-модель</a>` : ''}
    ${SAAS.canMedia ? `<a class="btn${n ? '' : ' btn-p'}" href="/company/objects/${id}?tab=media&kind=${inner ? 'interior' : 'exterior'}#upload-card">⬆ Загрузить 3D-модель</a>` : ''}</div></div>`;
}
/* ---------- загруженная модель здания вместо схемы программы: «Объект в 3D» и «Квартира для клиента» ----------
   Модель открывается страницей /company/objects/{id}/locate (assets/locate3d.js): квартира подсвечена на стене,
   подъезд обведён. Если модель загружена — показываем только её: схема программы нужна, лишь пока модели нет. */
const hasReal3d = p => !!(SAAS && SAAS.media && SAAS.media[p.id] && SAAS.media[p.id].exterior);
function real3dFrame(p, aptId, cls) {
  return `<div class="real3d-frame ${cls || ''}"><iframe src="/company/objects/${encodeURIComponent(p.id)}/locate?embed=1${aptId ? '&u=' + encodeURIComponent(aptId) : ''}" title="3D" allow="fullscreen"></iframe></div>`;
}
// «Объект в 3D»: нажали квартиру на фасаде модели — открываем её карточку (цена, бронь, оплата)
window.addEventListener('message', e => {
  if (e.origin !== location.origin || !e.data || e.data.type !== 'oftob-locate-pick' || curPage !== 'site' || !e.data.unit) return;
  if (genApts(P()).some(a => a.id === e.data.unit)) go('apt', { id: e.data.unit });
});
if (PAGES.site) {
  const r = PAGES.site.render, m = PAGES.site.mounted;
  PAGES.site.render = function (p, a) {
    return real3dBar(p, 'exterior') + (hasReal3d(p) ? real3dFrame(p, null, 'big') : r.call(this, p, a));
  };
  PAGES.site.mounted = function (p, a) { if (!hasReal3d(p) && m) m.call(this, p, a); };
}
/* ---------- загруженные интерьеры квартиры: 3D, панорамы 360°, рендеры и план ----------
   Файлы привязаны к планировке (или к числу комнат). Правило то же, что у просмотра на сервере (ObjectMedia::forUnit):
   свои файлы планировки, иначе — общие для такого числа комнат, иначе — общие для всех. Загружено — показываем их, а не примеры программы. */
// свой файл квартиры (загружен к ней или к такой же) — важнее файла планировки того же вида; файлы отдельных квартир другим не видны
const innerPick = (files, apt, kinds) => {
  const list = files.filter(m => kinds.includes(m.k)), mine = list.filter(m => (m.u || []).map(String).includes(String(apt.id)));
  if (mine.length) return mine;
  const shared = list.filter(m => !(m.u || []).length), own = apt.type ? shared.filter(m => m.l === apt.type) : [];
  return own.length ? own : shared.filter(m => !m.l && (m.r == null || +m.r === +apt.rooms));
};
function innerKinds(p, apt) {
  const files = (SAAS && SAAS.media && SAAS.media[p.id] && SAAS.media[p.id].files) || [], view = apt ? innerPick(files, apt, ['interior', 'pano']) : [];
  return { view, panos: view.filter(m => m.k === 'pano'), photos: apt ? innerPick(files, apt, ['photo']) : [], plans: apt ? innerPick(files, apt, ['plan']) : [] };
}
const mediaUrl = m => `/company/media/${m.id}/file`;
const innerFrame = (p, apt, mid) => `<div class="real3d-frame big"><iframe src="/company/objects/${encodeURIComponent(p.id)}/3d?embed=1&u=${encodeURIComponent(apt.id)}${mid ? '&m=' + mid : ''}" title="3D" allow="fullscreen; xr-spatial-tracking"></iframe></div>`;
const innerUpBtn = (apt, primary, label) => SAAS && SAAS.canMedia ? `<button class="btn btn-s${primary ? ' btn-p' : ''}" data-act="innerManage" data-apt="${apt ? apt.id : ''}">⬆ ${label || 'Загрузить / изменить интерьер'}</button>` : '';
// плашка над вкладкой: что показано и кнопка загрузки
const innerBar = (title, text, btns) => `<div class="card real3d"><div class="real3d-t"><b>${title}</b><span class="small muted">${text}</span></div><div class="flex">${btns}</div></div>`;
let innerPhotos = [];
/** Рендеры и фото квартиры, загруженные дизайнером; панорамы 360° — кнопками */
const roomName = m => String(m.room || (m.k === 'pano' ? m.n : '') || '').trim();
function realGallery(p, apt, f) {
  // по комнатам: сначала с названием комнаты (по алфавиту не сортируем — порядок загрузки), без комнаты — в конце
  const rooms = [...new Set(f.photos.map(roomName))].sort((a, b) => (a ? 0 : 1) - (b ? 0 : 1));
  innerPhotos = rooms.flatMap(r => f.photos.filter(m => roomName(m) === r));
  const main = innerPhotos[0];
  return `<div class="card ph-card"><h3>📸 Рендеры и фото квартиры <span class="tools">${f.panos.length ? `<button class="btn-p btn-s" data-act="innerView" data-apt="${apt.id}" data-m="${f.panos[0].id}">🌐 Панорамы 360° <span class="notr">(${f.panos.length})</span></button>` : ''}${innerUpBtn(apt, false, 'Загрузить / изменить')}</span></h3>
    ${main ? `<div class="ph-wrap"><button class="ph-main" data-act="innerPhoto" data-i="0"><img src="${mediaUrl(main)}" alt="" loading="lazy"><span class="ph-tag notr">${esc(roomName(main) || main.n || '')}</span><span class="ph-zoom">🔍</span></button>
      <div class="ph-thumbs">${innerPhotos.map((m, i) => `<button class="ph-th${i ? '' : ' on'}" data-act="innerPhoto" data-i="${i}"><img src="${mediaUrl(m)}" alt="" loading="lazy"><span class="notr"><b>${esc(roomName(m) || m.n || '')}</b></span></button>`).join('')}</div></div>`
      : `<div class="pano-list">${f.panos.map(m => `<button class="btn" data-act="innerView" data-apt="${apt.id}" data-m="${m.id}">🌐 <span class="notr">${esc(m.room || m.n || '')}</span></button>`).join('')}</div>`}
  </div>`;
}
ACT.innerPhoto = d => {
  const i = +d.i, n = innerPhotos.length, m = innerPhotos[i]; if (!m) return;
  openModal(`<div class="ph-big"><img src="${mediaUrl(m)}" alt=""><div class="flex mt"><b class="notr">${esc(roomName(m) ? roomName(m) + ' · ' + (m.n || '') : m.n || '')}</b><span class="right"></span>
    ${n > 1 ? `<button data-act="innerPhoto" data-i="${(i - 1 + n) % n}">←</button><button data-act="innerPhoto" data-i="${(i + 1) % n}">→</button>` : ''}
    <a class="btn btn-p" href="${mediaUrl(m)}" target="_blank" rel="noopener">⬇ Открыть в полном размере</a></div></div>`);
  $('.modal-box').classList.add('wide');
};
/** Окно поверх программы: просмотр 3D / 360° или страница «Интерьеры» */
let innerDirty = false;
function innerOverlay(src, title) {
  innerDirty = false;
  const ov = document.createElement('div'); ov.className = 'inner-ov';
  ov.innerHTML = `<div class="inner-ov-in"><button type="button" class="inner-ov-x" aria-label="Закрыть">✕</button><iframe src="${esc(src)}" title="${esc(title)}" allow="fullscreen; xr-spatial-tracking"></iframe></div>`;
  const close = () => { ov.remove(); document.removeEventListener('keydown', onKey); if (innerDirty && ['design', 'apt'].includes(curPage)) rerender(); };
  const onKey = e => { if (e.key === 'Escape') close(); };
  ov.querySelector('.inner-ov-x').onclick = close;
  ov.addEventListener('click', e => { if (e.target === ov) close(); });
  document.addEventListener('keydown', onKey);
  document.body.append(ov);
}
ACT.innerView = d => innerOverlay(`/company/objects/${encodeURIComponent(P().id)}/3d?embed=1&u=${encodeURIComponent(d.apt)}${d.m ? '&m=' + d.m : ''}`, '3D');
// «Интерьеры»: по планировкам загрузить 3D, 360°, рендеры и план; ошибочный файл переименовать, перенести, удалить
ACT.innerManage = d => {
  const p = P(), a = pageArgs || {}, apt = (d && d.apt) || a.apt || (curPage === 'apt' ? a.id : '');
  innerOverlay(`/company/objects/${encodeURIComponent(p.id)}/interiors?embed=1&${apt ? 'u=' + encodeURIComponent(apt) : 'r=' + (+p.design.rooms || 0)}`, 'Интерьеры');
};
window.addEventListener('message', e => {
  if (e.origin !== location.origin || !e.data || e.data.type !== 'oftob-inner-changed' || !SAAS) return;
  const m = (SAAS.media = SAAS.media || {})[e.data.pid] = SAAS.media[e.data.pid] || {}, n = e.data.n || {};
  m.in = e.data.in || []; m.interior = n.interior || 0; m.pano = n.pano || 0;
  if (Array.isArray(e.data.files)) m.files = e.data.files;
  innerDirty = true;
});
// после вкладок квартиры — сюда встаёт плашка «что показано»
const afterTabs = (html, extra) => { const k = '▦ Шахматка</button></div>', i = html.indexOf(k); return i < 0 ? extra + html : html.slice(0, i + k.length) + extra + html.slice(i + k.length); };

if (PAGES.apt) {
  const r = PAGES.apt.render, m = PAGES.apt.mounted;
  // вкладки «В доме» и «Как пройти»: вместо нарисованного дома — загруженная модель с подсвеченной квартирой и подъездом;
  // шаги «как пройти» и схема участка остаются
  const real = (p, a) => !!(a && a.id && ['house', 'route'].includes(a.tab || 'house') && hasReal3d(p));
  const aptOf = (p, a) => a && a.id ? genApts(p).find(x => x.id === a.id) : null;
  // вкладка «3D-интерьер»: загружен 3D-интерьер или панорамы этой квартиры — показываем их, а не нарисованный
  const ownInner = (p, a) => { const apt = aptOf(p, a); return !!(SAAS && apt && a.tab === 'interior' && innerKinds(p, apt).view.length); };
  PAGES.apt.render = function (p, a) {
    let html = r.call(this, p, a);
    const apt = aptOf(p, a);
    if (SAAS && apt && apt.rooms) {
      const f = innerKinds(p, apt);
      if (a.tab === 'interior') {
        if (f.view.length) {
          const k = html.indexOf('data-act="aptStyle"'), s = html.lastIndexOf('<div class="flex mb">', k), v = html.indexOf('<div class="v-move">', k), e = v < 0 ? -1 : html.indexOf('</div></div>', v);
          if (k >= 0 && s >= 0 && e >= 0) html = html.slice(0, s) + innerFrame(p, apt) + html.slice(e + 12);
          if (SAAS.canMedia) html = afterTabs(html, `<div class="flex mb inner-tools"><span class="small muted">Загруженный интерьер этой планировки — его видят покупатели.</span><span class="right"></span>${innerUpBtn(apt)}</div>`);
        } else {
          html = afterTabs(html, innerBar('🛋 3D-интерьер этой квартиры ещё не загружен', `<span>Ниже — примерный интерьер, нарисованный программой.</span> ${f.photos.length ? '<span>Рендеры этой планировки — во вкладке «Фото».</span> ' : ''}<span>Загрузите 3D-модель от дизайнера (GLB, FBX, OBJ, SketchUp, 3ds Max…) или панорамы 360° комнат.</span>`, innerUpBtn(apt, true, 'Загрузить 3D или 360°')));
        }
      }
      if (a.tab === 'photo') {
        const stock = photoGallery(apt.o.style || 'modern');
        if (f.photos.length || f.panos.length) html = html.replace(stock, realGallery(p, apt, f));
        else html = afterTabs(html, innerBar('📸 Своих рендеров этой планировки ещё нет', 'Ниже — примеры дизайна, которые показывает программа. Загрузите рендеры и фото от дизайнера (JPG, PNG) — покупатели увидят их вместо примеров.', innerUpBtn(apt, true, 'Загрузить рендеры')));
      }
      if (a.tab === 'plan') {
        const pl = f.plans[0];
        html = afterTabs(html, pl ? `<div class="card real-plan"><h3>📐 Планировка квартиры от архитектора <span class="tools">${f.plans.length > 1 ? f.plans.map((x, i) => `<a class="btn btn-s" href="${mediaUrl(x)}" target="_blank" rel="noopener">${i + 1}</a>`).join('') : ''}<a class="btn btn-s" href="${mediaUrl(pl)}" target="_blank" rel="noopener">⬇ Открыть</a>${innerUpBtn(apt, false, 'Изменить')}</span></h3>
          ${pl.ext === 'pdf' ? `<iframe src="${mediaUrl(pl)}" title="PDF"></iframe>` : `<img src="${mediaUrl(pl)}" alt="" loading="lazy">`}</div>`
          : SAAS.canMedia ? innerBar('📐 Чертёж планировки не загружен', 'Ниже — схема, которую рисует программа. Загрузите план квартиры от архитектора (PDF или картинка).', innerUpBtn(apt, true, 'Загрузить план')) : '');
      }
    }
    if (!real(p, a)) return html;
    return html.replace(/<div class="site-viewer" id="aptSite"><div class="v-tools" id="s3Tools"><\/div>(<div class="v-help">[^<]*<\/div>)?<\/div>/, real3dFrame(p, a.id))
      .replace('<div class="flex mt"><button class="btn-p" data-act="aptWin">🪟 Вид из окна</button><button data-act="aptFocus">🎯 Показать квартиру</button></div>', '');
  };
  PAGES.apt.mounted = function (p, a) {
    if (real(p, a) || ownInner(p, a)) return;
    if (m) m.call(this, p, a);
  };
}
/* ---------- «Дизайн квартиры 3D»: настоящий интерьер выбранной квартиры (или планировки по числу комнат) ----------
   Есть загруженный 3D-интерьер или 360° — показываем сверху; есть рендеры — они вместо примеров программы;
   ниже остаётся примерный дизайн программы (стили, ремонт «под ключ»). */
function designInner(p, a) {
  if (!SAAS) return { html: '', photos: false };
  const A = genApts(p).filter(x => x.rooms > 0), apt = a.apt ? A.find(x => x.id === a.apt) : null, rooms = apt ? apt.rooms : p.design.rooms;
  // квартира не выбрана: квартира с таким числом комнат, у которой что-то загружено (иначе — первая такая)
  const has = x => { const f = innerKinds(p, x); return f.view.length || f.photos.length; };
  const rep = apt || A.find(x => +x.rooms === +rooms && has(x)) || A.find(x => +x.rooms === +rooms);
  const f = innerKinds(p, rep), any = f.view.length || f.photos.length;
  const what = apt ? `№${apt.num} · ${apt.rooms}-комн.${apt.typeName ? ' · ' + esc(apt.typeName) : ''}` : `${rooms}-комн.`;
  const html = `<div class="card real3d"><div class="real3d-t"><b>📦 Настоящий интерьер квартиры</b><span class="real3d-for">${what}</span>
    <span class="small muted">${any ? 'Загружен интерьер этой планировки — смотрите ниже. Его видят покупатели в кабинете и на сайте. Под ним — примерный дизайн программы.'
      : 'Здесь — примерная квартира, нарисованная программой. Загрузите интерьер от дизайнера (GLB, FBX, OBJ, SketchUp, 3ds Max…) и панорамы 360° каждой комнаты.'}</span></div>
    <div class="flex">${innerUpBtn(apt || rep, !any)}</div></div>
    ${f.view.length ? innerFrame(p, rep) : ''}${f.photos.length ? realGallery(p, rep, f) : ''}`;
  return { html, photos: f.photos.length > 0 };
}
if (PAGES.design) {
  const r = PAGES.design.render;
  PAGES.design.render = function (p, a) {
    let html = r.call(this, p, a);
    const d = designInner(p, a);
    if (d.photos) {   // свои рендеры уже показаны сверху — примеры программы убираем
      const A = genApts(p).filter(x => x.rooms > 0), apt = a.apt ? A.find(x => x.id === a.apt) : null;
      html = html.replace(photoGallery((apt && apt.o.style) || p.design.style), '');
    }
    return d.html + html;
  };
}
