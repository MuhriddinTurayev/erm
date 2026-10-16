'use strict';
/* ===== ИИ-консультант: автопроверка проекта + чат через сервер (Gemini, ChatGPT, DeepSeek, Claude — подключает администратор платформы) ===== */
const AI = { hist: [], busy: false, view: [], persona: 'general', provider: null };
const aiProviders = () => (window.OFTOB && window.OFTOB.ai) || [];
const aiReady = () => aiProviders().length > 0 && !(window.OFTOB && window.OFTOB.plan && window.OFTOB.plan.ai === false);

/* ---------- Автопроверка (работает без интернета) ---------- */
const ROLE_OF_LAB = { concrete: 'Бетонщик', rebar: 'Арматурщик', carpenter: 'Плотник-опалубщик', welder: 'Сварщик', mason: 'Каменщик (кладчик)', facer: 'Каменщик (кладчик)', electric: 'Электрик', plumber: 'Сантехник', plasterer: 'Штукатур', painter: 'Маляр', tiler: 'Плиточник', roofer: 'Кровельщик', facade: 'Фасадчик', window: 'Монтажник' };
const GROUP_STAGE = { 'Каркас': /каркас|фундамент|подземн/i, 'Стены и фасад': /кладк|фасад|кровл|окн/i, 'Сети и отделка': /электр|водопр|отделк|лифт/i };
function prevMonth() { const d = new Date(today() + 'T00:00:00'); d.setDate(1); d.setMonth(d.getMonth() - 1); return isoDate(d).slice(0, 7); }
function auditCalc(p) {
  const F = [], add = (lvl, sec, page, text, fix) => F.push({ id: F.length, lvl, sec, page, text, fix });
  const t = today(), m = monthNow(), dom = +t.slice(8), pm = prevMonth();
  const act = p.staff.filter(s => s.active !== false), L = labCalc(p);
  const activeSt = p.stages.filter(s => s.start <= t && addDays(s.start, s.dur) > t && s.progress < 100);
  /* --- Работники --- */
  const need = {};
  L.rows.forEach(r => { const re = GROUP_STAGE[r.group]; if (re && activeSt.some(s => re.test(s.name))) { const role = ROLE_OF_LAB[r.key]; need[role] = (need[role] || 0) + r.workers; } });
  const have = {}; act.forEach(s => have[s.role] = (have[s.role] || 0) + 1);
  Object.entries(need).forEach(([role, n]) => { const h = have[role] || 0; if (h < n * 0.7) add(h === 0 ? 'bad' : 'warn', 'Работники', 'labour', `Не хватает: <b>${role}</b> — в штате ${h}, по расчёту на текущие работы нужно ≈ ${n}`, { k: 'hire', role, n: n - h }); });
  const prorabNeed = L.itr.find(i => i.key === 'prorab').count, prorabHave = have['Прораб'] || 0;
  if (prorabHave < prorabNeed) add('warn', 'Работники', 'staff', `Прорабов ${prorabHave}, а при пиковой численности ${L.peak} чел. нужно ${prorabNeed}`, { k: 'hire', role: 'Прораб', n: prorabNeed - prorabHave });
  if (dom > 5) { const zero = act.filter(s => !s.isNew && !earned(p, s, m).h && !(s.hired && daysBetween(s.hired, t) < 7)); if (zero.length) add('warn', 'Работники', 'staff', `Нет часов в табеле за ${monthName(m)}: ${zero.slice(0, 6).map(s => esc(s.name)).join(', ')}${zero.length > 6 ? ' и ещё ' + (zero.length - 6) : ''}`, { k: 'tsfill', ids: zero.map(s => s.id) }); }
  const over = []; act.forEach(s => { const ts = p.ts[s.id] || {}; const n = Object.entries(ts).filter(([d, v]) => d.startsWith(m) && num(v) > 12).length; if (n) over.push(`${esc(s.name)} (${n} дн.)`); });
  if (over.length) add('warn', 'Работники', 'staff', `Переработка больше 12 часов в день (нарушение охраны труда): ${over.slice(0, 6).join(', ')}`, { k: 'capot' });
  const unpaid = act.map(s => ({ s, d: earned(p, s, pm).sum - paidFor(p, s.id, pm) })).filter(x => x.d > 1000);
  if (unpaid.length) add('bad', 'Работники', 'staff', `Не выплачена зарплата за ${monthName(pm)}: ${unpaid.length} сотрудникам на сумму ${money(sum(unpaid, x => x.d))}`, { k: 'payprev' });
  if (act.some(s => !s.rate)) add('bad', 'Работники', 'staff', `Не указана ставка у: ${act.filter(s => !s.rate).map(s => esc(s.name)).join(', ')}`, { k: 'rate' });
  if (!F.some(f => f.sec === 'Работники')) add('ok', 'Работники', 'staff', 'Замечаний по работникам нет');
  /* --- Работы и сроки --- */
  p.stages.forEach(s => {
    const end = addDays(s.start, s.dur);
    if (s.progress < 100 && end < t) add('bad', 'Работы и сроки', 'schedule', `Просрочен этап «${esc(s.name)}»: срок был ${fdate(end)}, выполнено ${s.progress}%`, { k: 'resched', id: s.id });
    else if (s.start <= t && s.progress < 100) { const exp = Math.min(100, daysBetween(s.start, t) / s.dur * 100); if (exp - s.progress > 20) add('warn', 'Работы и сроки', 'schedule', `Этап «${esc(s.name)}» отстаёт: выполнено ${s.progress}%, по графику должно быть ≈ ${fmt(exp)}%`, { k: 'resched', id: s.id }); }
  });
  const C = prepCalc(p), pitSt = p.stages.find(s => /котлован/i.test(s.name));
  if (!C.ready) add(pitSt && pitSt.start <= t ? 'bad' : 'warn', 'Работы и сроки', 'prep', `${T(pitSt && pitSt.start <= t ? 'Котлован уже начат, но не выполнены обязательные подготовительные работы' : 'Не выполнены обязательные подготовительные работы')}: ${C.crit.filter(r => r.status !== 'done').map(r => esc(T(r.name))).join('; ')}`);
  geoCalc(p).notes.filter(n => n[0] !== 'ok').forEach(n => add(n[0], 'Геология и фундамент', 'geology', n[1], n[2] ? { k: n[2] } : null));
  if (!F.some(f => f.sec === 'Работы и сроки')) add('ok', 'Работы и сроки', 'schedule', 'Все этапы идут по графику');
  /* --- Деньги --- */
  const S = smetaCalc(p), pr = progress(p);
  S.lines.forEach(l => { if (l.plan > 0 && l.fact > l.plan) add('bad', 'Деньги', 'smeta', `Перерасход по статье «${esc(l.cat)}»: факт ${moneyS(l.fact)} больше сметы ${moneyS(l.plan)}`); });
  const spent = S.total ? S.fact / S.total * 100 : 0;
  if (spent > pr + 15) add('warn', 'Деньги', 'smeta', `Освоено ${fmt(spent, 1)}% бюджета, а готовность объекта только ${fmt(pr, 1)}%`);
  if (S.F.bal < 0) add('bad', 'Деньги', 'accounting', `Касса в минусе: ${moneyS(S.F.bal)}`);
  const A = genApts(p), debt = sum(A.filter(a => a.status === 'sold'), a => Math.max(0, a.price - a.paid));
  let overdue = 0, odN = 0;
  A.filter(a => a.status === 'sold').forEach(a => {
    const pays = (a.o.payments || []).slice().sort((x, y) => x.date.localeCompare(y.date)); if (!pays.length) { overdue += a.price; odN++; return; }
    const mo = parseInt(String(a.o.plan || '').replace(/\D/g, '')) || 0, first = pays[0], el = daysBetween(first.date, t);
    const exp = mo ? Math.min(a.price, first.amount + (a.price - first.amount) / mo * Math.floor(el / 30.4)) : (el > 30 ? a.price : first.amount);
    if (exp - a.paid > 1000) { overdue += exp - a.paid; odN++; }
  });
  if (overdue > 0) add('warn', 'Деньги', 'sales', `Просрочены платежи покупателей: ${odN} кв. на ${moneyS(overdue)} — свяжитесь с покупателями`);
  else if (debt > 0) add('ok', 'Деньги', 'sales', `Покупатели платят по графику, остаток по рассрочкам ${moneyS(debt)}`);
  if (!F.some(f => f.sec === 'Деньги')) add('ok', 'Деньги', 'smeta', 'Перерасходов нет');
  /* --- Техника --- */
  p.fleet.forEach(v => { const st = vehStats(p, v, ''); if (st.n && st.devPct > 10) add('warn', 'Техника', 'fleet', `Перерасход топлива: ${esc(v.name)} +${fmt(st.devPct, 1)}% (${fmt(st.dev)} л сверх нормы)`); if (!v.driver) add('warn', 'Техника', 'fleet', `Не назначен водитель: ${esc(v.name)}`, { k: 'driver', vid: v.id }); });
  if (!F.some(f => f.sec === 'Техника')) add('ok', 'Техника', 'fleet', 'Расход топлива в пределах нормы');
  safetyAudit(p, add); legalAudit(p, add); leadsAudit(p, add); gpsAudit(p, add);
  const bad = F.filter(f => f.lvl === 'bad').length, warn = F.filter(f => f.lvl === 'warn').length;
  return { F, bad, warn, score: Math.max(0, 100 - bad * 10 - warn * 4) };
}

