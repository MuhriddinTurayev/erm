'use strict';
/* ===== Справочники, новый проект, демо-данные ===== */
const ROLES = ['Прораб', 'Мастер участка', 'Главный инженер', 'Инженер ПТО', 'Сметчик', 'Геодезист', 'Инженер по ТБ', 'Снабженец', 'Кладовщик', 'Бухгалтер', 'Менеджер по продажам', 'Дизайнер',
  'Бетонщик', 'Арматурщик', 'Плотник-опалубщик', 'Сварщик', 'Каменщик (кладчик)', 'Штукатур', 'Маляр', 'Плиточник', 'Электрик', 'Сантехник', 'Кровельщик', 'Фасадчик', 'Монтажник',
  'Крановщик', 'Машинист экскаватора', 'Водитель самосвала', 'Водитель', 'Разнорабочий', 'Охранник'];
const ITR_ROLES = new Set(['Прораб', 'Мастер участка', 'Главный инженер', 'Инженер ПТО', 'Сметчик', 'Геодезист', 'Инженер по ТБ', 'Снабженец', 'Кладовщик', 'Бухгалтер', 'Менеджер по продажам', 'Дизайнер']);
const TX_OUT = ['Подготовительные работы', 'Котлован и грунт', 'Материалы', 'Зарплата рабочих', 'Зарплата ИТР', 'Техника и топливо', 'Субподряд', 'Проект и разрешения', 'Налоги и сборы', 'Офис и прочее'];
const TX_IN = ['Продажа квартир', 'Продажа коммерции', 'Инвестиции', 'Кредит банка', 'Прочие поступления'];
const VEH_TYPES = ['Экскаватор', 'Самосвал', 'Бульдозер', 'Автокран', 'Башенный кран', 'Бетононасос', 'Миксер', 'Погрузчик', 'Каток', 'Грузовик', 'Автобус', 'Легковой', 'Генератор', 'Прочее'];
const STAGE_DEF = [
  ['Подготовка площадки, ограждение, бытовки', 0, 20],
  ['Геодезическая разбивка осей', 10, 7],
  ['Котлован: разработка и вывоз грунта', 17, 30],
  ['Фундаментная плита', 45, 40],
  ['Подземная часть, гидроизоляция', 80, 40],
  ['Монолитный каркас', 115, 260],
  ['Кладка наружных стен и перегородок', 175, 220],
  ['Кровля', 380, 35],
  ['Окна и витражи', 300, 150],
  ['Фасад и утепление', 330, 160],
  ['Электромонтаж', 260, 220],
  ['Водопровод, канализация, отопление', 260, 220],
  ['Лифты', 400, 60],
  ['Черновая отделка и МОП', 380, 140],
  ['Благоустройство территории', 480, 60],
  ['Ввод в эксплуатацию', 540, 30],
];
function defaultStages(start) { return STAGE_DEF.map(([name, off, dur]) => ({ id: uid(), name, start: addDays(start, off), dur, progress: 0, resp: '' })); }

function newProject(name, start) {
  start = start || today();
  return {
    id: uid(), name: name || 'Новый проект', address: '', status: 'Проектирование', startDate: start,
    b: { plot: 6000, floors: 12, underground: 1, sections: 2, aptsPerFloor: 4, length: 56, width: 17, floorH: 3.0, commercialFirst: true, sellableK: 0.72, mix: '1,2,2,3', pricePerM2: 11000000, commercialPrice: 14000000, floorStep: 0.5 },
    pit: { length: 60, width: 21, depth: 4.5, slope: 0.5, looseK: 1.25, backfillPct: 12, excProd: 60, excCount: 2, excFuel: 17, excRent: 280000, operatorRate: 45000, truckCap: 16, truckL100: 40, distance: 15, speed: 30, unloadMin: 6, truckRentTrip: 150000, driverRate: 35000, dumpFee: 4000, shiftH: 10, shifts: 2, rentInclFuel: false },
    m: { slabThk: 0.9, floorThk: 0.2, wallsK: 0.12, rebarK: 0.11, extWallThk: 0.3, partitionsK: 0.6, glazingK: 0.22, brickFacing: true, boardK: 0.004, beamK: 0.0025 },
    prices: {}, labour: {}, itr: {}, mach: {}, extraMat: [],
    overhead: { designPct: 3, overheadPct: 7, reservePct: 5, land: 0, permits: 150000000, subcontract: 0, taxPct: 0 },
    staff: [], ts: {}, pays: [], fleet: [], fuel: [],
    stages: defaultStages(start), tx: [], apts: {},
    design: { rooms: 2, area: 62, style: 'modern' },
    geo: geoDefaults(), prep: {}, prepExtra: [], cands: [], safety: safeDefaults(), legal: legalDefaults(), leads: [], gps: { lat: '', lng: '', r: 150, autoTs: true }, geoEv: [],
  };
}
function migrate(p) {
  const d = newProject(p.name, p.startDate);
  for (const k in d) {
    if (p[k] === undefined) p[k] = d[k];
    else if (d[k] && typeof d[k] === 'object' && !Array.isArray(d[k]) && p[k] && typeof p[k] === 'object') for (const kk in d[k]) if (p[k][kk] === undefined) p[k][kk] = d[k][kk];
  }
}
/* Новый объект открывается с параметрами-образцом (12 этажей, 56×17 м…): смета, материалы, котлован, бригады и 3D
   посчитаны для него. Пока параметры своего дома не введены (или не подтверждены) — показываем это явно. */
