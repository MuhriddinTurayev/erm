'use strict';
/* ===== Юрист: договоры, споры, претензии, шаблоны документов ===== */
const CTR_TYPES = ['Генподряд / подряд', 'Субподряд', 'Поставка материалов', 'Аренда техники', 'Договор купли-продажи квартиры', 'Долевое участие', 'Трудовой договор', 'Проектирование', 'Аренда земли', 'Прочее'];
const CASE_SIDE = ['Покупатель квартиры', 'Подрядчик / субподрядчик', 'Поставщик', 'Работник', 'Госорган / инспекция', 'Соседи / третьи лица', 'Банк'];
const CASE_STAGE = [['claim', 'Претензия'], ['talks', 'Переговоры'], ['court', 'Суд'], ['decision', 'Решение'], ['exec', 'Исполнение'], ['closed', 'Закрыто']];
const CASE_RISK = [['low', 'Низкий'], ['mid', 'Средний'], ['high', 'Высокий']];
function legalDefaults() { return { contracts: [], cases: [] }; }
function salesOverdue(p) {
  const t = today(), out = [];
  genApts(p).filter(a => a.status === 'sold').forEach(a => {
    const pays = (a.o.payments || []).slice().sort((x, y) => x.date.localeCompare(y.date)); if (!pays.length) return;
    const mo = parseInt(String(a.o.plan || '').replace(/\D/g, '')) || 0, first = pays[0], el = daysBetween(first.date, t);
    const exp = mo ? Math.min(a.price, first.amount + (a.price - first.amount) / mo * Math.floor(el / 30.4)) : (el > 30 ? a.price : first.amount);
    if (exp - a.paid > 1000) out.push({ a, debt: Math.round(exp - a.paid) });
  });
  return out;
}
function legalCalc(p) {
  const L = p.legal, t = today(), open = L.cases.filter(c => c.stage !== 'closed');
  return { open, claimed: sum(open, c => c.amount), late: open.filter(c => c.deadline && c.deadline < t), soon: open.filter(c => c.deadline && c.deadline >= t && daysBetween(t, c.deadline) <= 7), ctrSoon: L.contracts.filter(c => c.status !== 'closed' && c.until && c.until >= t && daysBetween(t, c.until) <= 30), ctrLate: L.contracts.filter(c => c.status !== 'closed' && c.until && c.until < t), high: open.filter(c => c.risk === 'high') };
}
function legalAudit(p, add) {
  const C = legalCalc(p);
  C.late.forEach(c => add('bad', 'Юридические вопросы', 'legal', `Пропущен срок по делу «${esc(c.subject)}» (${esc(c.party)}): был ${fdate(c.deadline)}`));
  C.soon.forEach(c => add('warn', 'Юридические вопросы', 'legal', `Через ${daysBetween(today(), c.deadline)} дн. срок по делу «${esc(c.subject)}» (${esc(c.party)})`));
  if (C.ctrLate.length) add('warn', 'Юридические вопросы', 'legal', `Истёк срок договоров: ${C.ctrLate.slice(0, 4).map(c => esc(c.party)).join(', ')} — продлите или закройте`);
  const od = salesOverdue(p).filter(x => !p.legal.cases.some(c => c.aptId === x.a.id && c.stage !== 'closed'));
  if (od.length) add('warn', 'Юридические вопросы', 'legal', `По ${od.length} просроченным оплатам покупателей не направлены претензии`, { k: 'claims' });
  if (!C.late.length && !C.soon.length && !od.length) add('ok', 'Юридические вопросы', 'legal', `Сроки по делам соблюдаются · открытых дел: ${C.open.length}`);
}
const TPL = {
  buyer: ['Претензия покупателю о просрочке оплаты', c => `ПРЕТЕНЗИЯ\nо погашении задолженности по договору купли-продажи квартиры\n\nКому: ${c.party}\nОт: ${DB.settings.company}\nДата: ${fdate(today())}\n\nМежду ${DB.settings.company} (Продавец) и Вами (Покупатель) заключён договор купли-продажи квартиры ${c.subjectShort || ''} в объекте «${P().name}» с оплатой в рассрочку.\n\nПо состоянию на ${fdate(today())} у Вас образовалась просроченная задолженность в размере ${money(c.amount)}.\n\nНа основании условий договора и норм гражданского законодательства просим погасить задолженность в течение 10 (десяти) календарных дней с момента получения настоящей претензии.\n\nВ случае неоплаты Продавец будет вынужден начислить неустойку, предусмотренную договором, и обратиться в суд с требованием о взыскании задолженности, неустойки и судебных расходов, а также рассмотреть вопрос о расторжении договора.\n\nРеквизиты для оплаты: _______________________\n\nС уважением,\nРуководитель ${DB.settings.company} ______________ / ____________ /`],
  supplier: ['Претензия поставщику о просрочке / недопоставке', c => `ПРЕТЕНЗИЯ\nо нарушении сроков поставки\n\nКому: ${c.party}\nОт: ${DB.settings.company}\nДата: ${fdate(today())}\n\nПо договору поставки № ____ от ________ Поставщик обязался поставить: ${c.subject}.\n\nОбязательство не исполнено в срок / исполнено не полностью. Это привело к простою работ на объекте «${P().name}» и убыткам Покупателя.\n\nТребуем:\n1. Поставить товар в полном объёме в течение 5 (пяти) рабочих дней.\n2. Уплатить неустойку по условиям договора${c.amount ? ` — ${money(c.amount)}` : ''}.\n\nПри неисполнении требований оставляем за собой право расторгнуть договор, взыскать неустойку и убытки в судебном порядке.\n\nОтвет просим направить в течение 10 дней.\n\n______________ / ____________ /`],
  contractor: ['Уведомление подрядчику о недостатках работ', c => `УВЕДОМЛЕНИЕ\nо выявленных недостатках работ\n\nКому: ${c.party}\nОт: ${DB.settings.company}\nДата: ${fdate(today())}\nОбъект: ${P().name}, ${P().address}\n\nПри приёмке работ выявлены недостатки: ${c.subject}.\n\nНедостатки зафиксированы в акте осмотра от ${fdate(today())} с фотофиксацией.\n\nНа основании договора подряда требуем безвозмездно устранить недостатки в срок до ${fdate(addDays(today(), 10))}. До устранения недостатков работы не принимаются и не оплачиваются${c.amount ? `; сумма удержания — ${money(c.amount)}` : ''}.\n\nПри неустранении в срок Заказчик вправе привлечь третьих лиц с отнесением расходов на Подрядчика.\n\n______________ / ____________ /`],
  answer: ['Ответ на претензию', c => `ОТВЕТ НА ПРЕТЕНЗИЮ\n\nКому: ${c.party}\nОт: ${DB.settings.company}\nДата: ${fdate(today())}\n\nРассмотрев Вашу претензию по вопросу «${c.subject}», сообщаем:\n\n1. ______________________________________________\n2. ______________________________________________\n\nС учётом изложенного предлагаем: ______________________ .\nГотовы встретиться для урегулирования вопроса в досудебном порядке.\n\n______________ / ____________ /`],
  safety: ['Предписание об устранении нарушений ТБ', c => `ПРЕДПИСАНИЕ\nоб устранении нарушений требований охраны труда\n\nКому: ${c.party}\nОбъект: ${P().name}\nДата: ${fdate(today())}\n\nПри проверке выявлено: ${c.subject}.\n\nТребуется:\n1. Немедленно прекратить работы с нарушением.\n2. Устранить нарушение до ${fdate(addDays(today(), 1))}.\n3. Провести внеплановый инструктаж работников.\n\nПовторное нарушение влечёт отстранение от работ и дисциплинарную ответственность.\n\nИнженер по охране труда ______________ / ____________ /`],
};
PAGES.legal = {
  title: 'Юрист: договоры, споры, претензии',
  render(p, a) {
    const L = p.legal, C = legalCalc(p), t = today(), tab = a.tab || 'cases', od = salesOverdue(p);
    const tabs = [['cases', '⚖️ Споры и претензии'], ['contracts', '📄 Договоры'], ['docs', '📝 Шаблоны документов']];
    const stName = Object.fromEntries(CASE_STAGE), riskName = Object.fromEntries(CASE_RISK);
    let body = '';
    if (tab === 'cases') body = `
      ${od.filter(x => !L.cases.some(c => c.aptId === x.a.id && c.stage !== 'closed')).length ? `<div class="warnbox">⚠️ Просроченные оплаты покупателей без претензии: ${od.filter(x => !L.cases.some(c => c.aptId === x.a.id && c.stage !== 'closed')).length}. <button class="btn-s btn-p" data-act="lgClaims">Создать претензии автоматически</button></div>` : ''}
      <div class="card"><h3>Споры и претензии <span class="tools"><button class="btn-s btn-p" data-act="lgCaseAdd">+ Дело</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Сторона</th><th>Предмет</th><th class="n">Сумма</th><th>Стадия</th><th>Срок</th><th>Риск</th><th>Ответственный</th><th></th></tr></thead><tbody>
      ${L.cases.slice().sort((x, y) => (x.stage === 'closed') - (y.stage === 'closed') || (x.deadline || '9').localeCompare(y.deadline || '9')).map(c => { const i = L.cases.indexOf(c), late = c.stage !== 'closed' && c.deadline && c.deadline < t;
        return `<tr${c.stage === 'closed' ? ' style="opacity:.55"' : ''}><td><b>${esc(c.party)}</b><div class="small muted">${esc(c.side)}</div></td><td style="min-width:200px">${esc(c.subject)}${c.note ? `<div class="small muted notr">${esc(c.note)}</div>` : ''}</td><td class="n">${c.amount ? money(c.amount) : '—'}</td>
        <td style="width:140px">${cellSel(`legal.cases.${i}.stage`, c.stage, CASE_STAGE)}</td><td class="${late ? 'bad' : ''}" style="white-space:nowrap">${c.deadline ? fdate(c.deadline) : '—'}${late ? '<div class="small">просрочен</div>' : ''}</td>
        <td>${pill(c.risk === 'high' ? 'sold' : c.risk === 'mid' ? 'booked' : 'free', riskName[c.risk] || '—')}</td><td class="small">${esc(c.resp || '')}</td>
        <td style="white-space:nowrap"><button class="btn-s" data-act="lgDoc" data-i="${i}">📝 Документ</button> <button class="btn-s" data-act="lgAskCase" data-i="${i}">🤖</button> <button class="x" data-act="lgDel" data-k="cases" data-id="${c.id}">✕</button></td></tr>`; }).join('') || '<tr><td colspan="8" class="muted">Споров нет</td></tr>'}
      </tbody></table></div></div>`;
    if (tab === 'contracts') body = `<div class="card"><h3>Реестр договоров <span class="tools"><button class="btn-s btn-p" data-act="lgCtrAdd">+ Договор</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Контрагент</th><th>Вид</th><th>№ / дата</th><th class="n">Сумма</th><th>Действует до</th><th>Статус</th><th></th></tr></thead><tbody>
      ${L.contracts.map((c, i) => { const late = c.status !== 'closed' && c.until && c.until < t, soon = C.ctrSoon.includes(c); return `<tr><td><b>${esc(c.party)}</b></td><td class="small">${esc(c.type)}</td><td class="small">№ ${esc(c.num || '—')} от ${fdate(c.date)}</td><td class="n">${money(c.amount)}</td>
        <td class="${late ? 'bad' : soon ? 'warn-t' : ''}">${c.until ? fdate(c.until) : '—'}${late ? ' · истёк' : soon ? ' · скоро' : ''}</td><td style="width:130px">${cellSel(`legal.contracts.${i}.status`, c.status, [['active', 'Действует'], ['closed', 'Исполнен / закрыт'], ['dispute', 'Спор']])}</td><td><button class="x" data-act="lgDel" data-k="contracts" data-id="${c.id}">✕</button></td></tr>`; }).join('') || '<tr><td colspan="7" class="muted">Договоров нет</td></tr>'}
      </tbody></table></div></div>`;
    if (tab === 'docs') { const k = a.tpl || 'buyer', c = L.cases[+a.ci] || { party: '________', subject: '________', amount: 0 };
      body = `<div class="card"><h3>Шаблоны документов</h3><div class="form mb">
        <label class="fld"><span>Документ</span><select data-arg="tpl">${opts(Object.entries(TPL).map(([k2, v]) => [k2, v[0]]), k)}</select></label>
        <label class="fld"><span>По делу</span><select data-arg="ci"><option value="">— заполнить вручную —</option>${L.cases.map((x, i) => `<option value="${i}"${String(i) === String(a.ci) ? ' selected' : ''}>${esc(x.party)}: ${esc(x.subject)}</option>`).join('')}</select></label></div>
        <textarea class="ad-text notr" id="docText" rows="20">${esc(TPL[k][1](c))}</textarea>
        <div class="flex mt"><button class="btn-p" data-act="docCopy">📋 Скопировать</button><button data-act="docSave">⬇ Скачать .txt</button><button data-act="lgAiDoc">🤖 Проверить и улучшить с ИИ-юристом</button></div>
        <div class="small muted mt">Шаблон — основа. Перед отправкой проверьте реквизиты, номера договоров и суммы; важные документы согласуйте с юристом.</div></div>`; }
    return `<div class="kpis">
      ${kpi('Открытых дел', C.open.length, 'высокий риск: ' + C.high.length, C.high.length ? 'bad' : 'bl')}
      ${kpi('Сумма требований', moneyS(C.claimed), 'по открытым делам', 'ac')}
      ${kpi('Сроки на этой неделе', C.soon.length, C.late.length ? `<span class="bad">просрочено: ${C.late.length}</span>` : 'просрочек нет', C.late.length ? 'bad' : 'ok')}
      ${kpi('Договоров', L.contracts.length, 'истекают в 30 дней: ' + C.ctrSoon.length, C.ctrLate.length ? 'ac' : 'ok')}
    </div>
    <div class="flex mb seg">${tabs.map(([k2, l]) => `<button class="${k2 === tab ? 'btn-p' : ''}" data-act="lgTab" data-tab="${k2}">${l}</button>`).join('')}<span class="right"></span><button data-act="aiAskPersona" data-persona="lawyer" data-q="Проанализируй юридические риски проекта: споры, претензии, договоры, просроченные оплаты покупателей. Что делать в первую очередь и какие сроки нельзя пропустить?">🤖 Спросить ИИ-юриста</button></div>
    ${body}
    <div class="small muted">⚖️ ИИ-юрист и шаблоны помогают подготовиться, но не заменяют консультацию адвоката. По спорам в суде и крупным суммам привлекайте лицензированного юриста.</div>`;
  }
};
ACT.lgTab = d => { pageArgs = { tab: d.tab }; rerender(); };
ACT.lgDel = async d => { if (!await ui.confirm(T('Удалить запись?'))) return; const p = P(); p.legal[d.k] = p.legal[d.k].filter(x => x.id !== d.id); save(); rerender(); };
ACT.lgDoc = d => { const c = P().legal.cases[+d.i], k = /Покупатель/.test(c.side) ? (c.aptId ? 'buyer' : 'answer') : /Поставщик/.test(c.side) ? 'supplier' : /Подрядчик/.test(c.side) ? 'contractor' : 'answer'; pageArgs = { tab: 'docs', tpl: k, ci: d.i }; rerender(); };
ACT.lgAskCase = d => { const c = P().legal.cases[+d.i]; aiAsk(`${T('Спорная ситуация')}: ${c.side} «${c.party}», ${T('предмет')}: ${c.subject}, ${T('сумма')}: ${money(c.amount)}, ${T('стадия')}: ${Object.fromEntries(CASE_STAGE)[c.stage]}. ${c.note || ''} ${T('Оцени позицию компании, риски, какие доказательства собрать и пошаговый план действий со сроками.')}`, 'lawyer'); };
ACT.lgAiDoc = () => aiAsk(T('Проверь этот юридический документ, исправь ошибки, усили позицию компании и укажи, что нужно дописать:') + '\n\n' + $('#docText').value, 'lawyer');
ACT.docCopy = () => { const t = $('#docText'); t.select(); (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.reject()).then(() => toast('Текст скопирован'), () => { document.execCommand('copy'); toast('Текст скопирован'); }); };
ACT.docSave = () => download(`dokument_${today()}.txt`, '﻿' + $('#docText').value, 'text/plain');
function createClaims(p) {
  let n = 0; salesOverdue(p).forEach(({ a, debt }) => {
    if (p.legal.cases.some(c => c.aptId === a.id && c.stage !== 'closed')) return;
    p.legal.cases.push({ id: uid(), aptId: a.id, party: a.buyer || 'Покупатель кв. №' + a.num, side: 'Покупатель квартиры', subject: `Просрочка оплаты кв. №${a.num}`, subjectShort: `№${a.num} (${a.area} м², этаж ${a.floor})`, amount: debt, stage: 'claim', opened: today(), deadline: addDays(today(), 30), risk: debt > 100e6 ? 'mid' : 'low', resp: '', note: a.o.plan || '' }); n++;
  });
  return n;
}
ACT.lgClaims = () => { const n = createClaims(P()); save(); rerender(); toast(`Создано претензий: ${n}. Срок ответа — 30 дней`); };
ACT.lgCaseAdd = () => openModal(`<h2>Новое дело / спор</h2><div class="form mt">
  <label class="fld"><span>Сторона</span><select id="lcSide">${opts(CASE_SIDE)}</select></label>
  <label class="fld"><span>Название / ФИО</span><input class="txt" id="lcParty"></label>
  <label class="fld" style="grid-column:1/-1"><span>Предмет спора</span><input class="txt" id="lcSubj" placeholder="например: недопоставка арматуры 20 т"></label>
  <label class="fld"><span>Сумма требования, ${cur()}</span><input class="txt" type="number" id="lcAmt"></label>
  <label class="fld"><span>Стадия</span><select id="lcStage">${opts(CASE_STAGE)}</select></label>
  <label class="fld"><span>Срок (ответ / заседание)</span><input class="txt" type="date" id="lcDead" value="${addDays(today(), 30)}"></label>
  <label class="fld"><span>Риск</span><select id="lcRisk">${opts(CASE_RISK, 'mid')}</select></label>
  <label class="fld"><span>Ответственный</span><input class="txt" id="lcResp"></label>
  <label class="fld" style="grid-column:1/-1"><span>Примечание</span><input class="txt" id="lcNote"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="lgCaseSave">Сохранить</button></div>`);