/* ---------- Автоисправление замечаний ---------- */
let AU_LAST = [];
function roleRate(p, role) { const r = p.staff.filter(s => s.role === role && s.rate); return r.length ? Math.round(sum(r, s => s.rate) / r.length) : (ITR_ROLES.has(role) ? 60000 : 30000); }
function hire(p, role, n) { const base = p.staff.filter(s => s.role === role).length, out = []; for (let i = 1; i <= n; i++) { const s = { id: uid(), name: `${role} — новый ${base + i}`, role, rate: roleRate(p, role), phone: '', active: true, hired: today(), isNew: true }; p.staff.push(s); out.push(s); } return out; }
function applyFix(p, fx) {
  const t = today(), m = monthNow();
  switch (fx.k) {
    case 'hire': hire(p, fx.role, fx.n); return `Добавлено в штат: ${fx.role} × ${fx.n}`;
    case 'tsfill': { let n = 0; fx.ids.forEach(id => { const ts = p.ts[id] = p.ts[id] || {}; for (let d = 1; d <= 31; d++) { const ds = `${m}-${String(d).padStart(2, '0')}`; if (ds > t) break; if (new Date(ds + 'T00:00:00').getDay() === 0 || ts[ds] != null) continue; ts[ds] = DB.settings.normHours; n++; } }); return `Табель заполнен: ${n} рабочих дней`; }
    case 'capot': { let n = 0; for (const id in p.ts) for (const ds in p.ts[id]) if (ds.startsWith(m) && num(p.ts[id][ds]) > 12) { p.ts[id][ds] = 12; n++; } return `Смены больше 12 ч сокращены до 12 ч: ${n}`; }
    case 'payprev': {
      const pm = prevMonth(); let n = 0, total = 0;
      p.staff.filter(s => s.active !== false).forEach(s => {
        const due = Math.round(earned(p, s, pm).sum - paidFor(p, s.id, pm)); if (due <= 1000) return;
        const id = uid(), tx = addTx(p, { date: t, type: 'out', cat: ITR_ROLES.has(s.role) ? 'Зарплата ИТР' : 'Зарплата рабочих', amount: due, who: s.name, desc: `Зарплата за ${monthName(pm)}`, link: { kind: 'pay', id } });
        p.pays.push({ id, staffId: s.id, month: pm, date: t, amount: due, txId: tx.id }); n++; total += due;
      });
      return `Выплачена зарплата ${n} сотрудникам: ${money(total)}`;
    }
    case 'rate': p.staff.filter(s => !s.rate).forEach(s => s.rate = roleRate(p, s.role)); return 'Ставки проставлены по средним для профессии';
    case 'resched': {
      const s = p.stages.find(x => x.id === fx.id); if (!s) return '';
      const el = Math.max(1, daysBetween(s.start, t)), pace = s.progress > 0 ? s.progress / el : 100 / s.dur / 2;
      const rem = Math.min(s.dur * 3, Math.max(7, Math.ceil((100 - s.progress) / pace)));
      s.dur = el + rem; return `График этапа «${s.name}» пересчитан по фактическому темпу: окончание ${fdate(addDays(s.start, s.dur))}`;
    }
    case 'collapse': p.geo.collapseFix = true; return 'Добавлено устранение просадочности: грунтовая подушка под плитой (в подготовительных работах)';
    case 'seismic': p.geo.seismicFix = true; return 'Учтены антисейсмические мероприятия: армирование +12%';
    case 'piles': p.geo.piles = true; return 'Принят свайный фундамент (сваи добавлены в подготовительные работы)';
    case 'claims': return `Создано претензий покупателям: ${createClaims(p)}`;
    case 'driver': {
      const v = p.fleet.find(x => x.id === fx.vid); if (!v) return '';
      const busy = new Set(p.fleet.map(x => x.driver).filter(Boolean));
      let d = p.staff.find(s => s.active !== false && /Водитель|Машинист|Крановщик/.test(s.role) && !busy.has(s.name));
      if (!d) d = hire(p, v.type === 'Экскаватор' ? 'Машинист экскаватора' : v.type === 'Самосвал' ? 'Водитель самосвала' : 'Водитель', 1)[0];
      v.driver = d.name; return `Водитель «${d.name}» назначен на ${v.name}`;
    }
  }
  return '';
}
ACT.aiFix = d => { const f = AU_LAST[+d.i]; if (!f || !f.fix) return; const msg = applyFix(P(), f.fix); save(); rerender(); toast('✅ ' + msg); };
ACT.aiFixAll = () => {
  const p = P(), fixes = auditCalc(p).F.filter(f => f.fix); if (!fixes.length) return toast('Нечего исправлять автоматически');
  fixes.forEach(f => applyFix(p, f.fix)); save(); const a = auditCalc(p); rerender();
  toast(`✅ Исправлено замечаний: ${fixes.length}. Оценка проекта: ${a.score}/100`);
};

