'use strict';
/* ===== Все инженерные и финансовые расчёты ===== */
const MIX_BASE = { 1: 40, 2: 62, 3: 84, 4: 105 };
const STATUSES = [['free', 'Свободна'], ['booked', 'Бронь'], ['sold', 'Продана'], ['reserve', 'Не в продаже']];
const ST_NAME = Object.fromEntries(STATUSES);

/* ---------- Здание ---------- */
function bld(p) {
  const b = p.b;
  const fp = b.length * b.width;
  const resFloors = Math.max(0, b.floors - (b.commercialFirst ? 1 : 0));
  const totalAbove = fp * b.floors, under = fp * b.underground, total = totalAbove + under;
  const sellable = fp * resFloors * b.sellableK;
  const commercial = b.commercialFirst ? fp * b.sellableK : 0;
  const apts = resFloors * b.sections * b.aptsPerFloor;
  const height = b.floors * b.floorH;
  const perim = 2 * (b.length + b.width);
  return {
    fp, resFloors, totalAbove, under, total, sellable, commercial, apts, height, perim,
    coverage: b.plot ? fp / b.plot * 100 : 0, volume: fp * (height + b.underground * b.floorH), avgApt: apts ? sellable / apts : 0
  };
}

/* ---------- Квартиры (шахматка) ---------- */
function genApts(p) {
  const b = p.b, B = bld(p);
  const mix = String(b.mix || '2').split(/[^0-9]+/).filter(Boolean).map(x => Math.min(4, Math.max(1, parseInt(x))));
  if (!mix.length) mix.push(2);
  const per = []; for (let i = 0; i < b.aptsPerFloor; i++) per.push(mix[i % mix.length]);
  const sumBase = per.reduce((s, r) => s + MIX_BASE[r], 0) || 1;
  const secArea = B.fp * b.sellableK / Math.max(1, b.sections);
  const scale = secArea / sumBase;
  const out = []; let n = 0;
  const push = a => {
    const o = p.apts[a.id] || {};
    a.basePrice = Math.round(a.basePrice / 1000) * 1000;
    a.status = o.status || 'free'; a.price = o.price || a.basePrice;
    a.buyer = o.buyer || ''; a.phone = o.phone || '';
    a.paid = sum(o.payments || [], x => x.amount); a.o = o; out.push(a);
  };
  for (let s = 1; s <= b.sections; s++) {
    if (b.commercialFirst) { const area = r1(secArea); push({ id: `K-${s}`, num: 'К' + s, section: s, floor: 1, idx: 0, rooms: 0, area, basePrice: area * b.commercialPrice }); }
    const f0 = b.commercialFirst ? 2 : 1;
    for (let f = f0; f <= b.floors; f++) for (let i = 0; i < b.aptsPerFloor; i++) {
      n++; const rooms = per[i]; const area = r1(MIX_BASE[rooms] * scale);
      push({ id: `${s}-${f}-${i}`, num: String(n), section: s, floor: f, idx: i, rooms, area, basePrice: area * b.pricePerM2 * (1 + (f - f0) * b.floorStep / 100) });
    }
  }
  return out;
}

