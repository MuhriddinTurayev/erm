'use strict';
/* ===== Лиды: обращения покупателей от заявки до сделки ===== */
const LEAD_ST = [['new', 'Новые', '#64748b'], ['contact', 'Связались', '#2563eb'], ['meet', 'Встреча назначена', '#7c3aed'], ['shown', 'Показ проведён', '#d97706'], ['booked', 'Бронь / задаток', '#ca8a04'], ['deal', 'Сделка', '#16a34a'], ['lost', 'Отказ', '#dc2626']];
const LEAD_NAME = Object.fromEntries(LEAD_ST.map(x => [x[0], x[1]])), LEAD_ORDER = LEAD_ST.map(x => x[0]);
const LEAD_SRC = ['Instagram', 'Facebook', 'WhatsApp', 'Telegram', 'OLX', 'Сайт', 'Звонок', 'Офис продаж', 'Рекомендация', 'Баннер / реклама'];
const LOST_WHY = ['Дорого', 'Не подошёл этаж или планировка', 'Купил у конкурента', 'Не одобрили ипотеку', 'Передумал', 'Не отвечает'];
const ACTIVE_ST = new Set(['new', 'contact', 'meet', 'shown', 'booked']);
function leadsCalc(p) {
  const L = p.leads, t = today(), wk = addDays(t, -7);
  const active = L.filter(l => ACTIVE_ST.has(l.status)), deals = L.filter(l => l.status === 'deal'), lost = L.filter(l => l.status === 'lost');
  const due = active.filter(l => l.nextDate && l.nextDate <= t), late = active.filter(l => l.nextDate && l.nextDate < t);
  const stale = L.filter(l => l.status === 'new' && daysBetween(l.created, t) >= 1);
  const closed = deals.length + lost.length;
  return { active, deals, lost, due, late, stale, newWeek: L.filter(l => l.created >= wk).length, conv: L.length ? deals.length / L.length * 100 : 0, winRate: closed ? deals.length / closed * 100 : 0, dealSum: sum(deals, l => (genApts(p).find(a => a.id === l.aptId) || {}).price || l.budget * 1e6 || 0) };
}
function leadsAudit(p, add) {
  const C = leadsCalc(p);
  if (C.stale.length) add('warn', 'Продажи и лиды', 'leads', `Новые заявки без ответа больше суток: ${C.stale.slice(0, 5).map(l => esc(l.name)).join(', ')}${C.stale.length > 5 ? ' и ещё ' + (C.stale.length - 5) : ''} — позвоните сегодня`);
  if (C.late.length) add('warn', 'Продажи и лиды', 'leads', `Просрочены звонки и встречи с клиентами: ${C.late.length}`);
  if (!C.stale.length && !C.late.length) add('ok', 'Продажи и лиды', 'leads', `Все клиенты в работе · активных лидов ${C.active.length}`);
}
const leadWant = l => [l.rooms ? l.rooms + '-комн.' : '', l.budget ? 'до ' + fmt(l.budget) + ' млн' : '', l.floor || '', l.side === 'south' ? 'окна во двор' : l.side === 'north' ? 'окна на улицу' : ''].filter(Boolean).join(' · ');
const telHref = ph => 'tel:' + String(ph || '').replace(/[^\d+]/g, ''), waHref = ph => 'https://wa.me/' + String(ph || '').replace(/\D/g, '');
function leadCard(l) {
  const t = today(), late = l.nextDate && l.nextDate < t && ACTIVE_ST.has(l.status), todayDue = l.nextDate === t;
  const i = LEAD_ORDER.indexOf(l.status), next = ACTIVE_ST.has(l.status) && l.status !== 'booked' ? LEAD_ORDER[i + 1] : null;
  return `<div class="kcard lead-card${l.unread ? ' unread' : ''}" data-act="leadOpen" data-id="${l.id}"><div class="flex" style="justify-content:space-between"><b>${l.unread ? '<i class="dot"></i>' : ''}${esc(l.name)}</b><span class="src">${l.ch && CH[l.ch] ? CH[l.ch][0] + ' ' : ''}${esc(l.src)}</span></div>
    <div class="small">${esc(l.phone || (l.igUser ? '@' + l.igUser : ''))}</div>${l.unread && l.log && l.log.length ? `<div class="small lead-msg notr">${esc(l.log[l.log.length - 1].t.slice(0, 90))}</div>` : ''}${leadWant(l) ? `<div class="small muted">${esc(leadWant(l))}</div>` : ''}
    ${l.nextText && ACTIVE_ST.has(l.status) ? `<div class="small ${late ? 'bad' : todayDue ? 'warn-t' : 'muted'}">⏰ ${fdate(l.nextDate)} — ${esc(l.nextText)}</div>` : ''}
    ${l.status === 'lost' && l.why ? `<div class="small bad">✕ ${esc(l.why)}</div>` : ''}${l.manager ? `<div class="small muted">👤 ${esc(l.manager)}</div>` : ''}
    <div class="kbtns">${next ? `<button class="btn-s" data-act="leadMove" data-id="${l.id}" data-st="${next}">→ ${LEAD_NAME[next]}</button>` : ''}${ACTIVE_ST.has(l.status) ? `<button class="btn-s" data-act="leadPick" data-id="${l.id}">🔎</button><button class="btn-s btn-d" data-act="leadLost" data-id="${l.id}">✕</button>` : ''}</div></div>`;
}
PAGES.leads = {
  title: 'Лиды: заявки и клиенты',
  render(p, a) {
    const C = leadsCalc(p), tab = a.tab || 'board', q = (a.q || '').toLowerCase(), src = a.src || '';
    const list = p.leads.filter(l => (!q || (l.name + ' ' + l.phone + ' ' + (l.note || '')).toLowerCase().includes(q)) && (!src || l.src === src));
    const tabs = [['board', '🗂 Воронка'], ['tasks', `⏰ Задачи (${C.due.length})`], ['list', '📋 Список'], ['stats', '📊 Аналитика'], ['connect', '🔌 Подключения']];
    let body = '';
    if (tab === 'board') body = `<div class="kanban leads-kanban">${LEAD_ST.map(([st, t, col]) => { const ls = list.filter(l => l.status === st).sort((x, y) => (x.nextDate || '9').localeCompare(y.nextDate || '9')); return `<div class="kcol"><div class="kcol-h" style="border-color:${col}">${t} <span>${ls.length}</span></div>${ls.map(leadCard).join('') || '<div class="small muted" style="padding:8px">—</div>'}</div>`; }).join('')}</div>`;
    if (tab === 'tasks') { const ds = C.due.slice().sort((x, y) => x.nextDate.localeCompare(y.nextDate));
      body = `<div class="card"><h3>Что сделать сегодня</h3>${ds.length ? ds.map(l => `<div class="audit a-${l.nextDate < today() ? 'bad' : 'warn'}"><span>${l.nextDate < today() ? '❗' : '⏰'}</span><div><b>${esc(l.name)}</b> · ${esc(l.phone)} · ${LEAD_NAME[l.status]}<div class="small">${fdate(l.nextDate)} — ${esc(l.nextText || 'связаться')}</div></div><span class="audit-btns"><a class="btn btn-s" href="${telHref(l.phone)}">📞</a><a class="btn btn-s" href="${waHref(l.phone)}" target="_blank" rel="noopener">💬</a><button class="btn-s btn-p" data-act="leadOpen" data-id="${l.id}">Открыть</button></span></div>`).join('') : '<p class="muted">На сегодня задач нет 👍</p>'}</div>`; }
    if (tab === 'list') body = `<div class="card"><div class="tbl-wrap"><table><thead><tr><th>Дата</th><th>Клиент</th><th>Телефон</th><th>Источник</th><th>Ищет</th><th>Этап</th><th>Следующее действие</th><th>Менеджер</th></tr></thead><tbody>
      ${list.slice().sort((x, y) => y.created.localeCompare(x.created)).map(l => `<tr data-act="leadOpen" data-id="${l.id}" style="cursor:pointer"><td class="small">${fdate(l.created)}</td><td><b>${esc(l.name)}</b></td><td>${esc(l.phone)}</td><td>${esc(l.src)}</td><td class="small">${esc(leadWant(l))}</td><td><span class="pill" style="background:${LEAD_ST.find(x => x[0] === l.status)[2]}22;color:${LEAD_ST.find(x => x[0] === l.status)[2]}">${LEAD_NAME[l.status]}</span></td><td class="small ${l.nextDate && l.nextDate < today() && ACTIVE_ST.has(l.status) ? 'bad' : ''}">${l.nextDate ? fdate(l.nextDate) + ' — ' + esc(l.nextText || '') : '—'}</td><td class="small">${esc(l.manager || '')}</td></tr>`).join('') || '<tr><td colspan="8" class="muted">Лидов нет</td></tr>'}
      </tbody></table></div></div>`;
    if (tab === 'connect') body = `<div id="cnWrap">${connectHtml()}</div>`;
    if (tab === 'stats') {
      const bySrc = LEAD_SRC.map(s => { const ls = p.leads.filter(l => l.src === s); return { s, n: ls.length, d: ls.filter(l => l.status === 'deal').length }; }).filter(x => x.n).sort((x, y) => y.n - x.n), mx = Math.max(1, ...bySrc.map(x => x.n));
      const funnel = LEAD_ORDER.filter(s => s !== 'lost').map((s, i, arr) => ({ s, n: p.leads.filter(l => arr.indexOf(l.status) >= i).length }));
      const why = LOST_WHY.map(w => ({ w, n: C.lost.filter(l => l.why === w).length })).filter(x => x.n);
      body = `<div class="grid g2"><div class="card"><h3>Источники заявок</h3>${bySrc.map(x => `<div class="hb"><div class="lb"><span>${esc(x.s)}</span><span>${x.n} заявок · сделок ${x.d} (${fmt(x.d / x.n * 100)}%)</span></div><div class="tr"><div class="pl" style="width:${x.n / mx * 100}%"></div><div class="fa" style="width:${x.d / mx * 100}%;background:var(--ok)"></div></div></div>`).join('')}<div class="legend mt"><span><i style="background:#cdd6e6"></i>заявки</span><span><i style="background:var(--ok)"></i>сделки</span></div></div>
        <div class="card"><h3>Воронка продаж</h3>${funnel.map(f => `<div class="funnel-row"><span>${LEAD_NAME[f.s]}</span><div class="funnel-bar" style="width:${Math.max(6, f.n / Math.max(1, funnel[0].n) * 100)}%;background:${LEAD_ST.find(x => x[0] === f.s)[2]}">${f.n}</div></div>`).join('')}
        ${why.length ? `<h4>Причины отказов</h4>${why.map(x => `<div class="flex" style="justify-content:space-between"><span>${esc(x.w)}</span><b>${x.n}</b></div>`).join('')}` : ''}</div></div>`;
    }
    return `<div class="kpis">
      ${kpi('Активных лидов', C.active.length, 'новых за неделю: ' + C.newWeek, 'bl')}
      ${kpi('Задачи на сегодня', C.due.length, C.late.length ? `<span class="bad">просрочено: ${C.late.length}</span>` : 'просрочек нет', C.late.length ? 'bad' : 'ok')}
      ${kpi('Без ответа > суток', C.stale.length, 'новые заявки', C.stale.length ? 'bad' : 'ok')}
      ${kpi('Сделок', C.deals.length, 'на ' + moneyS(C.dealSum), 'ok')}
      ${kpi('Конверсия в сделку', fmt(C.conv, 1) + '%', 'из закрытых выиграно ' + fmt(C.winRate) + '%', 'ac')}
    </div>
    <div class="flex mb seg">${tabs.map(([k, l]) => `<button class="${k === tab ? 'btn-p' : ''}" data-act="leadTab" data-tab="${k}">${l}</button>`).join('')}
      <span class="right"></span><input class="txt" data-arg="q" value="${esc(a.q || '')}" placeholder="Поиск: имя, телефон…" style="width:200px"><select data-arg="src"><option value="">Все источники</option>${opts(LEAD_SRC, src)}</select>
      <button class="btn-s" data-act="leadCsv">⬇ CSV</button><button class="btn-p" data-act="leadNew">+ Лид</button></div>
    ${body}`;
  }
};
ACT.leadTab = d => { pageArgs.tab = d.tab; rerender(); };
const leadBy = id => P().leads.find(l => l.id === id);
function leadLog(l, text) { (l.log = l.log || []).push({ d: today(), t: text }); }
const LEAD_TODO = { new: 'Перезвонить, уточнить пожелания', contact: 'Пригласить на показ', meet: 'Показ квартир в офисе продаж', shown: 'Отправить расчёт рассрочки', booked: 'Подписать договор' };
ACT.leadMove = (d, el) => { const l = leadBy(d.id); l.status = d.st; leadLog(l, '→ ' + LEAD_NAME[d.st]); if (LEAD_TODO[d.st]) { l.nextText = LEAD_TODO[d.st]; if (!l.nextDate || l.nextDate < today()) l.nextDate = addDays(today(), 1); } save(); rerender(); };
ACT.leadLost = async d => {
  const l = leadBy(d.id);
  // причина отказа — из списка или своя (раньше — системное окно браузера с номерами)
  const r = await ui.form(T('Причина отказа?'), [
    { name: 'why', type: 'select', label: 'Причина', value: LOST_WHY[0], options: [...LOST_WHY.map(w => [w, w]), ['', 'Другое']] },
    { name: 'other', label: 'Своя причина', placeholder: 'Например: уехал в другой город' },
  ], { ok: T('Отметить отказ') });
  if (!r) return; const why = r.why || r.other; if (!why) return toast('Укажите причину');
  l.why = why; l.status = 'lost'; leadLog(l, '✕ Отказ: ' + l.why); save(); rerender(); };
