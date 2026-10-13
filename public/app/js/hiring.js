'use strict';
/* ===== Найм сотрудников: вакансии, кандидаты, приём на работу ===== */
const CAND_ST = [['new', 'Новые отклики', '#64748b'], ['interview', 'Собеседование', '#2563eb'], ['trial', 'Испытательный срок', '#d97706'], ['hired', 'Приняты', '#16a34a'], ['rejected', 'Отказ', '#dc2626']];
const CAND_SRC = ['Telegram', 'OLX', 'hh.uz / сайты', 'Знакомые / рекомендация', 'Пришёл сам', 'Биржа труда', 'Другое'];
const isVacancy = s => !!s.isNew;
function vacancies(p) {
  const v = {}; p.staff.filter(s => s.active !== false && isVacancy(s)).forEach(s => { (v[s.role] = v[s.role] || { role: s.role, n: 0, rate: s.rate }).n++; });
  return Object.values(v).sort((a, b) => b.n - a.n);
}
const roleTr = (role, l) => (l === 'ru' ? role : (L10N[l] && L10N[l][role]) || role);
function vacAd(p, role, n, rate, l) {
  const nh = DB.settings.normHours, month = Math.round(rate * nh * 26 / 10000) * 10000, ph = DB.settings.hrPhone || '+998 __ ___ __ __';
  const r = roleTr(role, l), proj = p.name, addr = p.address || '';
  if (l === 'uz') return `🏗 ISHGA TAKLIF: ${r} — ${n} kishi\n📍 Obyekt: ${proj}${addr ? ', ' + addr : ''}\n💰 Maosh: ${fmt(rate)} so'm/soat (≈ ${fmt(month)} so'm/oy), har oy to'lanadi\n🕗 Jadval: haftasiga 6 kun, smena ${nh} soat, qo'shimcha ish ×${DB.settings.overtimeK}\n✅ Rasmiy ishga joylashtirish, maxsus kiyim, tushlik\n📞 Tel.: ${ph}\n— ${DB.settings.company}`;
  if (l === 'tg') return `🏗 ДАЪВАТ БА КОР: ${r} — ${n} нафар\n📍 Объект: ${proj}${addr ? ', ' + addr : ''}\n💰 Музд: ${fmt(rate)} сум/соат (≈ ${fmt(month)} сум/моҳ), ҳар моҳ пардохт мешавад\n🕗 Ҷадвал: 6 рӯз дар ҳафта, баст ${nh} соат, изофакорӣ ×${DB.settings.overtimeK}\n✅ Ба кор қабули расмӣ, либоси махсус, хӯроки нисфирӯзӣ\n📞 Тел.: ${ph}\n— ${DB.settings.company}`;
  return `🏗 ТРЕБУЮТСЯ: ${r} — ${n} чел.\n📍 Объект: ${proj}${addr ? ', ' + addr : ''}\n💰 Оплата: ${fmt(rate)} ${cur()}/час (≈ ${fmt(month)} ${cur()}/мес), выплаты каждый месяц\n🕗 График: 6 дней в неделю, смена ${nh} ч, сверхурочные ×${DB.settings.overtimeK}\n✅ Официальное оформление, спецодежда, обед\n📞 Тел.: ${ph}\n— ${DB.settings.company}`;
}
PAGES.hiring = {
  title: 'Найм сотрудников',
  render(p, a) {
    const V = vacancies(p), C = p.cands, totalVac = sum(V, v => v.n), m = monthNow();
    const hiredM = C.filter(c => c.status === 'hired' && (c.hiredAt || '').startsWith(m)).length;
    const roleSel = a.vrole && V.find(v => v.role === a.vrole) ? a.vrole : (V[0] && V[0].role);
    const vSel = V.find(v => v.role === roleSel);
    const adLang = a.adl || (curLang() === 'ru' ? 'ru' : curLang());
    return `
    <div class="kpis">
      ${kpi('Открытых вакансий', fmt(totalVac), V.length + ' профессий', totalVac ? 'bad' : 'ok')}
      ${kpi('Кандидатов в работе', C.filter(c => ['new', 'interview', 'trial'].includes(c.status)).length, 'всего откликов ' + C.length, 'bl')}
      ${kpi('На собеседовании', C.filter(c => c.status === 'interview').length, 'на испытании ' + C.filter(c => c.status === 'trial').length, 'ac')}
      ${kpi('Принято в этом месяце', hiredM, monthName(m), 'ok')}
    </div>
    <div class="grid g2">
      <div class="card"><h3>Открытые вакансии <span class="tools"><button class="btn-s" data-act="vacAdd">+ Вакансия</button></span></h3>
        ${V.length ? `<div class="tbl-wrap"><table><thead><tr><th>Профессия</th><th class="n">Нужно</th><th class="n">Ставка/час</th><th class="n">Кандидатов</th><th></th></tr></thead><tbody>
          ${V.map(v => { const nc = C.filter(c => c.role === v.role && ['new', 'interview', 'trial'].includes(c.status)).length; return `<tr${v.role === roleSel ? ' style="background:#fff8ec"' : ''}><td><b>${esc(v.role)}</b></td><td class="n"><b>${v.n}</b></td><td class="n">${fmt(v.rate)}</td><td class="n">${nc}</td>
            <td style="white-space:nowrap"><button class="btn-s" data-act="vacPick" data-role="${esc(v.role)}">📢 Объявление</button> <button class="btn-s btn-p" data-act="candAdd" data-role="${esc(v.role)}">+ Кандидат</button> <button class="x" data-act="vacClose" data-role="${esc(v.role)}" title="Закрыть одну вакансию">−1</button></td></tr>`; }).join('')}
        </tbody></table></div>` : '<div class="note">Открытых вакансий нет. Вакансии появляются, когда ИИ-консультант находит нехватку людей (кнопка «Исправить»), или их можно добавить вручную.</div>'}
      </div>
      <div class="card"><h3>Объявление о работе <span class="tools">${vSel ? `<select data-arg="adl">${opts(LANGS, adLang)}</select>` : ''}</span></h3>
        ${vSel ? `<div class="small muted mb">Готовый текст для Telegram-каналов, OLX, досок объявлений. Выберите вакансию слева.</div>
          <textarea class="ad-text notr" id="adText" rows="9">${esc(vacAd(p, vSel.role, vSel.n, vSel.rate, adLang))}</textarea>
          <div class="flex mt"><button class="btn-p" data-act="adCopy">📋 Скопировать</button><button data-act="aiHireAsk" data-role="${esc(vSel.role)}">🤖 ИИ: вопросы для собеседования</button>
          <label class="fld" style="flex-direction:row;align-items:center;gap:6px">Телефон отдела кадров: <input class="txt" data-bind="S:hrPhone" value="${esc(DB.settings.hrPhone || '')}" placeholder="+998" data-re style="width:160px"></label></div>`
        : '<p class="muted">Нет вакансий для объявления.</p>'}
      </div>
    </div>
    <div class="card"><h3>Кандидаты: путь от отклика до приёма <span class="tools"><button class="btn-s btn-p" data-act="candAdd">+ Кандидат</button></span></h3>
      <div class="kanban">${CAND_ST.map(([st, t, col]) => { const list = C.filter(c => c.status === st).sort((x, y) => (y.date || '').localeCompare(x.date || '')); return `<div class="kcol"><div class="kcol-h" style="border-color:${col}">${t} <span>${list.length}</span></div>
        ${list.map(c => `<div class="kcard"><b>${esc(c.name)}</b><div class="small">${esc(c.role)}${c.exp ? ' · ' + c.exp + ' лет опыта' : ''}</div><div class="small muted">${esc(c.phone || '')}${c.src ? ' · ' + esc(c.src) : ''}</div>${c.note ? `<div class="small notr">${esc(c.note)}</div>` : ''}
          <div class="kbtns">${st === 'new' ? `<button class="btn-s" data-act="candMove" data-id="${c.id}" data-st="interview">→ Собеседование</button>` : ''}${st === 'interview' ? `<button class="btn-s" data-act="candMove" data-id="${c.id}" data-st="trial">→ Испытание</button>` : ''}${['new', 'interview', 'trial'].includes(st) ? `<button class="btn-s btn-p" data-act="candHire" data-id="${c.id}">✅ Принять</button><button class="btn-s btn-d" data-act="candMove" data-id="${c.id}" data-st="rejected">Отказ</button>` : ''}<button class="x" data-act="candDel" data-id="${c.id}">✕</button></div></div>`).join('') || '<div class="small muted" style="padding:8px">—</div>'}</div>`; }).join('')}</div>
    </div>`;
  }
};
ACT.vacPick = d => { pageArgs.vrole = d.role; rerender(); };
ACT.vacClose = async d => { const p = P(), s = p.staff.find(x => isVacancy(x) && x.role === d.role && x.active !== false); if (!s || !await ui.confirm(T('Закрыть одну вакансию?') + ' ' + d.role)) return; p.staff = p.staff.filter(x => x !== s); save(); rerender(); };
ACT.vacAdd = () => openModal(`<h2>Новая вакансия</h2><div class="form mt">
  <label class="fld"><span>Профессия</span><select id="vRole">${opts(ROLES, 'Бетонщик')}</select></label>
  <label class="fld"><span>Сколько человек нужно</span><input class="txt" type="number" id="vN" value="1" min="1"></label>
  <label class="fld"><span>Ставка в час, ${cur()}</span><input class="txt" type="number" id="vRate" placeholder="по средней в штате"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="vacSave">Открыть вакансию</button></div>`);
