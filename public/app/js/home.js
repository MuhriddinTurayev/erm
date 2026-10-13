'use strict';
/* ===== Главная страница Oftob_Sohtumon ===== */
const APP_NAME = 'Oftob_Sohtumon';

const TILES = [
  ['objects', '🏢', 'Все объекты', 'Все стройки компании: касса, расходы, готовность, продажи и просрочки по каждому объекту', ['#0f172a', '#f59e0b']],
  ['cash', '💵', 'Касса компании', 'Сколько денег на счету, сколько ушло сегодня и вчера, на что и на каком объекте', ['#ea580c', '#f59e0b']],
  ['sell', '🏬', 'Продажи и дизайн', 'Лиды, шахматка и продажи квартир, презентация для клиента, 3D-дизайн и тур 360°', ['#16a34a', '#db2777']],
  ['leads', '📞', 'Лиды', 'Заявки покупателей: воронка продаж, звонки, встречи, подбор квартиры и задаток', ['#0ea5e9', '#6366f1']],
  ['ai', '🤖', 'ИИ-консультант', 'Проверка работ и работников, поиск ошибок и перерасходов, ответы на любые вопросы по стройке', ['#4f46e5', '#06b6d4']],
  ['build', '🏗️', 'Проект и площадка', 'Проект здания, квартирография, фасад и 3D комплекса, подготовка площадки до начала стройки', ['#3b82f6', '#f59e0b']],
  ['ground', GEO_ICON, 'Геология и котлован', 'Слои грунта, несущая способность, грунтовые воды, объём котлована, экскаваторы и самосвалы', ['#92400e', '#d97706']],
  ['attend', '📍', 'Приход и GPS', 'Сотрудник отмечает «Пришёл / Ушёл» на телефоне: таймер смены, GPS — на объекте или нет, карта и табель', ['#7c3aed', '#16a34a']],
  ['finance', '💼', 'Финансы', 'Смета и прибыль, бухгалтерия, техника и топливо, материалы, сотрудники и табель, бригады — всё о деньгах в одном месте', ['#047857', '#0ea5e9']],
  ['hiring', '🤝', 'Найм сотрудников', 'Вакансии, кандидаты, собеседования, приём на работу, объявления для Telegram и OLX', ['#0d9488', '#22c55e']],
  ['safety', '🦺', 'Техника безопасности', 'Инструктажи, удостоверения, спецодежда, наряды-допуски, происшествия, ежедневный осмотр', ['#ca8a04', '#ef4444']],
  ['schedule', '📅', 'График работ', 'Этапы от котлована до сдачи дома, диаграмма Ганта, просрочки', ['#7c3aed', '#a855f7']],
  ['legal', '⚖️', 'Юрист', 'Договоры, споры и претензии, сроки, шаблоны документов, ИИ-юрист', ['#1e3a8a', '#6366f1']],
  ['dashboard', '📊', 'Панель управления', 'Сводка по проекту: деньги, сроки, продажи и готовность', ['#f59e0b', '#ef4444']],
  ['settings', '⚙️', 'Настройки', 'Валюта, цена топлива, проекты, резервная копия данных', ['#334155', '#94a3b8']],
];
function tileInfo(k, p) {
  const B = bld(p);
  switch (k) {
    case 'objects': return `${DB.projects.length} объект(ов) · касса ${moneyS(sum(DB.projects, x => finance(x).bal))}`;
    case 'cash': { const t = sum(DB.projects, x => moneyOn(x, today()).out), y = sum(DB.projects, x => moneyOn(x, yesterday()).out); return `Сегодня −${moneyS(t)} · вчера −${moneyS(y)}`; }
    case 'finance': { const F = finance(p); return `Касса ${moneyS(F.bal)} · ${FIN_SUB.length} разделов`; }
    case 'attend': { const C = gpsCalc(p); return `На смене ${C.open.length} · ${C.off.length ? '⚠️ не на объекте ' + C.off.length : 'все на объекте'}`; }
    case 'ground': { const G = geoCalc(p), c = pitCalc(p); return `R ${fmt(G.util)}% · ${fmt(c.V)} м³ грунта`; }
    case 'sell': { const A = genApts(p); return `Продано ${A.filter(a => a.status === 'sold').length} из ${A.length} · 360°`; }
    case 'leads': { const c = leadsCalc(p); return `Активных ${c.active.length} · задач ${c.due.length}`; }
    case 'ai': { const a = auditCalc(p); return `Оценка ${a.score}/100 · замечаний ${a.bad + a.warn}`; }
    case 'dashboard': return `Готовность ${fmt(progress(p), 1)}%`;
    case 'build': { const B = bld(p), C = prepCalc(p); return `${B.apts} кв. · подготовка ${C.done}/${C.count}`; }
    case 'project': return `${B.apts} квартир · ${fmt(B.totalAbove)} м²`;
    case 'geology': { const G = geoCalc(p); return `${G.wells} скважин · ${G.util > 100 ? 'нужны сваи' : 'R ' + fmt(G.util) + '%'}`; }
    case 'prep': { const C = prepCalc(p); return `${moneyS(C.total)} · ${C.ready ? 'готово к старту' : 'готово ' + C.critDone.length + '/' + C.crit.length}`; }
    case 'pit': { const c = pitCalc(p); return `${fmt(c.V)} м³ · ${fmt(c.trips)} рейсов`; }
    case 'materials': return moneyS(matCalc(p).total);
    case 'labour': { const L = labCalc(p); return `${fmt(L.workersTotal)} рабочих · ${L.itrCount} ИТР`; }
    case 'staff': return `${p.staff.filter(s => s.active !== false).length} сотрудников`;
    case 'hiring': { const n = sum(vacancies(p), v => v.n), c = p.cands.filter(x => ['new', 'interview', 'trial'].includes(x.status)).length; return `Вакансий ${n} · кандидатов ${c}`; }
    case 'safety': { const c = safetyCalc(p); return `Без травм ${c.daysSafe} дн. · СИЗ ${fmt(c.ppePct)}%`; }
    case 'legal': { const c = legalCalc(p); return `Дел ${c.open.length} · сроков на неделе ${c.soon.length}`; }
    case 'fleet': return `${p.fleet.length} машин в автопарке`;
    case 'schedule': return `${p.stages.length} этапов · ${fmt(projSpan(p).days / 30.4, 1)} мес.`;
    case 'accounting': return `Касса ${moneyS(finance(p).bal)}`;
    case 'smeta': return `${moneyS(smetaCalc(p).perM2)} за м²`;
    case 'sales': { const A = genApts(p); return `Продано ${A.filter(a => a.status === 'sold').length} из ${A.length}`; }
    case 'design': return '6 стилей интерьера';
    case 'settings': return `${DB.projects.length} проект(ов)`;
  }
  return '';
}
/* полоса «деньги компании сегодня» на главной */
function homeMoneyStrip() {
  const t = today(), y = yesterday(), P_ = DB.projects;
  const bal = sum(P_, x => finance(x).bal), dT = sum(P_, x => moneyOn(x, t).out), dY = sum(P_, x => moneyOn(x, y).out), iT = sum(P_, x => moneyOn(x, t).inc);
  const cats = {}; P_.forEach(x => x.tx.forEach(tx => { if (tx.type === 'out' && tx.date === t) cats[tx.cat] = (cats[tx.cat] || 0) + tx.amount; }));
  const top = Object.entries(cats).sort((a, b) => b[1] - a[1])[0];
  return `<section class="money-strip" data-act="go" data-page="cash">
    <div class="ms-main"><span>💰 Деньги компании на счету</span><b>${moneyS(bal)}</b><small>${P_.length} объект(ов)</small></div>
    <div><span>Потрачено сегодня</span><b class="bad">${dT ? '−' : ''}${moneyS(dT)}</b><small>${dY ? deltaHtml(dT, dY) + 'к вчера' : '&nbsp;'}</small></div>
    <div><span>Потрачено вчера</span><b>${dY ? '−' : ''}${moneyS(dY)}</b><small>${fdate(y)}</small></div>
    <div><span>Поступило сегодня</span><b class="ok">${iT ? '+' : ''}${moneyS(iT)}</b><small>&nbsp;</small></div>
    <div><span>Больше всего ушло на</span><b>${top ? esc(top[0]) : '—'}</b><small>${top ? moneyS(top[1]) : 'расходов нет'}</small></div>
    <em>Касса компании →</em>
  </section>`;
}
function geoBrief(p) { const G = geoCalc(p); return G.util > 100 || G.settle > p.geo.sLimit ? '❌ нужны сваи' : G.notes.some(n => n[0] === 'warn') ? `⚠️ R ${fmt(G.util)}%` : `✅ R ${fmt(G.util)}%`; }
function startSteps(p) {
  const G = geoCalc(p), C = prepCalc(p), pit = pitCalc(p), geoDone = (p.prep.geology || {}).status === 'done';
  const pitSt = p.stages.find(s => /котлован/i.test(s.name)), pitPr = pitSt ? pitSt.progress : 0;
  const gw = G.notes.filter(n => n[0] !== 'ok').length;
  const steps = [
    ['geology', GEO_ICON, '1. Геология грунта', `${G.wells} скважин по ${fmt(G.wellDepth, 1)} м · основание: ${esc(G.base ? G.base.P.name : '—')} · R ${fmt(G.util)}% · осадка ${fmt(G.settle, 1)} см${gw ? ` · замечаний: ${gw}` : ''}`, geoDone ? 'done' : 'todo', geoDone ? 'изыскания выполнены' : 'нужно провести изыскания'],
    ['prep', '🚧', '2. Подготовка площадки', `${C.done} из ${C.count} работ выполнено · ${moneyS(C.total)}`, C.ready ? 'done' : C.done ? 'work' : 'todo', C.ready ? 'можно начинать котлован' : `обязательных: ${C.critDone.length}/${C.crit.length}`],
    ['pit', '⛏️', '3. Котлован', `${fmt(pit.V)} м³ грунта · ${fmt(pit.trips)} рейсов · ${pit.trucks} самосвалов · ${pit.days} дн.`, pitPr >= 100 ? 'done' : pitPr > 0 ? 'work' : 'todo', `выполнено ${pitPr}%`],
  ];
  return steps.map(([pg, ic, t, d, st, s2]) => `<button class="step st-${st}" data-act="go" data-page="${pg}"><span class="step-ic">${ic}</span><span class="step-b"><b>${t}</b><span>${d}</span><em>${st === 'done' ? '✅' : st === 'work' ? '🔄' : '⏳'} ${s2}</em></span></button>`).join('<span class="step-arr">→</span>');
}PAGES.home = {
  title: 'Главная',
  render(p) {
    const S = smetaCalc(p), A = genApts(p), sold = A.filter(a => a.status === 'sold').length, pr = progress(p);
    return `
    <section class="hero">
      <div class="hero-sun"></div>
      <div class="hero-in">
        <div class="hero-logo">☀️</div>
        <div>
          <h1>${esc(DB.settings.company || APP_NAME)}</h1>
          <p>Управление строительством многоэтажных домов — от котлована до продажи готовых квартир</p>
          <div class="hero-proj">Текущий объект: <b>${esc(p.name)}</b>${p.address ? ' · ' + esc(p.address) : ''} · ${esc(p.status)}${DB.projects.length > 1 ? ` · <a href="#objects" data-act="go" data-page="objects">все объекты (${DB.projects.length}) →</a>` : ''}</div>
        </div>
      </div>
      <div class="hero-stats">
        <div><span>Готовность</span><b>${fmt(pr, 1)}%</b>${progBar(pr, '#fff')}</div>
        <div><span>Себестоимость</span><b>${moneyS(S.total)}</b></div>
        <div><span>Выручка (план)</span><b>${moneyS(S.revenue)}</b></div>
        <div><span>Продано квартир</span><b>${sold} из ${A.length}</b></div>
        <div><span>Касса</span><b>${moneyS(S.F.bal)}</b></div>
        <div class="hs-geo" data-act="go" data-page="geology"><span>Геология (основание)</span><b>${geoBrief(p)}</b></div>
      </div>
    </section>
    ${seesMoney() ? homeMoneyStrip() : ''}
    <section class="start-steps">
      <h2 class="home-h">🏁 Начало строительства — обязательные шаги</h2>
      <div class="steps">${startSteps(p)}</div>
    </section>
    ${aiHomeHtml(p)}    <h2 class="home-h">Разделы</h2>
    <div class="tiles">
      ${TILES.filter(([k]) => canPage(k)).map(([k, ic, t, d, [c1, c2]]) => `
      <button class="tile" data-act="go" data-page="${k}" style="--c1:${c1};--c2:${c2}">
        <span class="tile-ic">${ic}</span>
        <span class="tile-t">${t}</span>
        <span class="tile-d">${d}</span>
        <span class="tile-m">${tileInfo(k, p)}</span>
        <span class="tile-go">Открыть →</span>
      </button>`).join('')}
    </div>`;
  },
  mounted() { const m = $('#aiMsgs'); if (m) m.scrollTop = m.scrollHeight; }
};