ACT.leadPick = d => { const l = leadBy(d.id); closeModal(); go('apt', { lead: l.id, rooms: l.rooms ? String(l.rooms) : '', st: 'free', side: l.side || '', pmax: l.budget ? String(l.budget) : '' }); };
ACT.leadCsv = () => { const p = P(); download('lidy.csv', csv([['Дата', 'Клиент', 'Телефон', 'Источник', 'Ищет', 'Этап', 'Следующее действие', 'Менеджер', 'Причина отказа'], ...p.leads.map(l => [fdate(l.created), l.name, l.phone, l.src, leadWant(l), LEAD_NAME[l.status], l.nextDate ? fdate(l.nextDate) + ' ' + (l.nextText || '') : '', l.manager || '', l.why || ''])]), 'text/csv'); };
ACT.leadNew = () => leadModal(null);
ACT.leadOpen = d => { const l = leadBy(d.id); if (l.unread) { delete l.unread; save(); } leadModal(l); };
function leadModal(l) {
  const p = P(), x = l || { name: '', phone: '', src: 'Instagram', status: 'new', rooms: '', budget: '', floor: '', side: '', manager: '', nextDate: today(), nextText: 'Перезвонить, уточнить пожелания', note: '', log: [] };
  const mgrs = p.staff.filter(s => !s.isNew && (s.role === 'Менеджер по продажам' || ITR_ROLES.has(s.role))).map(s => s.name);
  const apt = x.aptId ? genApts(p).find(a => a.id === x.aptId) : null;
  openModal(`<h2>${l ? '📞 ' + esc(x.name) : '+ Новый лид'}</h2>${l ? `<div class="flex mb">${x.phone ? `<a class="btn btn-s" href="${telHref(x.phone)}">📞 Позвонить</a><a class="btn btn-s" href="${waHref(x.phone)}" target="_blank" rel="noopener">🟢 WhatsApp</a>` : ''}${x.igUser ? `<a class="btn btn-s" href="https://ig.me/m/${encodeURIComponent(x.igUser)}" target="_blank" rel="noopener">📸 Instagram</a>` : x.ch === 'instagram' || x.ch === 'messenger' ? `<a class="btn btn-s" href="https://business.facebook.com/latest/inbox/all" target="_blank" rel="noopener">💬 Ответить в Meta</a>` : ''}<button class="btn-s btn-p" data-act="leadPick" data-id="${x.id}">🔎 Подобрать квартиру</button><button class="btn-s" data-act="leadAi" data-id="${x.id}">🤖 Совет ИИ</button>${apt ? `<button class="btn-s" data-act="aptOpen" data-id="${apt.id}">🧾 Кв. №${apt.num}</button>` : ''}</div>` : ''}
  <div class="form">
    <label class="fld"><span>Имя клиента *</span><input class="txt" id="ldName" value="${esc(x.name)}"></label>
    <label class="fld"><span>Телефон *</span><input class="txt" id="ldPhone" value="${esc(x.phone)}" placeholder="+998"></label>
    <label class="fld"><span>Источник</span><select id="ldSrc">${opts(LEAD_SRC, x.src)}</select></label>
    <label class="fld"><span>Этап</span><select id="ldSt">${opts(LEAD_ST.map(s => [s[0], s[1]]), x.status)}</select></label>
    <label class="fld"><span>Комнат</span><select id="ldRooms"><option value="">не важно</option>${opts([['1', '1-комнатная'], ['2', '2-комнатная'], ['3', '3-комнатная']], x.rooms)}</select></label>
    <label class="fld"><span>Бюджет, млн ${cur()}</span><input class="txt" type="number" id="ldBudget" value="${x.budget || ''}"></label>
    <label class="fld"><span>Этаж</span><input class="txt" id="ldFloor" value="${esc(x.floor || '')}" placeholder="например: средний, не 1-й"></label>
    <label class="fld"><span>Окна</span><select id="ldSide"><option value="">не важно</option>${opts([['south', 'во двор'], ['north', 'на улицу']], x.side)}</select></label>
    <label class="fld"><span>Менеджер</span><input class="txt" id="ldMgr" value="${esc(x.manager || '')}" list="ldMgrs"><datalist id="ldMgrs">${mgrs.map(m => `<option value="${esc(m)}">`).join('')}</datalist></label>
    <label class="fld"><span>Следующее действие: дата</span><input class="txt" type="date" id="ldNextD" value="${x.nextDate || ''}"></label>
    <label class="fld" style="grid-column:span 2"><span>Что сделать</span><input class="txt" id="ldNextT" value="${esc(x.nextText || '')}"></label>
    <label class="fld" style="grid-column:1/-1"><span>Заметка</span><input class="txt" id="ldNote" value="${esc(x.note || '')}"></label></div>
  ${l ? `<h4>История</h4><div class="lead-log">${(x.log || []).slice().reverse().map(e => `<div><span class="small muted">${fdate(e.d)}</span> ${esc(e.t)}</div>`).join('') || '<span class="muted small">пусто</span>'}</div>
    <div class="flex mt"><input class="txt" id="ldLog" placeholder="Записать звонок или разговор…" style="flex:1"><button class="btn-s" data-act="leadLogAdd" data-id="${x.id}">+ В историю</button></div>` : ''}
  <div class="flex mt"><button class="btn-p" data-act="leadSave" data-id="${l ? x.id : ''}">Сохранить</button>${l ? `<button class="btn-d" data-act="leadDel" data-id="${x.id}">Удалить</button>` : ''}</div>`);
}
ACT.leadSave = d => {
  const p = P(), name = $('#ldName').value.trim(), phone = $('#ldPhone').value.trim(); let l = d.id ? leadBy(d.id) : null; if (!name || (!phone && !(l && l.extKey))) return toast('Укажите имя и телефон'); const isNew = !l;
  if (!l) { l = { id: uid(), created: today(), log: [] }; p.leads.push(l); }
  const prev = l.status;
  Object.assign(l, { name, phone, src: $('#ldSrc').value, status: $('#ldSt').value, rooms: $('#ldRooms').value, budget: num($('#ldBudget').value) || '', floor: $('#ldFloor').value.trim(), side: $('#ldSide').value, manager: $('#ldMgr').value.trim(), nextDate: $('#ldNextD').value, nextText: $('#ldNextT').value.trim(), note: $('#ldNote').value.trim() });
  if (isNew) leadLog(l, 'Заявка: ' + l.src); else if (prev !== l.status) leadLog(l, '→ ' + LEAD_NAME[l.status]);
  save(); closeModal(); rerender(); toast(isNew ? 'Лид добавлен' : 'Сохранено');
};
ACT.leadLogAdd = d => { const t = $('#ldLog').value.trim(); if (!t) return; const l = leadBy(d.id); leadLog(l, t); save(); leadModal(l); };
ACT.leadDel = async d => { if (!await ui.confirm(T('Удалить лид?'))) return; P().leads = P().leads.filter(l => l.id !== d.id); save(); closeModal(); rerender(); };
ACT.leadAi = d => { const l = leadBy(d.id); closeModal(); aiAsk(`${T('Клиент')}: ${l.name}, ${T('источник')} ${l.src}, ${T('этап')} «${LEAD_NAME[l.status]}». ${T('Ищет')}: ${leadWant(l) || '—'}. ${T('Заметка')}: ${l.note || '—'}. ${T('История')}: ${(l.log || []).map(e => e.t).join('; ')}. ${T('Подбери 2–3 подходящие свободные квартиры из нашего проекта, подскажи, что сказать клиенту, как работать с возражениями и какой следующий шаг, чтобы довести до задатка.')}`); };
function leadsDemo(p) {
  const R = rng(321), t = today(), names = ['Шахзод Алимов', 'Ольга Ким', 'Бекзод Норов', 'Дилноза Хамидова', 'Рустам Юлдашев', 'Анна Пак', 'Жамшид Каримов', 'Малика Турсунова', 'Сардор Рахимов', 'Елена Цой', 'Улугбек Сафаров', 'Гульнора Исмаилова', 'Тимур Абдуллаев', 'Наргиза Юсупова', 'Фаррух Мирзаев', 'Зарина Ахмедова'];
  const sts = ['new', 'new', 'new', 'contact', 'contact', 'contact', 'meet', 'meet', 'shown', 'shown', 'booked', 'deal', 'deal', 'lost', 'lost', 'contact'];
  const mgr = (p.staff.find(s => s.role === 'Менеджер по продажам') || {}).name || '';
  names.forEach((name, i) => {
    const st = sts[i], created = addDays(t, -Math.floor(R() * 30) - (st === 'new' ? 0 : 3)), rooms = String(1 + Math.floor(R() * 3));
    const l = { id: uid(), name, phone: `+998 9${Math.floor(R() * 10)} ${100 + Math.floor(R() * 900)} ${10 + Math.floor(R() * 90)} ${10 + Math.floor(R() * 90)}`, src: LEAD_SRC[Math.floor(R() * LEAD_SRC.length)], status: st, rooms, budget: [650, 950, 1300][+rooms - 1] + Math.floor(R() * 4) * 50, floor: R() < .4 ? 'не первый и не последний' : '', side: R() < .5 ? 'south' : '', manager: mgr, created, note: '', log: [{ d: created, t: 'Заявка' }] };
    if (ACTIVE_ST.has(st)) { l.nextDate = addDays(t, Math.floor(R() * 5) - 2); l.nextText = { new: 'Перезвонить, уточнить пожелания', contact: 'Пригласить на показ', meet: 'Показ квартир в офисе продаж', shown: 'Отправить расчёт рассрочки', booked: 'Подписать договор' }[st]; }
    if (st === 'lost') l.why = LOST_WHY[Math.floor(R() * LOST_WHY.length)];
    if (st === 'new' && i === 0) l.created = addDays(t, -2);
    p.leads.push(l);
  });
}