ACT.vacSave = () => { const p = P(), role = $('#vRole').value, n = Math.max(1, num($('#vN').value)), list = hire(p, role, n); const r = num($('#vRate').value); if (r) list.forEach(s => s.rate = r); pageArgs.vrole = role; save(); closeModal(); rerender(); toast('Вакансия открыта'); };
ACT.candAdd = d => openModal(`<h2>Новый кандидат</h2><div class="form mt">
  <label class="fld"><span>ФИО</span><input class="txt" id="cName"></label>
  <label class="fld"><span>Профессия</span><select id="cRole">${opts(ROLES, d.role || pageArgs.vrole || 'Бетонщик')}</select></label>
  <label class="fld"><span>Телефон</span><input class="txt" id="cPhone" placeholder="+998"></label>
  <label class="fld"><span>Опыт, лет</span><input class="txt" type="number" id="cExp"></label>
  <label class="fld"><span>Желаемая ставка в час, ${cur()}</span><input class="txt" type="number" id="cRate"></label>
  <label class="fld"><span>Откуда узнал</span><select id="cSrc">${opts(CAND_SRC)}</select></label>
  <label class="fld" style="grid-column:1/-1"><span>Примечание</span><input class="txt" id="cNote" placeholder="разряд, где работал, документы…"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="candSave">Добавить кандидата</button></div>`);