let _defB = null;
function isDraft(p) {
  if (p.draft != null) return !!p.draft;
  if (p.demo || (p.tx || []).length) return false;
  _defB = _defB || newProject().b;
  return Object.keys(_defB).every(k => p.b[k] === _defB[k]);   // объекты, созданные до этой отметки: параметры не трогали — образец
}
const DRAFT_PAGES = new Set(['home', 'dashboard', 'build', 'project', 'prep', 'ground', 'geology', 'pit', 'finance', 'smeta', 'materials', 'labour', 'schedule', 'sell', 'sales', 'apt', 'design', 'site']);
function draftNotice(p, page) {
  if (!isDraft(p) || !DRAFT_PAGES.has(page)) return '';
  const b = p.b;
  return `<div class="draft-note"><div class="dn-t">📐 <b>${T('Это расчёт по образцу дома, а не ваши данные')}</b></div>
    <div class="dn-s">${T('Образец программы')}: ${b.floors} ${T('эт.')} · ${b.length}×${b.width} ${T('м')} · ${T('подъездов')}: ${b.sections} · ${T('квартир на этаже')}: ${b.aptsPerFloor}.
    ${T('Смета, материалы, котлован, бригады, квартиры и 3D посчитаны для него. Это план «сколько понадобится», а не деньги в кассе и не остатки на складе.')}</div>
    <div class="dn-a">${page !== 'project' ? `<button class="btn-p btn-s" data-act="go" data-page="project">${T('Ввести параметры своего дома')}</button>` : ''}<button class="btn-s" data-act="draftOk">${T('Параметры верны')}</button></div></div>`;
}

function addTx(p, t) { const tx = { id: uid(), date: today(), type: 'out', cat: 'Офис и прочее', amount: 0, who: '', desc: '', ...t }; p.tx.push(tx); return tx; }