/* ---------- Данные проекта для ИИ ---------- */
function aiContext(p) {
  const B = bld(p), pit = pitCalc(p), G = geoCalc(p), C = prepCalc(p), L = labCalc(p), S = smetaCalc(p), A = genApts(p), Au = auditCalc(p), m = monthNow();
  const strip = s => String(s).replace(/<[^>]+>/g, '');
  const roles = {}; p.staff.filter(s => s.active !== false).forEach(s => { const e = earned(p, s, m); const r = roles[s.role] = roles[s.role] || { n: 0, h: 0, sum: 0, paid: 0 }; r.n++; r.h += e.h; r.sum += e.sum; r.paid += paidFor(p, s.id, m); });
  const L2 = [];
  L2.push(`Компания: ${DB.settings.company}. Валюта: ${cur()}. Сегодня: ${fdate(today())}.`);
  L2.push(`ПРОЕКТ: ${p.name}, ${p.address}, статус «${p.status}», начало ${fdate(p.startDate)}.`);
  L2.push(`ЗДАНИЕ: ${p.b.floors} надземных + ${p.b.underground} подземных этажей, ${p.b.sections} подъезда, ${p.b.length}×${p.b.width} м, высота этажа ${p.b.floorH} м. Площадь участка ${fmt(p.b.plot)} м², застройки ${fmt(B.fp)} м², общая ${fmt(B.total)} м², продаваемая ${fmt(B.sellable)} м², квартир ${B.apts}.`);
  L2.push(`ГЕОЛОГИЯ: УГВ ${p.geo.gwl} м, сейсмичность ${p.geo.seismic} баллов. Слои: ${G.layers.map(l => `${l.P.name} ${fmt(l.top, 1)}–${fmt(l.bottom, 1)} м (E=${l.P.E} МПа, R0=${l.P.R0} кПа)`).join('; ')}. Давление под плитой ${fmt(G.pGross)} кПа, R=${fmt(G.R)} кПа (${fmt(G.util)}%), осадка ≈ ${fmt(G.settle, 1)} см.`);
  L2.push(`КОТЛОВАН: ${p.pit.length}×${p.pit.width}×${p.pit.depth} м, откос 1:${p.pit.slope}; грунт ${fmt(pit.V)} м³, вывоз ${fmt(pit.Vloose)} м³, ${pit.trips} рейсов, экскаваторов ${p.pit.excCount}, нужно самосвалов ${pit.trucks}, срок ${pit.days} дн., топливо ${fmt(pit.fuel)} л, стоимость ${money(pit.total)}.`);
  L2.push(`ПОДГОТОВКА ПЛОЩАДКИ: ${money(C.total)}, выполнено ${C.done} из ${C.count}. Не выполнено: ${C.rows.filter(r => r.qty > 0 && r.status !== 'done').map(r => r.name).join('; ') || 'всё выполнено'}.`);
  L2.push(`ГРАФИК (готовность ${fmt(progress(p), 1)}%): ${p.stages.map(s => `${s.name} ${fdate(s.start)}–${fdate(addDays(s.start, s.dur))} ${s.progress}%`).join('; ')}.`);
  L2.push(`ПОТРЕБНОСТЬ В РАБОЧИХ (расчёт): ${L.rows.map(r => `${r.name} ${r.workers} (объём ${fmt(r.qty)} ${r.unit}, выработка ${r.norm}/смена, срок ${r.days} дн.)`).join('; ')}; разнорабочих ${L.helpers.workers}, крановщиков ${L.crane.count}; пик ≈ ${L.peak} чел.; ИТР ${L.itrCount}.`);
  L2.push(`ШТАТ (${monthName(m)}): ${Object.entries(roles).map(([r, v]) => `${r}: ${v.n} чел., ${fmt(v.h)} ч, начислено ${money(v.sum)}, выплачено ${money(v.paid)}`).join('; ')}.`);
  L2.push(`ТЕХНИКА: ${p.fleet.map(v => { const s = vehStats(p, v, ''); return `${v.name} (${v.type}, водитель ${v.driver || 'нет'}, норма ${v.norm} ${v.mode === 'h' ? 'л/ч' : 'л/100км'}, факт ${fmt(s.liters)} л при норме ${fmt(s.normL)} л)`; }).join('; ')}.`);
  L2.push(`СМЕТА: себестоимость ${money(S.total)} (${money(S.perM2)}/м²), выручка план ${money(S.revenue)}, прибыль ${money(S.profit)} (${fmt(S.margin, 1)}%). Статьи план/факт: ${S.lines.map(l => `${l.cat} ${money(l.plan)}/${money(l.fact)}`).join('; ')}. Касса: поступило ${money(S.F.inc)}, потрачено ${money(S.F.out)}, баланс ${money(S.F.bal)}.`);
  L2.push(`ПРОДАЖИ: ${A.length} помещений, продано ${A.filter(a => a.status === 'sold').length}, бронь ${A.filter(a => a.status === 'booked').length}, получено оплат ${money(sum(A, a => a.paid))}, цена ${money(p.b.pricePerM2)}/м².`);
  { const sc = safetyCalc(p), lc = legalCalc(p); L2.push(`ОХРАНА ТРУДА: без травм ${sc.daysSafe} дн.; без вводного/первичного инструктажа ${sc.noIntro.length} чел.; повторный просрочен ${sc.late.length}; нет/просрочены удостоверения ${sc.certBad.length}; обеспеченность СИЗ ${fmt(sc.ppePct)}%; открытые происшествия: ${sc.openInc.map(i => i.desc).join('; ') || 'нет'}; действующих нарядов-допусков ${sc.activePermits.length}.`); L2.push(`ЮРИДИЧЕСКОЕ: открытые дела: ${lc.open.map(c => `${c.side} «${c.party}»: ${c.subject}, ${money(c.amount)}, стадия ${c.stage}, срок ${fdate(c.deadline)}`).join('; ') || 'нет'}; договоров ${p.legal.contracts.length}, истекают в 30 дней: ${lc.ctrSoon.map(c => c.party).join(', ') || 'нет'}; просроченных оплат покупателей ${salesOverdue(p).length}.`); }
  L2.push(`АВТОПРОВЕРКА (оценка ${Au.score}/100): ${Au.F.filter(f => f.lvl !== 'ok').map(f => `[${f.lvl === 'bad' ? 'КРИТИЧНО' : 'ВНИМАНИЕ'}] ${strip(f.text)}`).join(' | ') || 'замечаний нет'}.`);
  return L2.join('\n');
}
const AI_LANG_NAME = { ru: 'русском', uz: "узбекском (латиница, O'zbek tili)", tg: 'таджикском (кириллица, забони тоҷикӣ)' };
function aiSystem(p) {
  if (AI.persona && AI.persona !== 'general') return aiPersonaSystem(p);
  return `Ты — ИИ-консультант строительной CRM «${APP_NAME}». Ты опытный главный инженер, прораб, сметчик и бухгалтер в строительстве многоэтажных жилых домов (монолит, кладка) от котлована до продажи квартир, знаешь строительные нормы (СНиП/ШНК, СП), охрану труда и практику Узбекистана и Таджикистана.
Твоя задача: консультировать, проверять работы, сроки, работников, зарплату, технику, расходы, геологию и продажи по данным проекта ниже, находить ошибки и риски и давать конкретные практические рекомендации: что сделать, кому, в какой срок, с цифрами.
Отвечай на ${AI_LANG_NAME[DB.settings.lang] || 'русском'} языке. Пиши по делу, структурно: короткие заголовки и списки. Ссылайся на конкретные цифры из данных. Если данных не хватает — так и скажи, какие нужны. Не выдумывай факты, которых нет в данных. Важные инженерные решения (фундамент, несущие конструкции) — рекомендуй подтверждать расчётом конструктора.

ДАННЫЕ ПРОЕКТА:
${aiContext(p)}
${aiCompanyContext()}`;
}