/* ---------- Котлован ---------- */
function pitCalc(p) {
  const t = p.pit, H = t.depth, m = t.slope;
  const Ab = t.length * t.width;
  const Lt = t.length + 2 * m * H, Wt = t.width + 2 * m * H, At = Lt * Wt;
  const Am = (t.length + m * H) * (t.width + m * H);
  const V = H / 6 * (Ab + At + 4 * Am);                 // объём в плотном теле (формула призматоида)
  const Vkeep = V * t.backfillPct / 100;               // оставить на обратную засыпку
  const Vexp = V - Vkeep;                              // вывоз (плотный)
  const Vloose = Vexp * t.looseK;                       // вывоз (в разрыхлённом)
  const trips = Math.ceil(Vloose / Math.max(0.1, t.truckCap));
  const excCount = Math.max(1, t.excCount), prod = Math.max(1, t.excProd);
  const excH = V / (prod * excCount);                  // часов работы (календарных)
  const days = Math.ceil(excH / Math.max(1, t.shiftH * t.shifts));
  const loadMin = (t.truckCap / t.looseK) / prod * 60;
  const travelMin = 2 * t.distance / Math.max(1, t.speed) * 60;
  const cycle = loadMin + travelMin + t.unloadMin + 3;
  const trucksPerExc = Math.ceil(cycle / Math.max(0.1, loadMin));
  const trucks = trucksPerExc * excCount;
  const tripsPerShift = Math.floor(t.shiftH * 60 / cycle);
  const drivers = trucks * t.shifts, operators = excCount * t.shifts;
  const excMh = excH * excCount;
  const excFuel = excMh * t.excFuel;
  const km = trips * 2 * t.distance;
  const truckFuel = km * t.truckL100 / 100;
  const truckH = trips * cycle / 60;
  const fuel = excFuel + truckFuel;
  const fuelCost = fuel * DB.settings.fuelPrice;
  const excRent = excMh * t.excRent;
  const truckRent = trips * t.truckRentTrip;
  const wages = excMh * t.operatorRate + truckH * t.driverRate;
  const dump = Vloose * t.dumpFee;
  const incl = !!t.rentInclFuel;
  const total = excRent + truckRent + dump + (incl ? 0 : fuelCost + wages);
  return { V, Vkeep, Vexp, Vloose, trips, excH, excMh, days, loadMin, travelMin, cycle, trucksPerExc, trucks, tripsPerShift, drivers, operators, excFuel, truckFuel, km, truckH, fuel, fuelCost, excRent, truckRent, wages, dump, total, Lt, Wt, incl };
}

