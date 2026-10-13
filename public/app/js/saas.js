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
const isDemoProj = p => !!p.demo || /демо|demo/i.test(p.name || '');
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
if (ACT.projDel) { const pd = ACT.projDel; ACT.projDel = d => { if (SAAS && SAAS.restricted) return toast('Объекты удаляет руководитель компании'); pd(d); }; }

/* ---------- Расчёты по деньгам ---------- */
function moneyOn(p, date) { let out = 0, inc = 0; p.tx.forEach(t => { if (t.date === date) { if (t.type === 'in') inc += t.amount; else out += t.amount; } }); return { out, inc }; }
function moneyRange(p, from, to) { let out = 0, inc = 0; p.tx.forEach(t => { if (t.date >= from && t.date <= to) { if (t.type === 'in') inc += t.amount; else out += t.amount; } }); return { out, inc }; }
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
PAGES.cash = {
  title: 'Касса компании',
  render(_, a) {
    const per = a.per || 'today', [from, to] = periodRange(per), obj = a.obj || '', cat = a.cat || '';
    const projs = DB.projects.filter(p => !obj || p.id === obj);
    const t = today(), y = yesterday();
    const all = []; projs.forEach(p => p.tx.forEach(x => all.push({ ...x, pid: p.id, pname: p.name })));
    const bal = sum(projs, p => finance(p).bal), dT = sum(projs, p => moneyOn(p, t).out), dY = sum(projs, p => moneyOn(p, y).out), iT = sum(projs, p => moneyOn(p, t).inc);
    const wk = sum(projs, p => moneyRange(p, addDays(t, -6), t).out), mo = sum(projs, p => moneyRange(p, monthNow() + '-01', t).out);
    const inPer = all.filter(x => x.date >= from && x.date <= to);
    const outPer = inPer.filter(x => x.type === 'out'), outSum = sum(outPer, x => x.amount), inSum = sum(inPer.filter(x => x.type === 'in'), x => x.amount);
    const cats = {}; outPer.forEach(x => cats[x.cat] = (cats[x.cat] || 0) + x.amount);
    const catRows = Object.entries(cats).sort((a, b) => b[1] - a[1]);
    const days = []; for (let i = 29; i >= 0; i--) { const d = addDays(t, -i); days.push([d, sum(all.filter(x => x.type === 'out' && x.date === d), x => x.amount)]); }
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
      ${kpi('Деньги на счету (касса)', moneyS(bal), obj ? esc((DB.projects.find(p => p.id === obj) || {}).name || '') : 'по всем объектам', bal >= 0 ? 'ok' : 'bad')}
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