/* деньги по всей компании — чтобы ИИ отвечал «сколько ушло сегодня / вчера / на что» */
function aiCompanyContext() {
  if (typeof seesMoney === 'function' && !seesMoney()) return '';
  const t = today(), y = addDays(t, -1), mf = monthNow() + '-01', c = cur();
  const lines = DB.projects.map(p => {
    const F = finance(p); let dT = 0, dY = 0, mo = 0; const cats = {};
    p.tx.forEach(x => { if (x.type !== 'out') return; if (x.date === t) dT += x.amount; if (x.date === y) dY += x.amount; if (x.date >= mf && x.date <= t) { mo += x.amount; cats[x.cat] = (cats[x.cat] || 0) + x.amount; } });
    const top = Object.entries(cats).sort((a, b) => b[1] - a[1]).slice(0, 4).map(([k, v]) => `${k} ${Math.round(v)}`).join('; ');
    return `- ${p.name}: касса ${Math.round(F.bal)} ${c}; расход сегодня ${Math.round(dT)}, вчера ${Math.round(dY)}, за месяц ${Math.round(mo)}; главные статьи месяца: ${top || 'нет'}; готовность ${Math.round(progress(p))}%`;
  });
  const todayOps = []; DB.projects.forEach(p => p.tx.forEach(x => { if (x.date === t || x.date === y) todayOps.push(`${x.date} ${p.name}: ${x.type === 'in' ? '+' : '-'}${Math.round(x.amount)} ${x.cat} — ${x.desc || ''} ${x.who ? '(' + x.who + ')' : ''}`); }));
  return `\nВСЯ КОМПАНИЯ «${DB.settings.company}» (сегодня ${t}), объекты:\n${lines.join('\n')}\nОперации за сегодня и вчера:\n${todayOps.slice(0, 80).join('\n') || 'нет'}`;
}