ACT.lgCaseSave = () => { const party = $('#lcParty').value.trim(), subject = $('#lcSubj').value.trim(); if (!party || !subject) return toast('Заполните сторону и предмет'); P().legal.cases.push({ id: uid(), side: $('#lcSide').value, party, subject, amount: num($('#lcAmt').value), stage: $('#lcStage').value, opened: today(), deadline: $('#lcDead').value, risk: $('#lcRisk').value, resp: $('#lcResp').value.trim(), note: $('#lcNote').value.trim() }); save(); closeModal(); rerender(); toast('Сохранено'); };
ACT.lgCtrAdd = () => openModal(`<h2>Новый договор</h2><div class="form mt">
  <label class="fld"><span>Контрагент</span><input class="txt" id="ctParty"></label>
  <label class="fld"><span>Вид договора</span><select id="ctType">${opts(CTR_TYPES)}</select></label>
  <label class="fld"><span>Номер</span><input class="txt" id="ctNum"></label>
  <label class="fld"><span>Дата</span><input class="txt" type="date" id="ctDate" value="${today()}"></label>
  <label class="fld"><span>Сумма, ${cur()}</span><input class="txt" type="number" id="ctAmt"></label>
  <label class="fld"><span>Действует до</span><input class="txt" type="date" id="ctUntil" value="${addDays(today(), 365)}"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="lgCtrSave">Сохранить</button></div>`);