/* ===== Подключения: заявки из Facebook, Instagram, WhatsApp и с сайта ===== */
const CH = { facebook: ['📘', 'Facebook'], messenger: ['💬', 'Facebook'], instagram: ['📸', 'Instagram'], whatsapp: ['🟢', 'WhatsApp'], site: ['🌐', 'Сайт'] };
const viaServer = () => /^https?:$/.test(location.protocol);
let CN = null, inboxBusy = false;
async function cnApi(path, body) {
  const r = await fetch(path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
  if (!r.ok) throw new Error(r.status); return r.json();
}
async function inboxPoll(manual) {
  if (!viaServer() || inboxBusy || !DB) return; inboxBusy = true;
  try {
    let added = 0, upd = 0, j, guard = 0;
    const p = P();
    do {   // заявок больше лимита — забираем следующими порциями (раньше лишние терялись)
    j = await cnApi('/api/inbox?since=' + (DB.settings.inboxSeq || 0));
    for (const it of j.items) {
      const ch = CH[it.ch] || ['📥', it.ch], src = it.ch === 'site' ? (LEAD_SRC.includes(it.src) ? it.src : 'Сайт') : ch[1];
      const line = `${ch[0]} ${src}${it.kind === 'form' ? ' · форма' : ''}: ${it.text || ''}`.trim();
      const digits = String(it.phone || '').replace(/\D/g, '');
      let l = p.leads.find(x => x.extKey === it.key) || (digits && p.leads.find(x => x.phone && String(x.phone).replace(/\D/g, '') === digits));
      if (l) {
        leadLog(l, line); if (!l.phone && it.phone) l.phone = it.phone; l.extKey = l.extKey || it.key; l.unread = true;
        if (ACTIVE_ST.has(l.status)) { l.nextDate = today(); l.nextText = 'Ответить на новое сообщение'; } upd++;
      } else {
        const rm = it.rooms || (String(it.text).match(/(\d)\s*-?\s*(?:х\s*)?(?:комн|xona|хона)|комнат\D{0,4}(\d)/i) || []).slice(1).find(Boolean) || '';
        p.leads.push({ id: uid(), name: it.name || (it.user ? '@' + it.user : 'Клиент ' + src), phone: it.phone || '', src, ch: it.ch, extKey: it.key, igUser: it.user || '', status: 'new', rooms: /^[123]$/.test(rm) ? rm : '', budget: it.budget || '', floor: '', side: '', manager: '', created: today(), nextDate: today(), nextText: 'Ответить клиенту в течение 15 минут', note: String(it.text || '').slice(0, 200), unread: true, log: [{ d: today(), t: line }] });
        added++;
      }
    }
    DB.settings.inboxSeq = j.seq;
    } while (j.more && ++guard < 20);
    if (added || upd) {
      save(); toast(added ? `📥 ${T('Новых заявок')}: ${added}` : `💬 ${T('Новые сообщения от клиентов')}: ${upd}`);
      const busy = !$('#modal').classList.contains('hidden') || /INPUT|TEXTAREA|SELECT/.test((document.activeElement || {}).tagName || '');
      if (curPage === 'leads' && pageArgs.tab !== 'connect' && !busy) rerender();
    } else if (manual) toast('Новых заявок нет');
  } catch (e) { if (manual) toast('Сервер приёма заявок не отвечает'); }
  inboxBusy = false;
}
setTimeout(() => { inboxPoll(); setInterval(inboxPoll, 15000); }, 1500);

const cnCopy = (v, id) => `<div class="cn-copy"><code id="${id}" class="notr">${esc(v)}</code><button class="btn-s" data-act="cnCopy" data-id="${id}">📋</button></div>`;
function connectHtml() {
  if (!viaServer()) return `<div class="card"><h3>🔌 Подключения</h3><p>Заявки из Facebook, Instagram и WhatsApp принимает сервер программы. Сейчас программа открыта как файл.</p><p><b>Запустите файл «Запустить_localhost.bat»</b> в папке программы — откроется адрес http://localhost:5578, и здесь появятся настройки.</p></div>`;
  if (!CN) return `<div class="card muted">Загрузка…</div>`;
  if (CN.err) return `<div class="card"><h3>🔌 Подключения</h3><p class="bad">Сервер приёма заявок не отвечает. Закройте окно сервера и снова запустите «Запустить_localhost.bat».</p><button class="btn-s" data-act="cnReload">↻ Повторить</button></div>`;
  const pu = CN.publicUrl, base = pu || 'https://ВАШ-АДРЕС', hook = CN.hookUrl || base + '/webhook/meta', site = CN.leadUrl || base + '/api/lead?key=' + CN.apiKey;
  const ok = (b, t) => `<span class="cn-pill ${b ? 'on' : ''}">${b ? '✓' : '!'} ${t}</span>`;
  const n = [pu, CN.has.appSecret, CN.has.pageToken].filter(Boolean).length;
  return `<div class="grid2">
  <div class="card"><h3>1. Адрес в интернете ${ok(!!pu, pu ? 'указан' : 'не указан')}</h3>
    <p class="small muted">${window.OFTOB ? 'Адрес программы в интернете (с https). Если программа уже на хостинге — оставьте как есть, он подставлен автоматически.' : 'Facebook и WhatsApp отправляют заявки на адрес в интернете, поэтому компьютер с программой должен быть включён и сервер запущен. Для проверки запустите «Открыть_доступ_из_интернета.bat» и вставьте сюда адрес вида https://….trycloudflare.com. Для постоянной работы нужен свой сервер или постоянный туннель.'}</p>
    <div class="flex"><input class="txt notr" id="cnUrl" value="${esc(pu)}" placeholder="https://..." style="flex:1"><button class="btn-p" data-act="cnSaveUrl">Сохранить</button></div>
    <h4 class="mt">2. Ключи приложения Meta</h4>
    <p class="small muted">${window.OFTOB ? 'Ключи хранятся на сервере в зашифрованном виде — не в браузере и не в резервной копии.' : 'Ключи хранятся только на этом компьютере (папка data) — не в браузере и не в резервной копии.'}</p>
    <label class="fld"><span>App Secret ${ok(CN.has.appSecret, CN.has.appSecret ? 'сохранён' : 'не указан')}</span><input class="txt notr" type="password" id="cnSecret" autocomplete="off" placeholder="${CN.has.appSecret ? '•••••••' : 'App Secret'}"></label>
    <label class="fld"><span>Page Access Token ${ok(CN.has.pageToken, CN.has.pageToken ? 'сохранён' : 'не указан')}</span><input class="txt notr" type="password" id="cnToken" autocomplete="off" placeholder="${CN.has.pageToken ? '•••••••' : 'Page Access Token'}"></label>
    <div class="flex"><button class="btn-p" data-act="cnSaveKeys">Сохранить ключи</button><button class="btn-s" data-act="cnCheck">🔍 Проверить</button><button class="btn-s" data-act="cnSubscribe">🔔 Подписать страницу</button></div>
    <div id="cnCheckRes" class="small mt"></div>
    <h4 class="mt">3. Вебхук для Meta</h4>
    <div class="small muted">Callback URL</div>${cnCopy(hook, 'cnHook')}
    <div class="small muted">Verify token</div>${cnCopy(CN.verifyToken, 'cnVerify')}
    <button class="btn-s" data-act="cnRegen" data-k="verify">↻ Новый verify token</button>
  </div>
  <div class="card"><h3>Статус ${ok(n === 3, n + ' из 3 настроено')}</h3>
    <div class="flex mb"><button class="btn-s" data-act="cnPoll">📥 Проверить заявки</button><span class="small muted">Программа сама проверяет новые заявки каждые 15 секунд</span></div>
    <h4>Тестовая заявка</h4><p class="small muted">Проходит весь путь как настоящая и появится в колонке «Новые».</p>
    <div class="flex">${['facebook', 'instagram', 'whatsapp', 'site'].map(c => `<button class="btn-s" data-act="cnTest" data-ch="${c}">${CH[c][0]} ${CH[c][1]}</button>`).join('')}</div>
    <h4 class="mt">Последние входящие (${CN.count})</h4>
    <div class="lead-log">${CN.last.map(i => `<div><span class="small muted">${new Date(i.t).toLocaleString()}</span> ${(CH[i.ch] || ['📥'])[0]} <b class="notr">${esc(i.name || i.phone || '')}</b> <span class="notr">${esc((i.text || '').slice(0, 90))}</span></div>`).join('') || '<span class="small muted">пусто</span>'}</div>
    ${CN.errors.length ? `<h4 class="mt bad">Ошибки</h4><div class="lead-log">${CN.errors.slice(0, 5).map(e => `<div class="small"><span class="muted">${new Date(e.t).toLocaleString()}</span> ${esc(e.msg)}</div>`).join('')}</div>` : ''}
  </div></div>
  <div class="grid2 mt">
  <div class="card"><h3>📘 Facebook и 📸 Instagram — формы в рекламе</h3><ol class="cn-steps">
    <li>Откройте developers.facebook.com → «Мои приложения» → создайте приложение типа «Бизнес».</li>
    <li>Добавьте продукт «Webhooks» → объект «Page» → вставьте Callback URL и Verify token → подпишитесь на поле leadgen.</li>
    <li>В «Graph API Explorer» получите токен страницы с правами leads_retrieval, pages_show_list, pages_manage_metadata, pages_read_engagement и вставьте его в Page Access Token.</li>
    <li>Нажмите «🔔 Подписать страницу». Заявки из рекламных форм Facebook и Instagram будут приходить сюда сами.</li></ol></div>
  <div class="card"><h3>📸 Instagram Direct и 💬 Messenger</h3><ol class="cn-steps">
    <li>Instagram должен быть бизнес-аккаунтом и привязан к странице Facebook.</li>
    <li>В Webhooks выберите объекты «Instagram» и «Page» → подпишитесь на поле messages (тот же Callback URL и Verify token).</li>
    <li>Права токена: instagram_basic, instagram_manage_messages, pages_messaging.</li>
    <li>Каждый новый собеседник в Direct станет лидом, а его следующие сообщения попадут в историю клиента.</li></ol></div>
  <div class="card"><h3>🟢 WhatsApp Business</h3><ol class="cn-steps">
    <li>В том же приложении добавьте продукт «WhatsApp» и подключите номер компании.</li>
    <li>WhatsApp → Конфигурация → Webhook: вставьте Callback URL и Verify token → подпишитесь на messages.</li>
    <li>Каждый новый номер, написавший в WhatsApp, станет лидом с телефоном. Реклама «Написать в WhatsApp» попадёт сюда с названием объявления.</li></ol></div>
  <div class="card"><h3>🌐 Сайт, Tilda, Make, Zapier</h3>
    <p class="small">Отправляйте заявки POST-запросом на этот адрес (поля: name, phone, message, rooms, budget, src):</p>${cnCopy(site, 'cnSite')}
    <p class="small muted">Tilda: Настройки сайта → Формы → Webhook. Make или Zapier: модуль HTTP → POST. Так же можно подключить Telegram-бота и OLX через Make.</p>
    <button class="btn-s" data-act="cnRegen" data-k="api">↻ Новый ключ</button></div>
  </div>`;
}
async function cnLoad() {
  try { CN = await cnApi('/api/connect'); } catch (e) { CN = { err: true }; }
  if (curPage === 'leads' && pageArgs.tab === 'connect' && $('#cnWrap')) $('#cnWrap').innerHTML = connectHtml();
}
ACT.cnReload = () => { CN = null; cnLoad(); };
ACT.cnPoll = async () => { await inboxPoll(true); cnLoad(); };
ACT.cnCopy = d => { const v = $('#' + d.id).textContent; (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(() => toast('Скопировано'), () => toast(v)); };
ACT.cnSaveUrl = async () => { const v = $('#cnUrl').value.trim(); if (v && !/^https:\/\//.test(v)) return toast('Адрес должен начинаться с https://'); await cnApi('/api/connect', { publicUrl: v }); cnLoad(); toast('Сохранено'); };
ACT.cnSaveKeys = async () => { const s = $('#cnSecret').value.trim(), t = $('#cnToken').value.trim(); if (!s && !t) return toast('Вставьте ключ'); await cnApi('/api/connect', { appSecret: s, pageToken: t }); cnLoad(); toast('Ключи сохранены на этом компьютере'); };
ACT.cnRegen = async d => { if (!await ui.confirm(T('Создать новый ключ? Старый перестанет работать — его нужно будет заменить в настройках Meta или сайта.'))) return; await cnApi('/api/connect', { regen: d.k }); cnLoad(); };
ACT.cnCheck = async () => {
  const r = $('#cnCheckRes'); r.textContent = T('Проверяю…'); const j = await cnApi('/api/connect/check', {}).catch(() => ({ ok: false, error: T('Нет связи с сервером') }));
  r.innerHTML = j.ok ? `<span class="good">✓ ${T('Страница')}: <b class="notr">${esc(j.page)}</b>${j.ig ? ` · Instagram: <b class="notr">@${esc(j.ig)}</b>` : ''} · ${j.subscribed ? T('Подписка') + ': ' + esc(j.subscribed) : T('Страница ещё не подписана')}</span>` : `<span class="bad">✕ <span class="notr">${esc(j.error)}</span></span>`;
};
ACT.cnSubscribe = async () => { const j = await cnApi('/api/connect/subscribe', {}).catch(() => ({ ok: false, error: T('Нет связи с сервером') })); toast(j.ok ? 'Страница подписана: заявки и сообщения будут приходить сюда' : '✕ ' + j.error); if (j.ok) ACT.cnCheck(); };
ACT.cnTest = async d => { try { await cnApi('/api/test', { ch: d.ch }); } catch (e) { return toast('Сервер приёма заявок не отвечает'); } await inboxPoll(); cnLoad(); };

PAGES.leads.mounted = (p, a) => { if (a.tab === 'connect') cnLoad(); };