/* ---------- Мини-markdown ---------- */
function mdLite(s) {
  const lines = esc(s).split('\n'); let html = '', list = false;
  const inl = x => x.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>');
  lines.forEach(l => {
    const li = /^\s*(?:[-*•]|\d+[.)])\s+(.*)/.exec(l);
    if (li) { if (!list) { html += '<ul>'; list = true; } html += `<li>${inl(li[1])}</li>`; return; }
    if (list) { html += '</ul>'; list = false; }
    const h = /^#{1,4}\s+(.*)/.exec(l);
    if (h) html += `<h4>${inl(h[1])}</h4>`; else if (l.trim()) html += `<p>${inl(l)}</p>`;
  });
  if (list) html += '</ul>';
  return html;
}

/* ---------- Страница ---------- */
const AI_QUICK = ['Проверь все работы и сроки: что отстаёт и что делать?', 'Проверь работников: хватает ли людей, табель, зарплата, переработки', 'Где перерасход денег и как сэкономить?', 'Проверь геологию и фундамент: какие риски?', 'Что сделать в первую очередь на этой неделе? Дай план по дням', 'Проверь технику и расход топлива'];
const fixBtn = Au => { const n = Au.F.filter(f => f.fix).length; return n ? `<button class="btn-s btn-p" data-act="aiFixAll">🛠 Исправить все (${n})</button>` : ''; };
const aiColor = s => s >= 80 ? 'var(--ok)' : s >= 55 ? 'var(--warn)' : 'var(--bad)';
const auditRow = f => `<div class="audit a-${f.lvl}"><span>${f.lvl === 'ok' ? '✅' : f.lvl === 'warn' ? '⚠️' : '❌'}</span><div>${f.text}</div><span class="audit-btns">${f.fix ? `<button class="btn-s btn-p" data-act="aiFix" data-i="${f.id}">🛠 Исправить</button>` : ''}<button class="btn-s" data-act="go" data-page="${f.page}">Открыть</button></span></div>`;
function aiHeadHtml(Au) {
  return `<div class="ai-head">
      <div class="ai-score" style="--c:${aiColor(Au.score)}"><b>${Au.score}</b><span>из 100</span></div>
      <div><h2>🤖 ИИ-консультант</h2><p class="muted">Автоматически проверяет работы, сроки, работников, зарплату, деньги, технику и геологию. Задайте любой вопрос — ИИ ответит по данным вашего проекта.</p>
      <div class="flex"><span class="pill p-sold">критично: ${Au.bad}</span><span class="pill p-booked">внимание: ${Au.warn}</span><span class="pill p-free">в порядке: ${Au.F.filter(f => f.lvl === 'ok').length}</span></div></div>
    </div>`;
}
function aiChatHtml() {
  const pr = aiProviders(), pl = window.OFTOB && window.OFTOB.plan;
  const sel = AI.provider || (window.OFTOB && window.OFTOB.aiDefault) || '';
  const note = !pr.length ? `<div class="warnbox">ИИ ещё не подключён администратором платформы. Автопроверка слева работает и без него.</div>`
    : pl && pl.ai === false ? `<div class="warnbox">ИИ не входит в ваш тариф. <a href="/company/billing">Выбрать тариф</a></div>` : '';
  return `<div class="card ai-chat"><h3>Чат с консультантом <span class="tools">${aiPersonaTabs()}${AI.hist.length ? '<button class="btn-s" data-act="aiClear">Новый разговор</button>' : ''}</span></h3>
        ${note}
        <div class="ai-msgs" id="aiMsgs">${aiMsgsHtml()}</div>
        <div class="ai-quick">${AI_QUICK.map((q, i) => `<button class="btn-s" data-act="aiQuick" data-i="${i}">${q}</button>`).join('')}</div>
        <div class="ai-input"><textarea id="aiIn" rows="2" placeholder="Спросите консультанта… (Enter — отправить, Shift+Enter — новая строка)"></textarea><button class="btn-p" data-act="aiSend" id="aiSendBtn"${AI.busy || !aiReady() ? ' disabled' : ''}>Отправить</button></div>
        ${pr.length ? `<div class="ai-foot small muted">${pr.length > 1 ? `ИИ: <select id="aiProv">${opts([['', 'по умолчанию'], ...pr.map(x => [x.id, x.name])], sel)}</select>` : `ИИ: <b class="notr">${esc(pr[0].name)}</b>`}
          ${pl && pl.aiLimit ? ` · <span id="aiUsage">запросов в этом месяце: ${pl.aiUsed} из ${pl.aiLimit}</span>` : ''}</div>` : ''}
      </div>`;
}
/* блок консультанта для главной страницы */
function aiHomeHtml(p) {
  const Au = auditCalc(p); AU_LAST = Au.F; const top = Au.F.filter(f => f.lvl === 'bad').concat(Au.F.filter(f => f.lvl === 'warn')).slice(0, 6);
  return `<section class="ai-home">
    ${aiHeadHtml(Au)}
    <div class="grid g2">
      <div class="card"><h3>Главные замечания <span class="tools">${fixBtn(Au)}<button class="btn-s" data-act="aiAskAudit">🤖 Разобрать с ИИ</button><button class="btn-s" data-act="go" data-page="ai">Все замечания (${Au.bad + Au.warn}) →</button></span></h3>
        ${top.map(auditRow).join('') || '<div class="audit a-ok"><span>✅</span><div>Замечаний нет — проект в порядке</div><span></span></div>'}
      </div>
      ${aiChatHtml()}
    </div></section>`;
}
PAGES.ai = {
  title: 'ИИ-консультант',
  render(p) {
    const Au = auditCalc(p), secs = [...new Set(Au.F.map(f => f.sec))]; AU_LAST = Au.F;
    return `${aiHeadHtml(Au)}
    <div class="grid g2">
      <div class="card"><h3>Автопроверка проекта <span class="tools">${fixBtn(Au)}<button class="btn-s" data-act="aiAskAudit">🤖 Разобрать с ИИ</button></span></h3>
        ${secs.map(sec => `<h4>${sec}</h4>${Au.F.filter(f => f.sec === sec).map(auditRow).join('')}`).join('')}
      </div>
      ${aiChatHtml()}
    </div>`;
  },
  mounted() { const m = $('#aiMsgs'); if (m) m.scrollTop = m.scrollHeight; }
};function aiMsgsHtml() {
  if (!AI.view.length) return `<div class="ai-empty">👋 Здравствуйте! Я консультант по строительству. Выберите вопрос ниже или напишите свой — например, «проверь бригады бетонщиков» или «сколько ещё нужно денег до сдачи дома».</div>`;
  return AI.view.map(v => `<div class="ai-msg ${v.role} notr">${v.role === 'user' ? esc(v.text) : (v.html ?? mdLite(v.text)) || '<span class="ai-dots">●●●</span>'}</div>`).join('');
}
function aiRefresh() { const m = $('#aiMsgs'); if (!m) return; m.innerHTML = aiMsgsHtml(); m.scrollTop = m.scrollHeight; const b = $('#aiSendBtn'); if (b) b.disabled = AI.busy; }
async function aiAsk(text, persona) {
  text = (text || '').trim(); if (!text || AI.busy) return;
  if (persona && persona !== AI.persona) { AI.persona = persona; AI.hist = []; AI.view = []; }
  if (!aiReady()) { toast(aiProviders().length ? 'ИИ не входит в ваш тариф' : 'ИИ ещё не подключён администратором платформы'); return; }
  if (curPage !== 'ai' && curPage !== 'home') go('ai');
  AI.busy = true; AI.hist.push({ role: 'user', content: text }); AI.view.push({ role: 'user', text });
  const out = { role: 'assistant', text: '' }; AI.view.push(out); aiRefresh();
  const ps = $('#aiProv'); if (ps) AI.provider = ps.value || null;
  try {
    const r = await fetch('/api/ai/chat', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ system: aiSystem(P()), messages: AI.hist.slice(-30), provider: AI.provider ? +AI.provider : null }) });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || j.message || ('HTTP ' + r.status));
    out.text = j.text || '…';
    AI.hist.push({ role: 'assistant', content: out.text });
    if (window.OFTOB && window.OFTOB.plan && j.limit) { window.OFTOB.plan.aiUsed = j.used; const u = $('#aiUsage'); if (u) u.textContent = T('запросов в этом месяце') + ': ' + j.used + ' ' + T('из') + ' ' + j.limit; }
  } catch (e) {
    AI.hist.pop();
    out.html = `<p class="bad">${esc(e.message === 'Failed to fetch' ? T('Нет связи с сервером. Проверьте интернет.') : e.message)}</p>`;
  }
  AI.busy = false; aiRefresh();
}
ACT.aiSend = () => { const i = $('#aiIn'); const v = i.value; i.value = ''; aiAsk(v); };
ACT.aiQuick = d => aiAsk(T(AI_QUICK[+d.i]));
ACT.aiAskAudit = () => aiAsk(T('Разбери результаты автопроверки проекта: объясни каждую проблему, насколько она серьёзна, и дай пошаговый план исправления с ответственными и сроками.'));
ACT.aiClear = () => { AI.hist = []; AI.view = []; rerender(); };
ACT.aiOpen = () => go('ai');
document.addEventListener('keydown', e => { if (e.target.id === 'aiIn' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ACT.aiSend(); } });