/* ---------- Материалы ---------- */
const MAT_DEF = [
  ['concreteFound', 'Бетон и арматура', 'Бетон фундамента и подземной части B25', 'м³', 950000],
  ['concreteFrame', 'Бетон и арматура', 'Бетон каркаса: перекрытия, стены, колонны B25', 'м³', 950000],
  ['rebar', 'Бетон и арматура', 'Арматура А500С', 'т', 9500000],
  ['formwork', 'Бетон и арматура', 'Опалубка (аренда, оборот)', 'м²', 40000],
  ['waterproof', 'Бетон и арматура', 'Гидроизоляция фундамента', 'м²', 95000],
  ['board', 'Пиломатериалы', 'Доска обрезная 25–50 мм (опалубка, подмости, настилы)', 'м³', 3200000],
  ['beam', 'Пиломатериалы', 'Брус 100×100 / 150×150 (ригели опалубки, стойки, леса)', 'м³', 3600000],
  ['blocks', 'Стены и кладка', 'Газоблок D500 (наружные стены и перегородки)', 'м³', 780000],
  ['bricks', 'Стены и кладка', 'Кирпич облицовочный', 'шт', 1700],
  ['glue', 'Стены и кладка', 'Клей / кладочный раствор', 'мешок', 55000],
  ['insul', 'Фасад и кровля', 'Утеплитель фасада 100 мм', 'м²', 95000],
  ['facade', 'Фасад и кровля', 'Фасадная система / штукатурка', 'м²', 190000],
  ['roof', 'Фасад и кровля', 'Кровля (мембрана, утеплитель, уклоны)', 'м²', 280000],
  ['windows', 'Окна и двери', 'Окна и витражи (алюминий/ПВХ)', 'м²', 1100000],
  ['doors', 'Окна и двери', 'Входные двери квартир и помещений', 'шт', 2900000],
  ['plaster', 'Черновая отделка', 'Штукатурка стен (смесь 30 кг)', 'мешок', 65000],
  ['screed', 'Черновая отделка', 'Стяжка пола', 'м³', 720000],
  ['putty', 'Черновая отделка', 'Грунт, шпаклёвка, краска', 'м²', 14000],
  ['tile', 'Черновая отделка', 'Плитка МОП и санузлов', 'м²', 150000],
  ['cable', 'Электрика', 'Кабель ВВГнг (все сечения)', 'м', 14000],
  ['elPoints', 'Электрика', 'Розетки, выключатели, щиты, автоматы', 'точка', 90000],
  ['pipes', 'Сантехника и отопление', 'Трубы водопровода и канализации', 'м', 38000],
  ['heating', 'Сантехника и отопление', 'Отопление: радиаторы, стояки (на квартиру)', 'компл', 9000000],
  ['vent', 'Вентиляция и пожарная безопасность', 'Вентиляция, дымоудаление, пожарная сигнализация (на квартиру)', 'компл', 4500000],
  ['lifts', 'Лифты', 'Лифт пассажирский с монтажом', 'шт', 650000000],
  ['landscape', 'Благоустройство', 'Благоустройство: асфальт, плитка, детская площадка, озеленение', 'м²', 220000],
];
function matQty(p) {
  const b = p.b, m = p.m, B = bld(p), q = {};
  q.concreteFound = B.fp * m.slabThk + B.perim * b.floorH * b.underground * 0.3;
  q.concreteFrame = B.fp * (b.floors + b.underground) * (m.floorThk + m.wallsK);
  q.concreteAll = q.concreteFound + q.concreteFrame;
  q.rebar = q.concreteAll * m.rebarK * (p.geo && p.geo.seismicFix ? 1.12 : 1);
  q.formwork = q.concreteFrame * 6;
  q.waterproof = B.fp + B.perim * b.floorH * b.underground;
  q.board = q.formwork * (m.boardK ?? 0.004) + B.total * 0.002;
  q.beam = q.formwork * (m.beamK ?? 0.0025);
  const extGross = B.perim * b.floorH * b.floors;
  q.windows = extGross * m.glazingK; q.extNet = extGross - q.windows;
  q.blocks = q.extNet * m.extWallThk + B.totalAbove * m.partitionsK * 0.1;
  q.bricks = m.brickFacing ? q.extNet * 51 : 0;
  q.glue = q.blocks * 1.3;
  q.insul = q.extNet; q.facade = q.extNet;
  q.roof = B.fp * 1.05;
  q.doors = B.apts + (b.commercialFirst ? b.sections : 0);
  q.plasterArea = B.totalAbove * 2.4; q.plaster = Math.ceil(q.plasterArea * 12 / 30);
  q.screed = B.total * 0.05;
  q.putty = q.plasterArea;
  q.tile = B.apts * 16 + B.totalAbove * 0.06;
  q.cable = B.apts * 450 + B.totalAbove * 1.2;
  q.elPoints = B.apts * 35 + B.totalAbove * 0.05;
  q.pipes = B.apts * 55 + B.height * b.sections * 8;
  q.heating = B.apts;
  q.lifts = b.sections * (b.floors > 9 ? 2 : 1);
  q.vent = B.apts + (b.commercialFirst ? b.sections : 0);
  q.landscape = Math.max(0, b.plot - B.fp);
  return { q, B };
}
function matCalc(p) {
  const { q, B } = matQty(p);
  const rows = MAT_DEF.map(([key, group, name, unit, price]) => {
    const qty = q[key] || 0, pr = p.prices[key] ?? price;
    return { key, group, name, unit, qty, price: pr, sum: qty * pr };
  });
  p.extraMat.forEach((e, i) => rows.push({ key: 'x' + i, i, group: 'Дополнительные позиции', name: e.name, unit: e.unit, qty: e.qty, price: e.price, sum: e.qty * e.price, extra: true }));
  const groups = {}; rows.forEach(r => groups[r.group] = (groups[r.group] || 0) + r.sum);
  return { rows, groups, total: sum(rows, r => r.sum), q, B };
}

