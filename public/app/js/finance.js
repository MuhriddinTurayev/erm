'use strict';
/* ===== Финансы: общий раздел для бухгалтерии, техники, материалов, сотрудников и бригад ===== */
const FIN_SUB = [
  ['smeta', '📑', 'Смета и прибыль', 'Себестоимость за 1 м², план и факт по статьям, прибыль и рентабельность', ['#0f766e', '#14b8a6']],
  ['accounting', '💰', 'Бухгалтерия', 'Приходы и расходы, касса, выгрузка в Excel', ['#059669', '#10b981']],
  ['fleet', '🚛', 'Техника и топливо', 'Автопарк, заправки, расход топлива по норме и факту', ['#475569', '#64748b']],
  ['materials', '🧱', 'Материалы', 'Бетон, арматура, доска, брус, кладка, окна, сети — объёмы и цены', ['#dc2626', '#f97316']],
  ['staff', '🪪', 'Сотрудники и табель', 'Табель часов, начисления, выплаты зарплаты', ['#0891b2', '#22d3ee']],
  ['attend', '📍', 'Приход и GPS', 'Сотрудник нажимает «Пришёл» на телефоне — таймер смены и GPS: на объекте или нет, карта, табель сам', ['#7c3aed', '#16a34a']],
  ['labour', '👷', 'Бригады', 'Сколько нужно рабочих каждой профессии и фонд оплаты труда', ['#ea580c', '#facc15']],
];
const FIN_SET = new Set(FIN_SUB.map(x => x[0]));
function monthsBack(n) { const out = [], d = new Date(today() + 'T00:00:00'); d.setDate(1); for (let i = 0; i < n; i++) { out.unshift(isoDate(d).slice(0, 7)); d.setMonth(d.getMonth() - 1); } return out; }
function finInfo(k, p) {
  const m = monthNow();
  switch (k) {
    case 'smeta': { const S = smetaCalc(p); return [`${moneyS(S.perM2)} за м²`, `прибыль ${moneyS(S.profit)} · ${fmt(S.margin, 1)}%`]; }
    case 'accounting': { const F = finance(p); return [`Касса ${moneyS(F.bal)}`, `операций: ${p.tx.length}`]; }
    case 'fleet': { const fl = p.fuel.filter(f => f.date.startsWith(m)); return [`Топливо за месяц ${moneyS(sum(fl, f => f.liters * f.price))}`, `${p.fleet.length} машин · ${fmt(sum(fl, f => f.liters))} л`]; }
    case 'materials': { const M = matCalc(p), fact = finance(p).byCat['Материалы'] || 0; return [`Смета ${moneyS(M.total)}`, `куплено ${moneyS(fact)} (${fmt(M.total ? fact / M.total * 100 : 0)}%)`]; }
    case 'staff': { const act = p.staff.filter(s => s.active !== false && !s.isNew), e = sum(act, s => earned(p, s, m).sum), pd = sum(act, s => paidFor(p, s.id, m)); return [`Начислено за месяц ${moneyS(e)}`, `${act.length} чел. · к выплате ${moneyS(e - pd)}`]; }
    case 'attend': { const C = gpsCalc(p); return [`На смене ${C.open.length} · не на объекте ${C.off.length}`, `сегодня ${fmt(C.todayMs / 3600e3, 1)} ч · не отметились ${C.absent.length}`]; }
    case 'labour': { const L = labCalc(p); return [`ФОТ по смете ${moneyS(L.workCost + L.itrCost)}`, `нужно ${fmt(L.workersTotal)} рабочих + ${L.itrCount} ИТР`]; }
  }
  return ['', ''];
}
function finSubnav(cur) {
  return `<div class="fin-subnav"><button class="fin-home" data-act="go" data-page="finance">💼 Финансы</button>${FIN_SUB.map(([k, ic, t]) => `<button class="${k === cur ? 'on' : ''}" data-act="go" data-page="${k}">${ic} ${t}</button>`).join('')}</div>`;
}
PAGES.finance = {
  title: 'Финансы',
  render(p) {
    const F = finance(p), S = smetaCalc(p), m = monthNow(), months = monthsBack(6);
    const inM = sum(p.tx.filter(t => t.type === 'in' && t.date.startsWith(m)), t => t.amount), outM = sum(p.tx.filter(t => t.type === 'out' && t.date.startsWith(m)), t => t.amount);
    const flow = months.map(mm => ({ mm, inn: sum(p.tx.filter(t => t.type === 'in' && t.date.startsWith(mm)), t => t.amount), out: sum(p.tx.filter(t => t.type === 'out' && t.date.startsWith(mm)), t => t.amount) }));
    const mx = Math.max(1, ...flow.map(f => Math.max(f.inn, f.out)));
    return `
    <div class="kpis">
      ${kpi('Касса (баланс)', moneyS(F.bal), 'поступило ' + moneyS(F.inc) + ' · потрачено ' + moneyS(F.out), F.bal >= 0 ? 'ok' : 'bad')}
      ${kpi('Поступления за месяц', moneyS(inM), monthName(m), 'ok')}
      ${kpi('Расходы за месяц', moneyS(outM), monthName(m), 'bl')}
      ${kpi('Освоено бюджета', fmt(S.total ? S.fact / S.total * 100 : 0, 1) + '%', moneyS(S.fact) + ' из ' + moneyS(S.total), 'ac')}
      ${kpi('Плановая прибыль', moneyS(S.profit), 'рентабельность ' + fmt(S.margin, 1) + '%', S.profit >= 0 ? 'ok' : 'bad')}
    </div>
    <h2 class="home-h">Разделы финансов</h2>
    <div class="tiles fin-tiles">
      ${FIN_SUB.map(([k, ic, t, d, [c1, c2]]) => { const [a, b] = finInfo(k, p); return `
      <button class="tile" data-act="go" data-page="${k}" style="--c1:${c1};--c2:${c2}">
        <span class="tile-ic">${ic}</span><span class="tile-t">${t}</span><span class="tile-d">${d}</span>
        <span class="tile-m">${a}</span><span class="small muted">${b}</span><span class="tile-go">Открыть →</span>
      </button>`; }).join('')}
    </div>
    <div class="grid g2 mt">
      <div class="card"><h3>Движение денег за 6 месяцев</h3>
        <div class="flow">${flow.map(f => `<div class="flow-col" title="${monthName(f.mm)}: +${money(f.inn)} / −${money(f.out)}">
          <div class="flow-bars"><i class="in" style="height:${f.inn / mx * 100}%"></i><i class="out" style="height:${f.out / mx * 100}%"></i></div>
          <span>${MONTHS_S[+f.mm.slice(5) - 1]}</span></div>`).join('')}</div>
        <div class="legend mt"><span><i style="background:var(--ok)"></i>поступления</span><span><i style="background:var(--bad)"></i>расходы</span></div>
      </div>
      <div class="card"><h3>Расходы по статьям: факт / смета <span class="tools"><button class="btn-s" data-act="go" data-page="smeta">Смета →</button></span></h3>
        ${hbars(S.lines.filter(l => l.plan || l.fact).map(l => ({ label: l.cat, plan: l.plan, fact: l.fact })))}
      </div>
    </div>`;
  }
};