ACT.lgCtrSave = () => { const party = $('#ctParty').value.trim(); if (!party) return toast('Введите контрагента'); P().legal.contracts.push({ id: uid(), party, type: $('#ctType').value, num: $('#ctNum').value.trim(), date: $('#ctDate').value, amount: num($('#ctAmt').value), until: $('#ctUntil').value, status: 'active' }); save(); closeModal(); rerender(); toast('Сохранено'); };
function legalDemo(p) {
  const t = today(), s = p.startDate, L = p.legal;
  [['ООО «БетонСтрой»', 'Поставка материалов', 'П-14', s, 6500000000, addDays(t, 200)], ['ООО «Металл-Инвест»', 'Поставка материалов', 'П-15', s, 3800000000, addDays(t, 25)], ['ООО «СпецТехника»', 'Аренда техники', 'А-03', addDays(s, 10), 1500000000, addDays(t, -5)], ['ООО «ГеоПроект»', 'Проектирование', 'ПР-1', addDays(s, -30), 520000000, addDays(t, 90)], ['ЧП «Газоблок»', 'Поставка материалов', 'П-22', addDays(s, 150), 900000000, addDays(t, 120)]]
    .forEach(([party, type, num, date, amount, until]) => L.contracts.push({ id: uid(), party, type, num, date, amount, until, status: 'active' }));
  L.cases.push({ id: uid(), side: 'Поставщик', party: 'ООО «Металл-Инвест»', subject: 'Недопоставка арматуры А500С — 18 т, простой бригады 4 дня', amount: 171000000, stage: 'talks', opened: addDays(t, -12), deadline: addDays(t, 4), risk: 'mid', resp: 'Нодира Алиева', note: 'Поставщик ссылается на задержку на заводе' });
  L.cases.push({ id: uid(), side: 'Соседи / третьи лица', party: 'Жильцы дома №17', subject: 'Жалоба на шум в ночное время и трещины в отмостке', amount: 0, stage: 'claim', opened: addDays(t, -6), deadline: addDays(t, 24), risk: 'low', resp: 'Алишер Каримов', note: '' });
}