/* ---------- Труд и бригады ---------- */
const LAB_DEF = [ // key, профессия, этап, объём(ключ), ед., выработка в смену на 1 чел, срок (дней), ставка за смену
  ['concrete', 'Бетонщики', 'Каркас', 'concreteAll', 'м³', 1.3, 260, 300000],
  ['rebar', 'Арматурщики', 'Каркас', 'rebar', 'т', 0.25, 260, 300000],
  ['carpenter', 'Плотники-опалубщики', 'Каркас', 'formwork', 'м²', 5, 260, 300000],
  ['welder', 'Сварщики', 'Каркас', 'rebar', 'т', 0.8, 260, 330000],
  ['mason', 'Каменщики / кладчики (газоблок)', 'Стены и фасад', 'blocks', 'м³', 1.1, 220, 300000],
  ['facer', 'Каменщики облицовки (кирпич)', 'Стены и фасад', 'bricks', 'шт', 350, 200, 320000],
  ['roofer', 'Кровельщики', 'Стены и фасад', 'roof', 'м²', 15, 35, 280000],
  ['window', 'Монтажники окон', 'Стены и фасад', 'windows', 'м²', 9, 150, 300000],
  ['facade', 'Фасадчики', 'Стены и фасад', 'facade', 'м²', 7, 160, 290000],
  ['electric', 'Электрики', 'Сети и отделка', 'elPoints', 'точка', 4, 220, 320000],
  ['plumber', 'Сантехники', 'Сети и отделка', 'pipes', 'м', 5, 220, 320000],
  ['plasterer', 'Штукатуры', 'Сети и отделка', 'plasterArea', 'м²', 14, 140, 260000],
  ['painter', 'Маляры', 'Сети и отделка', 'plasterArea', 'м²', 28, 140, 250000],
  ['tiler', 'Плиточники', 'Сети и отделка', 'tile', 'м²', 6, 140, 300000],
];
const ITR_DEF = [ // key, должность, кол-во (null = авто), оклад в месяц
  ['chief', 'Главный инженер проекта', 1, 18000000],
  ['prorab', 'Прорабы (1 на 30 рабочих)', null, 12000000],
  ['master', 'Мастера участков (1 на 15 рабочих)', null, 9000000],
  ['pto', 'Инженеры ПТО и сметчики', 2, 9000000],
  ['geo', 'Геодезист', 1, 8000000],
  ['safety', 'Инженер по охране труда (ТБ)', 1, 7500000],
  ['supply', 'Снабженец', 1, 7000000],
  ['store', 'Кладовщик', 1, 5000000],
  ['acc', 'Бухгалтер', 1, 8000000],
  ['guard', 'Охрана площадки', 3, 4000000],
];
function projSpan(p) {
  if (!p.stages.length) return { start: p.startDate, end: p.startDate, days: 0 };
  let s = p.stages[0].start, e = s;
  p.stages.forEach(st => { if (st.start < s) s = st.start; const en = addDays(st.start, st.dur); if (en > e) e = en; });
  return { start: s, end: e, days: daysBetween(s, e) };
}
function labCalc(p) {
  const { q } = matQty(p), b = p.b;
  const rows = LAB_DEF.map(([key, name, group, qk, unit, norm, days, rate]) => {
    const o = p.labour[key] || {};
    const N = o.norm ?? norm, Dd = o.days ?? days, R = o.rate ?? rate, qty = q[qk] || 0;
    const pd = N > 0 ? qty / N : 0, workers = Dd > 0 ? Math.ceil(pd / Dd) : 0;
    return { key, name, group, unit, qty, norm: N, days: Dd, rate: R, pd, workers, cost: pd * R };
  });
  const pdSum = sum(rows, r => r.pd);
  const hk = p.labour.helpers || {};
  const hPct = hk.pct ?? 15, hDays = hk.days ?? 450, hRate = hk.rate ?? 200000, hPd = pdSum * hPct / 100;
  const helpers = { pd: hPd, workers: Math.ceil(hPd / Math.max(1, hDays)), cost: hPd * hRate, pct: hPct, days: hDays, rate: hRate };
  const ck = p.labour.crane || {};
  const cCount = ck.count ?? b.sections * 2, cDays = ck.days ?? rows[0].days, cRate = ck.rate ?? 380000;
  const crane = { count: cCount, days: cDays, rate: cRate, pd: cCount * cDays, cost: cCount * cDays * cRate };
  const groups = {}; rows.forEach(r => groups[r.group] = (groups[r.group] || 0) + r.workers);
  const peak = Math.max(0, ...Object.values(groups)) + helpers.workers + crane.count;
  const span = projSpan(p), months = Math.max(1, span.days / 30.4);
  const itr = ITR_DEF.map(([key, name, cnt, rate]) => {
    const o = p.itr[key] || {}; let auto = cnt;
    if (key === 'prorab') auto = Math.max(1, Math.ceil(peak / 30));
    if (key === 'master') auto = Math.max(1, Math.ceil(peak / 15));
    const c = o.count ?? auto, R = o.rate ?? rate, mo = o.months ?? Math.round(months);
    return { key, name, count: c, rate: R, months: mo, cost: c * R * mo, auto: cnt == null };
  });
  const workCost = sum(rows, r => r.cost) + helpers.cost + crane.cost;
  const itrCost = sum(itr, r => r.cost);
  const workersTotal = sum(rows, r => r.workers) + helpers.workers + crane.count;
  return { rows, helpers, crane, itr, groups, peak, workCost, itrCost, workersTotal, pdTotal: pdSum + hPd + crane.pd, months, itrCount: sum(itr, r => r.count) };
}