/* ---------- Демо-проект ---------- */
function demoProject() {
  const R = rng(20261009), ri = (a, b) => Math.floor(a + R() * (b - a + 1)), pick = a => a[Math.floor(R() * a.length)];
  const start = addDays(today(), -220);
  const p = newProject('ЖК «Новый Город» (демо)', start);
  p.address = 'ул. Строителей, участок 15'; p.status = 'Строительство'; p.demo = true;
  const t = today();
  p.stages.forEach(s => { const done = daysBetween(s.start, t) / s.dur; s.progress = Math.max(0, Math.min(100, Math.round(done * 100 * (0.85 + R() * 0.2) / 5) * 5)); });
  const resp = ['Каримов А.', 'Петров С.', 'Юсупов Б.'];
  p.stages.forEach((s, i) => s.resp = resp[i % 3]);
  const people = [
    ['Алишер Каримов', 'Прораб', 75000], ['Сергей Петров', 'Прораб', 72000], ['Бахтиёр Юсупов', 'Мастер участка', 55000], ['Нодира Алиева', 'Инженер ПТО', 52000],
    ['Олег Смирнов', 'Геодезист', 50000], ['Камола Юлдашева', 'Бухгалтер', 48000], ['Рустам Назаров', 'Бетонщик', 32000], ['Дильшод Рахимов', 'Бетонщик', 32000],
    ['Азиз Тошматов', 'Арматурщик', 33000], ['Фарход Исмоилов', 'Арматурщик', 33000], ['Жасур Абдуллаев', 'Плотник-опалубщик', 31000], ['Шерзод Холматов', 'Плотник-опалубщик', 31000],
    ['Ильхом Саидов', 'Сварщик', 38000], ['Тимур Ахмедов', 'Каменщик (кладчик)', 34000], ['Анвар Мирзаев', 'Каменщик (кладчик)', 34000], ['Улугбек Норматов', 'Электрик', 36000],
    ['Николай Иванов', 'Сантехник', 36000], ['Бобур Эргашев', 'Крановщик', 42000], ['Отабек Хасанов', 'Машинист экскаватора', 45000], ['Мурод Каримов', 'Водитель самосвала', 35000],
    ['Достон Рашидов', 'Водитель самосвала', 35000], ['Санжар Умаров', 'Разнорабочий', 25000], ['Лола Каримова', 'Менеджер по продажам', 45000], ['Евгений Ким', 'Маляр', 30000],
  ];
  p.staff = people.map(([name, role, rate]) => ({ id: uid(), name, role, rate, phone: `+998 9${ri(0, 9)} ${ri(100, 999)} ${ri(10, 99)} ${ri(10, 99)}`, active: true, hired: start }));
  // табель: прошлый и текущий месяц
  const m0 = monthNow(), dPrev = new Date(t + 'T00:00:00'); dPrev.setDate(1); dPrev.setMonth(dPrev.getMonth() - 1);
  const mPrev = isoDate(dPrev).slice(0, 7);
  [mPrev, m0].forEach(m => {
    const [y, mm] = m.split('-').map(Number), dim = new Date(y, mm, 0).getDate();
    for (let d = 1; d <= dim; d++) {
      const ds = `${m}-${String(d).padStart(2, '0')}`; if (ds > t) break;
      if (new Date(ds + 'T00:00:00').getDay() === 0) continue;
      p.staff.forEach(s => { if (R() < 0.94) { (p.ts[s.id] = p.ts[s.id] || {})[ds] = R() < 0.25 ? 10 : 8; } });
    }
  });
  // выплаты за прошлый месяц
  p.staff.forEach(s => {
    let h = 0, amt = 0; for (const d in p.ts[s.id] || {}) if (d.startsWith(mPrev)) { const v = p.ts[s.id][d]; h += v; amt += Math.min(v, 8) * s.rate + Math.max(0, v - 8) * s.rate * 1.5; }
    if (!amt) return; const pid = uid(), date = addDays(mPrev + '-01', 34);
    const tx = addTx(p, { date: date > t ? t : date, type: 'out', cat: ITR_ROLES.has(s.role) ? 'Зарплата ИТР' : 'Зарплата рабочих', amount: Math.round(amt), who: s.name, desc: `Зарплата за ${monthName(mPrev)}`, link: { kind: 'pay', id: pid } });
    p.pays.push({ id: pid, staffId: s.id, month: mPrev, date: tx.date, amount: tx.amount, txId: tx.id });
  });
  // техника
  const veh = [['Hyundai R220 экскаватор', 'Экскаватор', '01 A 123 BC', 'h', 17], ['Komatsu PC200 экскаватор', 'Экскаватор', '01 B 456 CD', 'h', 16],
    ['HOWO самосвал 25 т №1', 'Самосвал', '01 C 789 DE', 'km', 40], ['HOWO самосвал 25 т №2', 'Самосвал', '01 D 321 EF', 'km', 40], ['HOWO самосвал 25 т №3', 'Самосвал', '01 E 654 FG', 'km', 41],
    ['Миксер Shacman 10 м³', 'Миксер', '01 F 987 GH', 'km', 38], ['Автокран XCMG 25 т', 'Автокран', '01 G 147 HI', 'h', 12], ['Погрузчик SDLG', 'Погрузчик', '01 H 258 IJ', 'h', 11]];
  const drivers = p.staff.filter(s => /Водитель|Машинист|Крановщик/.test(s.role));
  p.fleet = veh.map(([name, type, plate, mode, norm], i) => ({ id: uid(), name, type, plate, mode, norm, driver: drivers[i % drivers.length]?.name || '', own: i % 3 !== 2, rent: i % 3 === 2 ? 300000 : 0 }));
  for (let k = 0; k < 40; k++) {
    const v = pick(p.fleet), date = addDays(t, -ri(0, 45)), qty = v.mode === 'h' ? ri(6, 20) : ri(80, 260);
    const normL = v.mode === 'h' ? qty * v.norm : qty * v.norm / 100, liters = Math.round(normL * (0.92 + R() * 0.2)), price = DB.settings.fuelPrice;
    const id = uid(); const tx = addTx(p, { date, type: 'out', cat: 'Техника и топливо', amount: liters * price, who: 'АЗС', desc: `Топливо: ${v.name}, ${liters} л`, link: { kind: 'fuel', id } });
    p.fuel.push({ id, date, vid: v.id, qty, liters, price, txId: tx.id });
  }
  prepDefs(p).forEach(([k]) => { p.prep[k] = { status: 'done' }; });
  [['Бетонщик', 5], ['Плотник-опалубщик', 6], ['Кровельщик', 2], ['Фасадчик', 3]].forEach(([r, n]) => hire(p, r, n));
  safetyDemo(p);
  [['Шухрат Мирзаев', 'Бетонщик', 'interview', 'Telegram', 6], ['Акмал Турсунов', 'Плотник-опалубщик', 'new', 'OLX', 3], ['Виктор Ли', 'Сварщик', 'trial', 'Знакомые / рекомендация', 10], ['Зафар Исмоилов', 'Арматурщик', 'new', 'Пришёл сам', 2]].forEach(([name, role, status, src, exp]) => p.cands.push({ id: uid(), name, role, status, src, exp, phone: `+998 9${ri(0, 9)} ${ri(100, 999)} ${ri(10, 99)} ${ri(10, 99)}`, rate: 0, note: '', date: addDays(t, -ri(1, 10)) }));
  // расходы
  const sup = ['ООО «БетонСтрой»', 'ООО «Металл-Инвест»', 'ЧП «Газоблок»', 'ООО «ЭлектроКабель»', 'ООО «ГеоПроект»', 'ООО «СпецТехника»'];
  [[8, 'Подготовительные работы', 650000000, 'ООО «СпецТехника»', 'Ограждение, бытовки, временные сети'], [0, 'Проект и разрешения', 520000000, 'ООО «ГеоПроект»', 'Рабочий проект и экспертиза'], [5, 'Проект и разрешения', 180000000, 'Хокимият', 'Разрешение на строительство'],
   [18, 'Котлован и грунт', 120000000, 'ООО «СпецТехника»', 'Разработка котлована, 1 этап'], [35, 'Котлован и грунт', 95000000, 'ООО «СпецТехника»', 'Вывоз грунта, 2 этап'],
   [50, 'Материалы', 1450000000, 'ООО «БетонСтрой»', 'Бетон B25 фундаментная плита'], [48, 'Материалы', 1180000000, 'ООО «Металл-Инвест»', 'Арматура А500С'],
   [90, 'Материалы', 980000000, 'ООО «БетонСтрой»', 'Бетон подземная часть'], [120, 'Материалы', 1320000000, 'ООО «БетонСтрой»', 'Бетон каркас 1–3 этажи'],
   [130, 'Материалы', 900000000, 'ООО «Металл-Инвест»', 'Арматура каркас'], [175, 'Материалы', 640000000, 'ЧП «Газоблок»', 'Газоблок D500'],
   [190, 'Материалы', 1250000000, 'ООО «БетонСтрой»', 'Бетон каркас 4–6 этажи'], [200, 'Офис и прочее', 95000000, 'ООО «Охрана+»', 'Охрана и бытовки'],
   [60, 'Техника и топливо', 420000000, 'ООО «СпецТехника»', 'Аренда башенного крана'], [150, 'Техника и топливо', 380000000, 'ООО «СпецТехника»', 'Аренда крана и бетононасоса'],
   [210, 'Материалы', 260000000, 'ООО «ЭлектроКабель»', 'Кабель и щиты']].forEach(([off, cat, amount, who, desc]) => {
    const date = addDays(start, off); if (date <= t) addTx(p, { date, type: 'out', cat, amount, who, desc });
  });
  addTx(p, { date: addDays(start, 2), type: 'in', cat: 'Инвестиции', amount: 6000000000, who: 'Учредители', desc: 'Взнос в уставный капитал проекта' });
  addTx(p, { date: addDays(start, 100), type: 'in', cat: 'Кредит банка', amount: 4000000000, who: 'Банк', desc: 'Проектное финансирование, транш 1' });
  // продажи
  const buyers = ['Абдуллаев Ш.', 'Иванова Е.', 'Ким В.', 'Рахимова Д.', 'Сидоров П.', 'Хасанов Б.', 'Назарова М.', 'Цой А.', 'Турсунов Ж.', 'Миронова О.', 'Исмоилов Ф.', 'Пак Д.', 'Каримов Р.', 'Ли С.', 'Нурматов А.', 'Ахмедова Г.'];
  genApts(p).forEach(a => {
    const r = R(); if (r > 0.34) return;
    const status = r < 0.24 ? 'sold' : 'booked', o = { status, buyer: pick(buyers), phone: `+998 9${ri(0, 9)} ${ri(100, 999)} ${ri(10, 99)} ${ri(10, 99)}`, plan: pick(['Полная оплата', 'Рассрочка 12 мес', 'Рассрочка 24 мес', 'Ипотека']), payments: [] };
    const amt = status === 'sold' ? (o.plan === 'Полная оплата' || o.plan === 'Ипотека' ? a.price : Math.round(a.price * (0.3 + R() * 0.3) / 1e6) * 1e6) : 10000000;
    const date = addDays(start, ri(40, 215) > 219 ? 215 : ri(40, 215)), id = uid();
    const tx = addTx(p, { date, type: 'in', cat: a.rooms ? 'Продажа квартир' : 'Продажа коммерции', amount: amt, who: o.buyer, desc: `Оплата кв. №${a.num}`, link: { kind: 'apt', id: a.id, payId: id } });
    o.payments.push({ id, date, amount: amt, txId: tx.id }); p.apts[a.id] = o;
  });
  legalDemo(p);
  leadsDemo(p); gpsDemo(p);
  return p;
}