ACT.candSave = () => {
  const name = $('#cName').value.trim(); if (!name) return toast('Введите ФИО');
  P().cands.push({ id: uid(), name, role: $('#cRole').value, phone: $('#cPhone').value.trim(), exp: num($('#cExp').value) || '', rate: num($('#cRate').value) || 0, src: $('#cSrc').value, note: $('#cNote').value.trim(), status: 'new', date: today() });
  save(); closeModal(); rerender(); toast('Кандидат добавлен');
};
ACT.candMove = d => { const c = P().cands.find(x => x.id === d.id); if (!c) return; c.status = d.st; c.date = today(); save(); rerender(); };
ACT.candDel = async d => { if (!await ui.confirm(T('Удалить кандидата?'))) return; const p = P(); p.cands = p.cands.filter(x => x.id !== d.id); save(); rerender(); };
ACT.candHire = d => {
  const p = P(), c = p.cands.find(x => x.id === d.id); if (!c) return;
  let s = p.staff.find(x => isVacancy(x) && x.role === c.role && x.active !== false);
  if (s) { s.name = c.name; s.phone = c.phone; if (c.rate) s.rate = c.rate; s.isNew = false; s.hired = today(); }
  else { s = { id: uid(), name: c.name, role: c.role, phone: c.phone, rate: c.rate || roleRate(p, c.role), active: true, hired: today() }; p.staff.push(s); }
  c.status = 'hired'; c.hiredAt = today(); c.staffId = s.id; save(); rerender(); toast(`${c.name} принят(а) на работу: ${c.role}`);
};
ACT.adCopy = () => { const t = $('#adText'); t.select(); (navigator.clipboard ? navigator.clipboard.writeText(t.value) : Promise.reject()).then(() => toast('Текст скопирован'), () => { document.execCommand('copy'); toast('Текст скопирован'); }); };
ACT.aiHireAsk = d => { go('ai'); aiAsk(T('Составь вопросы для собеседования и практическую проверку навыков для профессии') + ': ' + T(d.role) + '. ' + T('Что проверить в документах, на что обратить внимание по охране труда, какую ставку предложить по данным проекта.')); };