/* ---------- Техника ---------- */
function machCalc(p) {
  const pit = pitCalc(p), { q } = matQty(p), b = p.b, span = projSpan(p);
  const frameDays = p.labour.concrete?.days ?? 260;
  const defs = [
    { key: 'exc', name: 'Экскаватор (котлован)', count: Math.max(1, p.pit.excCount), hours: pit.excH, lph: p.pit.excFuel, rent: p.pit.excRent, pit: true },
    { key: 'truck', name: 'Самосвал (вывоз грунта)', count: pit.trucks, hours: pit.truckH / Math.max(1, pit.trucks), lph: 0, fuelL: pit.truckFuel, rent: 0, pit: true, note: `${fmt(pit.trips)} рейсов` },
    { key: 'dozer', name: 'Бульдозер', count: 1, hours: Math.round(pit.excH * 0.6 + 80), lph: 14, rent: 380000 },
    { key: 'roller', name: 'Каток грунтовый', count: 1, hours: 120, lph: 8, rent: 250000 },
    { key: 'crane', name: 'Башенный кран (электро)', count: b.sections, hours: Math.round(frameDays * 1.4 * 16), lph: 0, rent: 200000, note: 'электроэнергия отдельно' },
    { key: 'pump', name: 'Автобетононасос', count: 1, hours: Math.ceil(q.concreteAll / 18), lph: 16, rent: 850000 },
    { key: 'mixer', name: 'Автобетоносмеситель (миксер)', count: Math.max(2, Math.ceil(q.concreteAll / frameDays / 35)), hours: Math.ceil(q.concreteAll / 7 * 1.5 / Math.max(2, Math.ceil(q.concreteAll / frameDays / 35))), lph: 0, rent: 0, note: `${fmt(q.concreteAll / 7)} рейсов, доставка в цене бетона` },
    { key: 'autocrane', name: 'Автокран 25 т', count: 1, hours: 700, lph: 12, rent: 450000 },
    { key: 'loader', name: 'Фронтальный погрузчик', count: 1, hours: 900, lph: 11, rent: 320000 },
    { key: 'flatbed', name: 'Бортовой грузовик (материалы)', count: 2, hours: 1500, lph: 9, rent: 220000 },
    { key: 'lift', name: 'Фасадные люльки / подъёмники', count: b.sections * 2, hours: 1200, lph: 0, rent: 50000 },
    { key: 'gen', name: 'Дизель-генератор', count: 1, hours: 2000, lph: 6, rent: 60000 },
    { key: 'bus', name: 'Автобус для рабочих', count: 1, hours: Math.round(span.days * 3), lph: 9, rent: 150000 },
  ];
  const rows = defs.map(d => {
    const o = p.mach[d.key] || {}, r = { ...d };
    if (!d.pit) ['count', 'hours', 'lph', 'rent'].forEach(k => { if (o[k] != null) r[k] = o[k]; });
    r.mh = r.count * r.hours;
    r.fuelL = d.fuelL ?? r.mh * r.lph;
    r.fuelCost = r.fuelL * DB.settings.fuelPrice;
    r.rentCost = r.mh * r.rent;
    r.total = r.fuelCost + r.rentCost;
    return r;
  });
  const own = rows.filter(r => !r.pit);
  return { rows, total: sum(own, r => r.total), fuelL: sum(rows, r => r.fuelL), units: sum(rows, r => r.count) };
}