/* ---------- Роли консультанта ---------- */
const AI_PERSONAS = [['general', '🏗 Стройка'], ['lawyer', '⚖️ Юрист'], ['safety', '🦺 Охрана труда']];
function aiPersonaTabs() { return AI_PERSONAS.map(([k, l]) => `<button class="btn-s${AI.persona === k ? ' btn-p' : ''}" data-act="aiPersona" data-p="${k}">${l}</button>`).join(''); }
ACT.aiPersona = d => { if (AI.persona === d.p) return; AI.persona = d.p; AI.hist = []; AI.view = []; rerender(); };
ACT.aiAskPersona = d => aiAsk(T(d.q), d.persona);
function aiPersonaSystem(p) {
  const lang = AI_LANG_NAME[DB.settings.lang] || 'русском';
  const role = AI.persona === 'lawyer'
    ? `Ты — опытный юрист строительной компании «${DB.settings.company}»: договоры подряда, поставки и аренды, продажа квартир и рассрочка, долевое строительство, трудовые споры, претензионная работа и суды, споры с госорганами и соседями. Ориентируешься на законодательство Узбекистана и Таджикистана (Гражданский кодекс, Трудовой кодекс, Градостроительный кодекс, процессуальные кодексы). Не выдумывай номера статей, если не уверен — называй кодекс и суть нормы. Давай практичные шаги: что сделать, в какие сроки, какие доказательства и документы собрать, как вести переговоры, когда идти в суд. Помогай составлять претензии, ответы, уведомления. В конце важных ответов напоминай, что окончательное решение и подача в суд — с лицензированным адвокатом.`
    : `Ты — инженер по охране труда и промышленной безопасности на строительстве многоэтажных домов: инструктажи, допуски и удостоверения, работы на высоте, леса и подмости, краны и стропальные работы, земляные работы и котлованы, электробезопасность, огневые работы, СИЗ, наряды-допуски, расследование несчастных случаев, требования инспекции труда Узбекистана и Таджикистана. Находи нарушения по данным, оценивай риск для жизни людей, давай конкретные меры: что запретить немедленно, что исправить, кто отвечает, в какой срок, какие записи сделать в журналах.`;
  return `${role}
Отвечай на ${lang} языке, структурно, по делу, с опорой на данные проекта. Не выдумывай факты.

ДАННЫЕ ПРОЕКТА:
${aiContext(p)}`;
}