/* ===== Группы разделов: Финансы, Проект и площадка, Геология и котлован, Продажи и дизайн ===== */
const GROUPS = {
  finance: { icon: '💼', title: 'Финансы', subs: FIN_SUB, grad: 'linear-gradient(135deg,#047857,#0ea5e9)' },
  build: { icon: '🏗️', title: 'Проект и площадка', grad: 'linear-gradient(135deg,#3b82f6,#f59e0b)', subs: [
    ['project', '🏢', 'Проект и здание', 'Этажность, площади, подъезды, квартирография, схема фасада и 3D всего комплекса', ['#3b82f6', '#6366f1']],
    ['prep', '🚧', 'Подготовка площадки', 'Все работы до начала стройки: документы, ограждение, бытовки, сети, дороги, кран', ['#ca8a04', '#f59e0b']]] },
  ground: { icon: GEO_ICON, title: 'Геология и котлован', grad: 'linear-gradient(135deg,#92400e,#d97706)', subs: [
    ['geology', GEO_ICON, 'Геология грунта', 'Слои грунта, скважины, несущая способность, осадка, грунтовые воды, сейсмика', ['#92400e', '#d97706']],
    ['pit', '⛏️', 'Котлован и грунт', 'Объём грунта, экскаваторы, самосвалы, рейсы, водители, топливо', ['#a16207', '#ca8a04']]] },
  sell: { icon: '🏬', title: 'Продажи и дизайн', grad: 'linear-gradient(135deg,#16a34a,#db2777)', subs: [
    ['leads', '📞', 'Лиды', 'Заявки покупателей, воронка продаж, звонки и встречи, конверсия по источникам', ['#0ea5e9', '#6366f1']],
    ['sales', '🔑', 'Продажи квартир', 'Шахматка, бронь, продажи, покупатели, рассрочка и оплаты', ['#16a34a', '#84cc16']],
    ['design', '🛋️', 'Дизайн квартиры 3D', 'Фото интерьеров, 3D-модель в 6 стилях, тур 360° по комнатам', ['#db2777', '#f472b6']],
    ['apt', '🧾', 'Квартира для клиента', 'Подбор квартиры, где она в доме, планировка, 3D, вид из окна, расчёт оплаты, бронь и задаток', ['#ea580c', '#f59e0b']],
    ['site', '🏙', 'Объект в 3D', 'Весь жилой комплекс: здание, озеленение, детская площадка, парковка, ход строительства', ['#0284c7', '#22c55e']]] },
};
const GROUP_OF = {}; Object.entries(GROUPS).forEach(([g, v]) => v.subs.forEach(s => GROUP_OF[s[0]] = g));
function groupSubnav(cur) {
  const g = GROUP_OF[cur], G = GROUPS[g];
  return `<div class="fin-subnav"><button class="fin-home" style="background:${G.grad}" data-act="go" data-page="${g}"><i class="gi">${G.icon}</i> ${G.title}</button>${G.subs.map(([k, ic, t]) => `<button class="${k === cur ? 'on' : ''}" data-act="go" data-page="${k}"><i class="gi">${ic}</i> ${t}</button>`).join('')}</div>`;
}
const subTile = (k, ic, t, d, c1, c2, a, b) => `<button class="tile" data-act="go" data-page="${k}" style="--c1:${c1};--c2:${c2}"><span class="tile-ic">${ic}</span><span class="tile-t">${t}</span><span class="tile-d">${d}</span><span class="tile-m">${a}</span><span class="small muted">${b}</span><span class="tile-go">Открыть →</span></button>`;
PAGES.build = {
  title: 'Проект и площадка',
  render(p) {
    const B = bld(p), C = prepCalc(p), left = C.crit.filter(r => r.status !== 'done');
    const info = { project: [`${B.apts} квартир · ${fmt(B.totalAbove)} м²`, 'параметры здания и фасад'], prep: [`${C.done} из ${C.count} работ · ${moneyS(C.total)}`, C.ready ? 'готово к старту' : `обязательных: ${C.critDone.length}/${C.crit.length}`] };
    return `<div class="kpis">
      ${kpi('Квартир в доме', fmt(B.apts), fmt(B.totalAbove) + ' м² наземной площади', 'bl')}
      ${kpi('Подготовка площадки', C.done + ' из ' + C.count, 'работ выполнено', C.ready ? 'ok' : 'ac')}
      ${kpi('Обязательные работы', C.critDone.length + ' из ' + C.crit.length, C.ready ? 'можно начинать котлован' : 'до начала котлована', C.ready ? 'ok' : 'bad')}
      ${kpi('Стоимость подготовки', moneyS(C.total), 'все работы до начала стройки', 'ac')}
    </div>
    <h2 class="home-h">Разделы</h2>
    <div class="tiles fin-tiles">${GROUPS.build.subs.map(([k, ic, t, d, [c1, c2]]) => subTile(k, ic, t, d, c1, c2, ...info[k])).join('')}</div>
    <div class="card mt"><h3>Что осталось сделать до котлована <span class="tools"><button class="btn-s" data-act="go" data-page="prep">Подготовка →</button></span></h3>
      ${left.length ? left.map(r => `<div class="audit a-warn"><span>⚠️</span><div>${esc(r.name)}</div><span></span></div>`).join('') : '<div class="audit a-ok"><span>✅</span><div>Все обязательные работы выполнены — можно начинать котлован</div><span></span></div>'}
    </div>`;
  }
};
PAGES.ground = {
  title: 'Геология и котлован',
  render(p) {
    const G = geoCalc(p), c = pitCalc(p), warn = G.notes.filter(n => n[0] !== 'ok');
    const info = { geology: [`${G.wells} скважин по ${fmt(G.wellDepth, 1)} м`, `R ${fmt(G.util)}% · осадка ${fmt(G.settle, 1)} см`], pit: [`${fmt(c.V)} м³ · ${fmt(c.trips)} рейсов`, `${c.trucks} самосвалов · ${moneyS(c.total)}`] };
    return `<div class="kpis">
      ${kpi('Грунт в основании', esc(G.base ? G.base.P.name : '—'), 'отметка −' + fmt(p.pit.depth, 1) + ' м', G.base && G.base.P.collapse ? 'ac' : 'bl')}
      ${kpi('Несущая способность', fmt(G.util) + '%', 'давление ' + fmt(G.pGross) + ' из ' + fmt(G.R) + ' кПа', G.util > 100 ? 'bad' : G.util > 85 ? 'ac' : 'ok')}
      ${kpi('Ожидаемая осадка', fmt(G.settle, 1) + ' см', 'допустимо ' + p.geo.sLimit + ' см', G.settle > p.geo.sLimit ? 'bad' : 'ok')}
      ${kpi('Объём котлована', fmt(c.V) + ' м³', fmt(c.trips) + ' рейсов · ' + c.days + ' дн.', 'bl')}
      ${kpi('Стоимость', moneyS(c.total + G.cost), 'котлован ' + moneyS(c.total) + ' · изыскания ' + moneyS(G.cost), 'ac')}
    </div>
    <h2 class="home-h">Разделы</h2>
    <div class="tiles fin-tiles">${GROUPS.ground.subs.map(([k, ic, t, d, [c1, c2]]) => subTile(k, ic, t, d, c1, c2, ...info[k])).join('')}</div>
    <div class="grid g2 mt">
      <div class="card"><h3>Геологический разрез <span class="tools"><button class="btn-s" data-act="go" data-page="geology">Подробно →</button></span></h3>${geoSvg(p, G)}</div>
      <div class="card"><h3>Котлован <span class="tools"><button class="btn-s" data-act="go" data-page="pit">Расчёт →</button></span></h3>${pitSvg(p, c)}
        <h4>Выводы по основанию</h4>${(warn.length ? warn : G.notes.slice(0, 3)).map(([k, t]) => `<div class="audit a-${k}"><span>${k === 'ok' ? '✅' : k === 'warn' ? '⚠️' : '❌'}</span><div>${t}</div><span></span></div>`).join('')}</div>
    </div>`;
  }
};
PAGES.sell = {
  title: 'Продажи и дизайн',
  render(p) {
    const A = genApts(p), st = s => A.filter(a => a.status === s), sold = st('sold'), bk = st('booked'), fr = st('free');
    const soldSum = sum(sold, a => a.price), soldArea = sum(sold, a => a.area), paid = sum(A, a => a.paid), od = salesOverdue(p), odSum = sum(od, x => x.debt);
    const types = {}; A.forEach(a => { const k = a.rooms ? a.rooms + '-комнатные' : 'Коммерция'; const r = types[k] = types[k] || { n: 0, sold: 0, free: 0, area: 0, sum: 0 }; r.n++; r.area += a.area; r.sum += a.price; if (a.status === 'sold') r.sold++; if (a.status === 'free') r.free++; });
    const info = { sales: [`Продано ${sold.length} из ${A.length}`, `бронь ${bk.length} · свободно ${fr.length}`], leads: (() => { const c = leadsCalc(p); return [`Активных ${c.active.length} · сделок ${c.deals.length}`, `задач на сегодня ${c.due.length}`]; })(), apt: [`Свободно ${fr.length} квартир`, 'подбор · презентация · задаток'], design: ['6 стилей · тур 360°', `${INTERIOR_PHOTOS.length} фото интерьеров`], site: (() => { const s = siteStats(p); return [`Озеленение ${fmt(s.greenPct)}% · парковка ${fmt(s.need)} мест`, 'день / вечер · ход стройки']; })() };
    return `<div class="kpis">
      ${kpi('Продано квартир', `${sold.length} из ${A.length}`, fmt(soldArea) + ' м²', 'ok')}
      ${kpi('Выручка по продажам', moneyS(soldSum), 'средняя ' + moneyS(soldArea ? soldSum / soldArea : 0) + '/м²', 'bl')}
      ${kpi('Получено оплат', moneyS(paid), 'долг по рассрочкам ' + moneyS(sum(sold, a => Math.max(0, a.price - a.paid))), 'ac')}
      ${kpi('Просрочены платежи', od.length + ' кв.', moneyS(odSum), od.length ? 'bad' : 'ok')}
    </div>
    <h2 class="home-h">Разделы</h2>
    <div class="tiles fin-tiles">${GROUPS.sell.subs.map(([k, ic, t, d, [c1, c2]]) => subTile(k, ic, t, d, c1, c2, ...info[k])).join('')}</div>
    <div class="grid g2 mt">
      <div class="card"><h3>Продажи по типам квартир <span class="tools"><button class="btn-s" data-act="go" data-page="sales">Шахматка →</button></span></h3>
        <div style="display:flex;height:22px;border-radius:7px;overflow:hidden;margin-bottom:12px">${[['sold', sold.length], ['booked', bk.length], ['free', fr.length], ['reserve', A.length - sold.length - bk.length - fr.length]].map(([k, n]) => n ? `<div class="st-${k}" style="flex:${n}" title="${ST_NAME[k]}: ${n}"></div>` : '').join('')}</div>
        <div class="tbl-wrap"><table><thead><tr><th>Тип</th><th class="n">Всего</th><th class="n">Продано</th><th class="n">Свободно</th><th class="n">Ср. площадь</th><th class="n">Ср. цена</th></tr></thead><tbody>
        ${Object.entries(types).map(([k, r]) => `<tr><td>${k}</td><td class="n">${r.n}</td><td class="n ok">${r.sold}</td><td class="n">${r.free}</td><td class="n">${fmt(r.area / r.n, 1)} м²</td><td class="n">${moneyS(r.sum / r.n)}</td></tr>`).join('')}
        </tbody></table></div></div>
      <div class="card"><h3>Дизайн для покупателей <span class="tools"><button class="btn-s pano-btn" data-act="sellPano">🌐 Тур 360°</button><button class="btn-s" data-act="go" data-page="site">🏙 Объект в 3D</button><button class="btn-s" data-act="go" data-page="design">3D квартиры →</button></span></h3>
        <div class="sell-photos">${INTERIOR_PHOTOS.map((ph, i) => `<button class="ph-th" data-act="phOpen" data-i="${i}"><img src="${phUrl(ph, 1)}" alt="" loading="lazy"><span>${STYLES[ph.style].name}<br><b>${ph.room}</b></span></button>`).join('')}</div>
        <div class="small muted mt">Нажмите на фото, чтобы показать покупателю на весь экран.</div></div>
    </div>`;
  }
};
ACT.sellPano = () => go('design', { pano: 1 });