/* ---------- Финансы ---------- */
function finance(p) {
  let inc = 0, out = 0; const byCat = {};
  p.tx.forEach(t => { if (t.type === 'in') inc += t.amount; else { out += t.amount; byCat[t.cat] = (byCat[t.cat] || 0) + t.amount; } });
  return { inc, out, bal: inc - out, byCat };
}
function progress(p) { let s = 0, w = 0; p.stages.forEach(st => { s += st.dur * (st.progress || 0); w += st.dur; }); return w ? s / w : 0; }

function smetaCalc(p) {
  const B = bld(p), pit = pitCalc(p), M = matCalc(p), L = labCalc(p), Mc = machCalc(p), F = finance(p), o = p.overhead, A = genApts(p);
  const revenue = sum(A, a => a.price);
  const base = prepCalc(p).total + pit.total + M.total + L.workCost + L.itrCost + Mc.total + (o.subcontract || 0);
  const prep = prepCalc(p).total;
  const lines = [
    { cat: 'Подготовительные работы', name: 'Изыскания, геология и подготовка площадки', plan: prep },
    { cat: 'Котлован и грунт', name: 'Котлован: разработка и вывоз грунта', plan: pit.total },
    { cat: 'Материалы', name: 'Строительные материалы и оборудование', plan: M.total },
    { cat: 'Зарплата рабочих', name: 'Оплата труда рабочих бригад', plan: L.workCost },
    { cat: 'Зарплата ИТР', name: 'ИТР, прорабы, управление', plan: L.itrCost },
    { cat: 'Техника и топливо', name: 'Техника: аренда и топливо', plan: Mc.total },
    { cat: 'Субподряд', name: 'Субподрядные работы', plan: o.subcontract || 0 },
    { cat: 'Проект и разрешения', name: `Проектирование (${o.designPct}%), земля, подключение к сетям`, plan: base * o.designPct / 100 + (o.land || 0) + (o.permits || 0) },
    { cat: 'Офис и прочее', name: `Накладные (${o.overheadPct}%) и непредвиденные (${o.reservePct}%)`, plan: base * (o.overheadPct + o.reservePct) / 100 },
    { cat: 'Налоги и сборы', name: `Налоги (${o.taxPct}% от выручки)`, plan: revenue * o.taxPct / 100 },
  ];
  lines.forEach(l => l.fact = F.byCat[l.cat] || 0);
  const known = new Set(lines.map(l => l.cat));
  Object.keys(F.byCat).forEach(c => { if (!known.has(c)) lines.push({ cat: c, name: c, plan: 0, fact: F.byCat[c] }); });
  const total = sum(lines, l => l.plan);
  const profit = revenue - total;
  return { lines, total, revenue, profit, margin: revenue ? profit / revenue * 100 : 0, perM2: B.total ? total / B.total : 0, perSell: (B.sellable + B.commercial) ? total / (B.sellable + B.commercial) : 0, fact: F.out, B, F };
}
