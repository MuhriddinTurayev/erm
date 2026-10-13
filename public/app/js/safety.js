'use strict';
/* ===== Техника безопасности и охрана труда ===== */
const SAFE_CERT = { 'Сварщик': 'Удостоверение сварщика', 'Электрик': 'Группа по электробезопасности', 'Крановщик': 'Удостоверение крановщика', 'Монтажник': 'Допуск к работам на высоте', 'Кровельщик': 'Допуск к работам на высоте', 'Фасадчик': 'Допуск к работам на высоте', 'Машинист экскаватора': 'Удостоверение машиниста', 'Водитель самосвала': 'Водительское удостоверение кат. C', 'Арматурщик': 'Допуск стропальщика' };
const PPE_BASE = ['Каска', 'Сигнальный жилет', 'Спецобувь', 'Перчатки', 'Защитные очки'];
const PPE_HIGH = new Set(['Монтажник', 'Кровельщик', 'Фасадчик', 'Каменщик (кладчик)', 'Плотник-опалубщик']);
const ppeNeed = s => ITR_ROLES.has(s.role) ? ['Каска', 'Сигнальный жилет'] : PPE_BASE.concat(PPE_HIGH.has(s.role) ? ['Страховочная привязь'] : []);
const INC_KIND = [['near', 'Опасная ситуация (без пострадавших)'], ['violation', 'Нарушение правил ТБ'], ['minor', 'Лёгкая травма'], ['serious', 'Тяжёлая травма / несчастный случай']];
const PERMIT_KIND = ['Работы на высоте', 'Огневые (сварочные) работы', 'Работы в котловане / траншее', 'Работы краном вблизи ЛЭП', 'Электромонтажные работы', 'Работы в замкнутом пространстве'];
const DAILY = ['Ограждение котлована и откосы в норме', 'Леса и подмости осмотрены, ограждены', 'Краны и стропы осмотрены, журнал заполнен', 'Электрощиты закрыты, кабели без повреждений', 'Все рабочие в касках и жилетах', 'Пожарные щиты и огнетушители на месте', 'Проходы и проезды свободны, освещены', 'Проёмы и перепады высот ограждены', 'Аптечки укомплектованы', 'Наряды-допуски на опасные работы оформлены'];
function safeDefaults() { return { brief: {}, certs: [], ppe: [], permits: [], incidents: [], daily: {} }; }
function safetyCalc(p) {
  const sf = p.safety, t = today(), staff = p.staff.filter(s => s.active !== false && !s.isNew);
  const rows = staff.map(s => {
    const b = sf.brief[s.id] || {}, period = ITR_ROLES.has(s.role) ? 180 : 90;
    const repeatDue = b.repeat || b.primary ? addDays(b.repeat || b.primary, period) : null;
    const certKind = SAFE_CERT[s.role], cert = certKind ? sf.certs.filter(c => c.staffId === s.id && c.kind === certKind).sort((a, b) => (b.until || '').localeCompare(a.until || ''))[0] : null;
    const certSt = !certKind ? 'na' : !cert ? 'none' : cert.until < t ? 'expired' : daysBetween(t, cert.until) <= 30 ? 'soon' : 'ok';
    const need = ppeNeed(s), have = new Set(sf.ppe.filter(x => x.staffId === s.id && daysBetween(x.date, t) <= 365).map(x => x.item));
    const missing = need.filter(i => !have.has(i));
    return { s, b, intro: !!b.intro, primary: !!b.primary, repeatDue, repeatLate: repeatDue && repeatDue < t, certKind, cert, certSt, missing, need };
  });
  const lastInc = sf.incidents.filter(i => i.kind === 'minor' || i.kind === 'serious').map(i => i.date).sort().pop();
  const daysSafe = lastInc ? daysBetween(lastInc, t) : daysBetween(p.startDate, t);
  const ppeTotal = sum(rows, r => r.need.length), ppeHave = ppeTotal - sum(rows, r => r.missing.length);
  const dailyDone = Object.keys(sf.daily[t] || {}).filter(k => sf.daily[t][k]).length;
  return { rows, daysSafe: Math.max(0, daysSafe), noIntro: rows.filter(r => !r.intro || !r.primary), late: rows.filter(r => r.repeatLate), certBad: rows.filter(r => r.certSt === 'none' || r.certSt === 'expired'), certSoon: rows.filter(r => r.certSt === 'soon'), ppeMiss: rows.filter(r => r.missing.length), ppePct: ppeTotal ? ppeHave / ppeTotal * 100 : 100, openInc: sf.incidents.filter(i => i.status !== 'closed'), activePermits: sf.permits.filter(x => x.from <= t && x.to >= t), dailyDone };
}
function safetyAudit(p, add) {
  const C = safetyCalc(p), nm = l => l.slice(0, 5).map(r => esc(r.s.name)).join(', ') + (l.length > 5 ? ` и ещё ${l.length - 5}` : '');
  if (C.noIntro.length) add('bad', 'Охрана труда', 'safety', `Без вводного/первичного инструктажа: ${nm(C.noIntro)}`);
  if (C.late.length) add('warn', 'Охрана труда', 'safety', `Просрочен повторный инструктаж: ${nm(C.late)}`);
  if (C.certBad.length) add('bad', 'Охрана труда', 'safety', `Нет или просрочены удостоверения/допуски: ${C.certBad.slice(0, 5).map(r => `${esc(r.s.name)} (${r.certKind})`).join(', ')}`);
  if (C.certSoon.length) add('warn', 'Охрана труда', 'safety', `Истекают в течение 30 дней удостоверения: ${nm(C.certSoon)}`);
  if (C.ppeMiss.length) add('warn', 'Охрана труда', 'safety', `Не выдана спецодежда / СИЗ: ${C.ppeMiss.length} сотрудникам (обеспеченность ${fmt(C.ppePct)}%)`);
  if (C.openInc.length) add(C.openInc.some(i => i.kind === 'serious') ? 'bad' : 'warn', 'Охрана труда', 'safety', `Не закрыты происшествия/нарушения: ${C.openInc.length}`);
  if (!C.noIntro.length && !C.late.length && !C.certBad.length && !C.openInc.length) add('ok', 'Охрана труда', 'safety', `Охрана труда в порядке · без травм ${C.daysSafe} дн.`);
}
const certCell = r => r.certSt === 'na' ? '<span class="muted">—</span>' : r.certSt === 'none' ? `<span class="bad">нет: ${esc(r.certKind)}</span>` : `<span class="${r.certSt === 'expired' ? 'bad' : r.certSt === 'soon' ? 'warn-t' : 'ok'}">${esc(r.certKind)} до ${fdate(r.cert.until)}</span>`;
PAGES.safety = {
  title: 'Техника безопасности и охрана труда',
  render(p, a) {
    const C = safetyCalc(p), sf = p.safety, t = today(), tab = a.tab || 'staff', dd = sf.daily[t] || {};
    const tabs = [['staff', '👷 Инструктажи и допуски'], ['ppe', '🦺 Спецодежда (СИЗ)'], ['permits', '📋 Наряды-допуски'], ['inc', '⚠️ Происшествия'], ['daily', '✅ Осмотр площадки']];
    let body = '';
    if (tab === 'staff') body = `<div class="card"><h3>Инструктажи, удостоверения и допуски <span class="tools"><button class="btn-s btn-p" data-act="sfBriefAll">Отметить повторный инструктаж всем сегодня</button><button class="btn-s" data-act="sfCertAdd">+ Удостоверение</button></span></h3>
      <div class="small muted mb">Вводный и первичный — до начала работы. Повторный — рабочим каждые 3 месяца, ИТР каждые 6 месяцев.</div>
      <div class="tbl-wrap"><table><thead><tr><th>Сотрудник</th><th>Вводный</th><th>Первичный</th><th>Повторный до</th><th>Удостоверение / допуск</th><th></th></tr></thead><tbody>
      ${C.rows.map(r => `<tr><td><b>${esc(r.s.name)}</b><div class="small muted">${esc(r.s.role)}</div></td>
        <td>${r.b.intro ? fdate(r.b.intro) : '<span class="bad">не проведён</span>'}</td><td>${r.b.primary ? fdate(r.b.primary) : '<span class="bad">не проведён</span>'}</td>
        <td class="${r.repeatLate ? 'bad' : ''}">${r.repeatDue ? fdate(r.repeatDue) + (r.repeatLate ? ' — просрочен' : '') : '—'}</td><td>${certCell(r)}</td>
        <td style="white-space:nowrap"><button class="btn-s" data-act="sfBrief" data-id="${r.s.id}">📝 Инструктаж проведён</button>${r.certKind ? ` <button class="btn-s" data-act="sfCertAdd" data-id="${r.s.id}">🪪</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="6" class="muted">Нет сотрудников</td></tr>'}
      </tbody></table></div></div>`;
    if (tab === 'ppe') body = `<div class="card"><h3>Спецодежда и средства защиты <span class="tools"><button class="btn-s btn-p" data-act="sfPpeAll">Выдать недостающее всем</button></span></h3>
      <div class="small muted mb">Норма: каска, сигнальный жилет, спецобувь, перчатки, очки; для работ на высоте — страховочная привязь. Срок носки — 12 месяцев.</div>
      <div class="tbl-wrap"><table><thead><tr><th>Сотрудник</th><th>Выдано</th><th>Не хватает</th><th></th></tr></thead><tbody>
      ${C.rows.map(r => `<tr><td><b>${esc(r.s.name)}</b><div class="small muted">${esc(r.s.role)}</div></td><td class="small">${r.need.filter(i => !r.missing.includes(i)).join(', ') || '—'}</td><td class="small ${r.missing.length ? 'bad' : 'ok'}">${r.missing.join(', ') || '✅ всё выдано'}</td>
        <td>${r.missing.length ? `<button class="btn-s" data-act="sfPpe" data-id="${r.s.id}">🦺 Выдать</button>` : ''}</td></tr>`).join('')}
      </tbody></table></div></div>`;
    if (tab === 'permits') body = `<div class="card"><h3>Наряды-допуски на опасные работы <span class="tools"><button class="btn-s btn-p" data-act="sfPermitAdd">+ Наряд-допуск</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>№</th><th>Вид работ</th><th>Место</th><th>С</th><th>По</th><th>Ответственный</th><th>Статус</th><th></th></tr></thead><tbody>
      ${sf.permits.slice().reverse().map((x, i) => { const st = x.to < t ? 'закрыт' : x.from > t ? 'запланирован' : 'действует'; return `<tr><td>${sf.permits.length - i}</td><td>${esc(x.kind)}</td><td>${esc(x.place)}</td><td>${fdate(x.from)}</td><td>${fdate(x.to)}</td><td>${esc(x.resp)}</td><td>${pill(st === 'действует' ? 'free' : st === 'закрыт' ? 'reserve' : 'booked', st)}</td><td><button class="x" data-act="sfDel" data-k="permits" data-id="${x.id}">✕</button></td></tr>`; }).join('') || '<tr><td colspan="8" class="muted">Нарядов нет</td></tr>'}
      </tbody></table></div></div>`;
    if (tab === 'inc') body = `<div class="card"><h3>Журнал происшествий и нарушений <span class="tools"><button class="btn-s btn-p" data-act="sfIncAdd">+ Записать</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Дата</th><th>Тип</th><th>Что случилось</th><th>Кто</th><th>Принятые меры</th><th>Статус</th><th></th></tr></thead><tbody>
      ${sf.incidents.slice().sort((x, y) => y.date.localeCompare(x.date)).map(x => `<tr><td>${fdate(x.date)}</td><td>${pill(x.kind === 'serious' ? 'sold' : x.kind === 'near' ? 'reserve' : 'booked', Object.fromEntries(INC_KIND)[x.kind])}</td><td>${esc(x.desc)}</td><td>${esc(x.who)}</td><td class="small">${esc(x.action || '—')}</td>
        <td>${x.status === 'closed' ? pill('free', 'закрыто') : `<button class="btn-s" data-act="sfIncClose" data-id="${x.id}">Закрыть</button>`}</td><td><button class="x" data-act="sfDel" data-k="incidents" data-id="${x.id}">✕</button></td></tr>`).join('') || '<tr><td colspan="7" class="muted">Записей нет — отлично!</td></tr>'}
      </tbody></table></div><div class="small muted mt">Несчастный случай с тяжёлыми последствиями — немедленно сообщить в инспекцию труда и сохранить обстановку на месте до расследования.</div></div>`;
    if (tab === 'daily') body = `<div class="card"><h3>Ежедневный осмотр площадки — ${fdate(t)} <span class="tools">${C.dailyDone}/${DAILY.length}</span></h3>
      ${progBar(C.dailyDone / DAILY.length * 100, C.dailyDone === DAILY.length ? 'var(--ok)' : '')}
      <div class="checklist mt">${DAILY.map((d, i) => `<label class="chk-row${dd[i] ? ' done' : ''}"><input type="checkbox" data-act="sfDaily" data-i="${i}"${dd[i] ? ' checked' : ''}> ${d}</label>`).join('')}</div>
      <div class="small muted mt">Проверку проводит прораб или инженер по охране труда в начале каждой смены.</div></div>`;
    return `<div class="kpis">
      ${kpi('Дней без травм', fmt(C.daysSafe), C.openInc.length ? `открытых происшествий: ${C.openInc.length}` : 'открытых происшествий нет', C.openInc.length ? 'bad' : 'ok')}
      ${kpi('Инструктажи', C.noIntro.length + C.late.length ? `⚠️ ${C.noIntro.length + C.late.length}` : '✅', `без первичного: ${C.noIntro.length} · повторный просрочен: ${C.late.length}`, C.noIntro.length ? 'bad' : C.late.length ? 'ac' : 'ok')}
      ${kpi('Удостоверения и допуски', C.certBad.length ? `❌ ${C.certBad.length}` : '✅', `нет или просрочено · истекают скоро: ${C.certSoon.length}`, C.certBad.length ? 'bad' : 'ok')}
      ${kpi('Обеспеченность СИЗ', fmt(C.ppePct) + '%', progBar(C.ppePct), C.ppePct < 90 ? 'ac' : 'ok')}
      ${kpi('Наряды-допуски', C.activePermits.length + ' действует', 'осмотр сегодня: ' + C.dailyDone + '/' + DAILY.length, 'bl')}
    </div>
    <div class="flex mb seg">${tabs.map(([k, l]) => `<button class="${k === tab ? 'btn-p' : ''}" data-act="sfTab" data-tab="${k}">${l}</button>`).join('')}<span class="right"></span><button data-act="aiAskPersona" data-persona="safety" data-q="Проверь состояние охраны труда на объекте по данным проекта: инструктажи, удостоверения, СИЗ, происшествия. Что нарушает требования и что сделать в первую очередь?">🤖 Спросить инженера по ТБ</button></div>
    ${body}`;
  }
};
ACT.sfTab = d => { pageArgs.tab = d.tab; rerender(); };
ACT.sfBrief = d => { const p = P(), b = p.safety.brief[d.id] = p.safety.brief[d.id] || {}, t = today(); if (!b.intro) b.intro = t; if (!b.primary) b.primary = t; else b.repeat = t; save(); rerender(); toast('Инструктаж записан в журнал'); };
ACT.sfBriefAll = () => { if (!confirm(T('Записать повторный инструктаж всем сотрудникам с сегодняшней датой? Подтвердите, что инструктаж действительно проведён.'))) return; const p = P(), t = today(); safetyCalc(p).rows.forEach(r => { const b = p.safety.brief[r.s.id] = p.safety.brief[r.s.id] || {}; if (!b.intro) b.intro = t; if (!b.primary) b.primary = t; else b.repeat = t; }); save(); rerender(); toast('Инструктаж записан в журнал'); };
ACT.sfPpe = d => { const p = P(), r = safetyCalc(p).rows.find(x => x.s.id === d.id); r.missing.forEach(item => p.safety.ppe.push({ id: uid(), staffId: d.id, item, date: today() })); save(); rerender(); toast('Выдача СИЗ записана'); };
ACT.sfPpeAll = () => { if (!confirm(T('Записать выдачу всех недостающих СИЗ сегодняшней датой?'))) return; const p = P(); safetyCalc(p).rows.forEach(r => r.missing.forEach(item => p.safety.ppe.push({ id: uid(), staffId: r.s.id, item, date: today() }))); save(); rerender(); toast('Выдача СИЗ записана'); };
ACT.sfDaily = (d, el) => { const p = P(), t = today(), dd = p.safety.daily[t] = p.safety.daily[t] || {}; dd[d.i] = el.checked; save(); rerender(); };
ACT.sfDel = d => { if (!confirm(T('Удалить запись?'))) return; const p = P(); p.safety[d.k] = p.safety[d.k].filter(x => x.id !== d.id); save(); rerender(); };
ACT.sfIncClose = d => { const x = P().safety.incidents.find(i => i.id === d.id); const a = prompt(T('Какие меры приняты?'), x.action || ''); if (a == null) return; x.action = a; x.status = 'closed'; save(); rerender(); };
const staffOpts = (p, sel) => p.staff.filter(s => s.active !== false && !s.isNew).map(s => `<option value="${s.id}"${s.id === sel ? ' selected' : ''}>${esc(s.name)} — ${esc(s.role)}</option>`).join('');
ACT.sfCertAdd = d => { const p = P(), s = d.id ? p.staff.find(x => x.id === d.id) : null; openModal(`<h2>Удостоверение / допуск</h2><div class="form mt">
  <label class="fld"><span>Сотрудник</span><select id="ceStaff">${staffOpts(p, d.id)}</select></label>
  <label class="fld"><span>Вид</span><input class="txt" id="ceKind" value="${esc(s && SAFE_CERT[s.role] || 'Допуск к работам на высоте')}" list="ceKinds"><datalist id="ceKinds">${[...new Set(Object.values(SAFE_CERT))].map(k => `<option value="${k}">`).join('')}</datalist></label>
  <label class="fld"><span>Номер</span><input class="txt" id="ceNum"></label>
  <label class="fld"><span>Действует до</span><input class="txt" type="date" id="ceUntil" value="${addDays(today(), 365)}"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="sfCertSave">Сохранить</button></div>`); };
ACT.sfCertSave = () => { P().safety.certs.push({ id: uid(), staffId: $('#ceStaff').value, kind: $('#ceKind').value.trim(), num: $('#ceNum').value.trim(), until: $('#ceUntil').value }); save(); closeModal(); rerender(); toast('Сохранено'); };
ACT.sfPermitAdd = () => { const p = P(); openModal(`<h2>Наряд-допуск</h2><div class="form mt">
  <label class="fld"><span>Вид работ</span><select id="pmKind">${opts(PERMIT_KIND)}</select></label>
  <label class="fld"><span>Место</span><input class="txt" id="pmPlace" placeholder="Подъезд 1, 6 этаж"></label>
  <label class="fld"><span>С</span><input class="txt" type="date" id="pmFrom" value="${today()}"></label>
  <label class="fld"><span>По</span><input class="txt" type="date" id="pmTo" value="${addDays(today(), 1)}"></label>
  <label class="fld"><span>Ответственный</span><select id="pmResp">${p.staff.filter(s => ITR_ROLES.has(s.role) && !s.isNew).map(s => `<option>${esc(s.name)}</option>`).join('')}</select></label></div>
  <div class="flex mt"><button class="btn-p" data-act="sfPermitSave">Выдать наряд</button></div>`); };
ACT.sfPermitSave = () => { P().safety.permits.push({ id: uid(), kind: $('#pmKind').value, place: $('#pmPlace').value, from: $('#pmFrom').value, to: $('#pmTo').value, resp: $('#pmResp').value }); save(); closeModal(); rerender(); toast('Наряд-допуск оформлен'); };
ACT.sfIncAdd = () => { const p = P(); openModal(`<h2>Происшествие / нарушение</h2><div class="form mt">
  <label class="fld"><span>Дата</span><input class="txt" type="date" id="inDate" value="${today()}"></label>
  <label class="fld"><span>Тип</span><select id="inKind">${opts(INC_KIND)}</select></label>
  <label class="fld"><span>Кто</span><select id="inWho"><option value="">—</option>${p.staff.filter(s => !s.isNew).map(s => `<option>${esc(s.name)}</option>`).join('')}</select></label>
  <label class="fld" style="grid-column:1/-1"><span>Что случилось</span><input class="txt" id="inDesc"></label>
  <label class="fld" style="grid-column:1/-1"><span>Принятые меры</span><input class="txt" id="inAct"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="sfIncSave">Записать</button></div>`); };
ACT.sfIncSave = () => { const desc = $('#inDesc').value.trim(); if (!desc) return toast('Опишите, что случилось'); P().safety.incidents.push({ id: uid(), date: $('#inDate').value || today(), kind: $('#inKind').value, who: $('#inWho').value, desc, action: $('#inAct').value.trim(), status: 'open' }); save(); closeModal(); rerender(); toast('Записано в журнал'); };
function safetyDemo(p) {
  const R = rng(77), t = today(), sf = p.safety;
  p.staff.filter(s => !s.isNew).forEach((s, i) => {
    const d0 = addDays(p.startDate, Math.floor(R() * 20)); sf.brief[s.id] = { intro: d0, primary: d0 };
    if (i % 4) sf.brief[s.id].repeat = addDays(t, -Math.floor(R() * 80));
    if (SAFE_CERT[s.role] && i % 5) sf.certs.push({ id: uid(), staffId: s.id, kind: SAFE_CERT[s.role], num: '№' + (1000 + i), until: addDays(t, i % 3 ? 200 : 20) });
    ppeNeed(s).forEach((item, k) => { if ((i + k) % 7) sf.ppe.push({ id: uid(), staffId: s.id, item, date: addDays(p.startDate, 5) }); });
  });
  sf.permits.push({ id: uid(), kind: 'Работы на высоте', place: 'Подъезд 1, монтаж опалубки 6 эт.', from: addDays(t, -2), to: addDays(t, 5), resp: 'Алишер Каримов' });
  sf.permits.push({ id: uid(), kind: 'Огневые (сварочные) работы', place: 'Подъезд 2, арматурный каркас', from: addDays(t, -1), to: addDays(t, 2), resp: 'Сергей Петров' });
  sf.incidents.push({ id: uid(), date: addDays(t, -40), kind: 'near', who: '', desc: 'Падение доски с 4 этажа в зону прохода, никто не пострадал', action: 'Установлен защитный козырёк, ограждена опасная зона', status: 'closed' });
  sf.incidents.push({ id: uid(), date: addDays(t, -3), kind: 'violation', who: '', desc: 'Работа на высоте без страховочной привязи', action: '', status: 'open' });
}
