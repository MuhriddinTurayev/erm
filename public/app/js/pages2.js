'use strict';
/* ===== Страницы: сотрудники и табель, техника, график, бухгалтерия, продажи, дизайн, настройки ===== */

/* ---------- Сотрудники, табель, зарплата ---------- */
function earned(p, s, month) {
  const ts = p.ts[s.id] || {}, nh = DB.settings.normHours, ok = DB.settings.overtimeK; let h = 0, amt = 0, days = 0, ot = 0;
  for (const d in ts) { if (!d.startsWith(month)) continue; const v = num(ts[d]); if (!v) continue; h += v; days++; ot += Math.max(0, v - nh); amt += Math.min(v, nh) * s.rate + Math.max(0, v - nh) * s.rate * ok; }
  return { h, sum: amt, days, ot };
}
const paidFor = (p, sid, month) => sum(p.pays.filter(x => x.staffId === sid && x.month === month), x => x.amount);
PAGES.staff = {
  title: 'Сотрудники, табель учёта часов и зарплата',
  fullOnChange: el => !el.dataset.bind.startsWith('ts.'),
  render(p, a) {
    const month = a.month || monthNow(), role = a.role || '';
    const [y, mm] = month.split('-').map(Number), dim = new Date(y, mm, 0).getDate();
    const list = p.staff.filter(s => !role || s.role === role);
    const act = list.filter(s => s.active !== false && !s.isNew);
    const E = Object.fromEntries(p.staff.map(s => [s.id, earned(p, s, month)]));
    const totH = sum(act, s => E[s.id].h), totS = sum(act, s => E[s.id].sum), totP = sum(act, s => paidFor(p, s.id, month));
    const byRole = {}; act.forEach(s => { const r = byRole[s.role] = byRole[s.role] || { n: 0, h: 0, s: 0 }; r.n++; r.h += E[s.id].h; r.s += E[s.id].sum; });
    const roles = [...new Set(p.staff.map(s => s.role))];
    const days = []; for (let d = 1; d <= dim; d++) { const ds = `${month}-${String(d).padStart(2, '0')}`; days.push({ d, ds, we: new Date(ds + 'T00:00:00').getDay() === 0 }); }
    const pays = p.pays.filter(x => x.month === month).sort((x, y) => y.date.localeCompare(x.date));
    return `
    <div class="flex mb"><label class="flex">Месяц: <input class="txt" type="month" data-arg="month" value="${month}"></label>
      <label class="flex">Профессия: <select data-arg="role"><option value="">Все</option>${opts(roles, role)}</select></label>
      <span class="right"></span><button class="btn-p" data-act="staffAdd">+ Сотрудник</button></div>
    <div class="kpis">
      ${kpi('Работает сотрудников', act.length, 'ИТР ' + act.filter(s => ITR_ROLES.has(s.role)).length + ' · рабочих ' + act.filter(s => !ITR_ROLES.has(s.role)).length, 'ac')}
      ${kpi('Отработано часов', fmt(totH), monthName(month))}
      ${kpi('Начислено', moneyS(totS), 'с учётом сверхурочных ×' + DB.settings.overtimeK, 'bl')}
      ${kpi('Выплачено', moneyS(totP), '', 'ok')}
      ${kpi('К выплате', moneyS(totS - totP), '', totS - totP > 0 ? 'bad' : 'ok')}
    </div>
    <div class="card"><h3>Сотрудники <span class="tools"><button class="btn-s" data-act="staffCsv" data-month="${month}">⬇ Ведомость (CSV)</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>ФИО</th><th>Должность</th><th>Телефон</th><th class="n">Ставка/час</th><th class="n">Часов</th><th class="n">Начислено</th><th class="n">Выплачено</th><th class="n">К выплате</th><th>Работает</th><th></th></tr></thead><tbody>
      ${list.map(s => { const i = p.staff.indexOf(s), e = E[s.id], pd = paidFor(p, s.id, month), due = e.sum - pd; return `<tr${s.active === false ? ' style="opacity:.5"' : ''}>
        <td style="min-width:170px">${cell(`staff.${i}.name`, s.name, { type: 'text' })}${s.isNew ? '<span class="pill p-booked" title="Место под набор — примите кандидата в разделе «Найм»">вакансия</span>' : ''}</td><td style="min-width:170px">${cellSel(`staff.${i}.role`, s.role, ROLES)}</td>
        <td style="min-width:150px">${cell(`staff.${i}.phone`, s.phone, { type: 'text' })}</td><td class="n" style="min-width:105px">${cell(`staff.${i}.rate`, s.rate)}</td>
        <td class="n">${fmt(e.h)}${e.ot ? `<div class="small muted">сверхур. ${fmt(e.ot)}</div>` : ''}</td><td class="n">${money(e.sum)}</td><td class="n">${money(pd)}</td>
        <td class="n ${due > 0 ? 'bad' : 'ok'}"><b>${money(due)}</b></td><td style="text-align:center"><input type="checkbox" data-bind="staff.${i}.active" ${s.active !== false ? 'checked' : ''}></td>
        <td style="white-space:nowrap">${due > 0 ? `<button class="btn-s" data-act="payStaff" data-id="${s.id}" data-month="${month}" title="Выплатить зарплату">💵 Выплата</button>` : ''} <button class="x" data-act="staffDel" data-id="${s.id}">✕</button></td></tr>`; }).join('') || '<tr><td colspan="10" class="muted">Нет сотрудников</td></tr>'}
      </tbody></table></div></div>
    <div class="card"><h3>Табель учёта рабочего времени — ${monthName(month)} <span class="tools"><button class="btn-s" data-act="tsFill" data-month="${month}">Заполнить рабочие дни (${DB.settings.normHours} ч)</button><button class="btn-s btn-d" data-act="tsClear" data-month="${month}">Очистить месяц</button></span></h3>
      <div class="small muted mb">Вводите часы за день. Больше ${DB.settings.normHours} ч — сверхурочные (×${DB.settings.overtimeK}). Воскресенья выделены.</div>
      <div class="tbl-wrap"><table class="ts"><thead><tr><th class="nm">Сотрудник</th>${days.map(x => `<th class="${x.we ? 'we' : ''}">${x.d}</th>`).join('')}<th class="n">Часы</th><th class="n">Сумма</th></tr></thead><tbody>
      ${act.map(s => { const ts = p.ts[s.id] || {}; return `<tr><td class="nm">${esc(s.name)}<div class="small muted">${esc(s.role)}</div></td>${days.map(x => `<td class="${x.we ? 'we' : ''}"><input class="cell" inputmode="decimal" data-num data-bind="ts.${s.id}.${x.ds}" value="${ts[x.ds] ?? ''}"></td>`).join('')}<td class="n" id="tsH_${s.id}"><b>${fmt(E[s.id].h)}</b></td><td class="n" id="tsS_${s.id}">${moneyS(E[s.id].sum)}</td></tr>`; }).join('')}
      </tbody></table></div></div>
    <div class="grid g2">
      <div class="card"><h3>Итоги по профессиям</h3><div class="tbl-wrap"><table><thead><tr><th>Профессия</th><th class="n">Чел.</th><th class="n">Часов</th><th class="n">Начислено</th></tr></thead><tbody>
        ${Object.entries(byRole).sort((a, b) => b[1].s - a[1].s).map(([r, v]) => `<tr><td>${esc(r)}</td><td class="n">${v.n}</td><td class="n">${fmt(v.h)}</td><td class="n">${money(v.s)}</td></tr>`).join('')}
      </tbody></table></div></div>
      <div class="card"><h3>Выплаты за ${monthName(month)}</h3><div class="tbl-wrap"><table><tbody>
        ${pays.map(x => { const s = p.staff.find(z => z.id === x.staffId); return `<tr><td class="small muted">${fdate(x.date)}</td><td>${esc(s?.name || '—')}</td><td class="n">${money(x.amount)}</td><td><button class="x" data-act="payDel" data-id="${x.id}">✕</button></td></tr>`; }).join('') || '<tr><td class="muted">Выплат пока нет</td></tr>'}
      </tbody></table></div></div>
    </div>`;
  },
  onInput(el) {
    const b = el.dataset.bind; if (!b.startsWith('ts.')) return;
    const p = P(), sid = b.split('.')[1], s = p.staff.find(x => x.id === sid), e = earned(p, s, pageArgs.month || monthNow());
    const h = $('#tsH_' + sid), m = $('#tsS_' + sid); if (h) h.innerHTML = `<b>${fmt(e.h)}</b>`; if (m) m.textContent = moneyS(e.sum);
  }
};
ACT.staffAdd = () => openModal(`<h2>Новый сотрудник</h2><div class="form mt">
  <label class="fld"><span>ФИО</span><input class="txt" id="sName"></label>
  <label class="fld"><span>Должность</span><select id="sRole">${opts(ROLES, 'Бетонщик')}</select></label>
  <label class="fld"><span>Телефон</span><input class="txt" id="sPhone" placeholder="+998"></label>
  <label class="fld"><span>Ставка в час, ${cur()}</span><input class="txt" type="number" id="sRate" value="30000"></label></div>
  <div class="flex mt"><button class="btn-p" data-act="staffSave">Добавить</button></div>`);
ACT.staffSave = () => {
  const name = $('#sName').value.trim(); if (!name) return toast('Введите ФИО');
  P().staff.push({ id: uid(), name, role: $('#sRole').value, phone: $('#sPhone').value, rate: num($('#sRate').value), active: true, hired: today() });
  save(); closeModal(); rerender(); toast('Сотрудник добавлен');
};
ACT.staffDel = async d => { const p = P(), s = p.staff.find(x => x.id === d.id); if (!await ui.confirm(`Удалить сотрудника «${s.name}» и его табель? (Лучше снять галочку «Работает»)`)) return; p.staff = p.staff.filter(x => x.id !== d.id); delete p.ts[d.id]; save(); rerender(); };
ACT.payStaff = d => {
  const p = P(), s = p.staff.find(x => x.id === d.id), due = Math.round(earned(p, s, d.month).sum - paidFor(p, s.id, d.month));
  openModal(`<h2>Выплата зарплаты</h2><p>${esc(s.name)} · ${esc(s.role)} · ${monthName(d.month)}</p><div class="form">
   <label class="fld"><span>Сумма, ${cur()}</span><input class="txt" type="number" id="payAmt" value="${due}"></label>
   <label class="fld"><span>Дата</span><input class="txt" type="date" id="payDate" value="${today()}"></label></div>
   <p class="small muted">Выплата автоматически попадёт в бухгалтерию (статья «${ITR_ROLES.has(s.role) ? 'Зарплата ИТР' : 'Зарплата рабочих'}»).</p>
   <button class="btn-p" data-act="payDo" data-id="${s.id}" data-month="${d.month}">Выплатить</button>`);
};
ACT.payDo = d => {
  const p = P(), s = p.staff.find(x => x.id === d.id), amount = num($('#payAmt').value), date = $('#payDate').value || today(); if (amount <= 0) return toast('Введите сумму');
  const id = uid(), tx = addTx(p, { date, type: 'out', cat: ITR_ROLES.has(s.role) ? 'Зарплата ИТР' : 'Зарплата рабочих', amount, who: s.name, desc: `Зарплата за ${monthName(d.month)}`, link: { kind: 'pay', id } });
  p.pays.push({ id, staffId: s.id, month: d.month, date, amount, txId: tx.id }); save(); closeModal(); rerender(); toast('Выплата проведена');
};
ACT.payDel = async d => { const p = P(), x = p.pays.find(z => z.id === d.id); if (!x || !await ui.confirm('Отменить выплату?')) return; p.pays = p.pays.filter(z => z.id !== d.id); p.tx = p.tx.filter(t => t.id !== x.txId); save(); rerender(); };
ACT.tsFill = d => {
  const p = P(), [y, m] = d.month.split('-').map(Number), dim = new Date(y, m, 0).getDate(), t = today(); let n = 0;
  p.staff.filter(s => s.active !== false).forEach(s => {
    const ts = p.ts[s.id] = p.ts[s.id] || {};
    for (let i = 1; i <= dim; i++) { const ds = `${d.month}-${String(i).padStart(2, '0')}`; if (d.month === monthNow() && ds > t) break; if (new Date(ds + 'T00:00:00').getDay() === 0 || ts[ds] != null) continue; ts[ds] = DB.settings.normHours; n++; }
  });
  save(); rerender(); toast(`Заполнено ${n} записей`);
};
ACT.tsClear = async d => { if (!await ui.confirm('Очистить табель за ' + monthName(d.month) + '?')) return; const p = P(); for (const sid in p.ts) for (const ds in p.ts[sid]) if (ds.startsWith(d.month)) delete p.ts[sid][ds]; save(); rerender(); };
ACT.staffCsv = d => { const p = P(); download(`zarplata_${d.month}.csv`, csv([['ФИО', 'Должность', 'Телефон', 'Ставка/ч', 'Часов', 'Начислено', 'Выплачено', 'К выплате'], ...p.staff.map(s => { const e = earned(p, s, d.month), pd = paidFor(p, s.id, d.month); return [s.name, s.role, s.phone, s.rate, e.h, Math.round(e.sum), pd, Math.round(e.sum - pd)]; })]), 'text/csv'); };

/* ---------- Техника и топливо ---------- */
function vehStats(p, v, month) {
  const fl = p.fuel.filter(f => f.vid === v.id && (!month || f.date.startsWith(month)));
  const qty = sum(fl, f => f.qty), liters = sum(fl, f => f.liters), cost = sum(fl, f => f.liters * f.price);
  const normL = v.mode === 'h' ? qty * v.norm : qty * v.norm / 100;
  return { qty, liters, cost, normL, dev: liters - normL, devPct: normL ? (liters - normL) / normL * 100 : 0, n: fl.length };
}
PAGES.fleet = {
  title: 'Техника, машины и расход топлива',
  fullOnChange: true,
  render(p, a) {
    const month = a.month ?? monthNow(), Mc = machCalc(p);
    const st = p.fleet.map(v => ({ v, s: vehStats(p, v, month) }));
    const L = sum(st, x => x.s.liters), C = sum(st, x => x.s.cost), N = sum(st, x => x.s.normL);
    const log = p.fuel.filter(f => !month || f.date.startsWith(month)).sort((x, y) => y.date.localeCompare(x.date));
    const names = Object.fromEntries(p.fleet.map(v => [v.id, v]));
    return `
    <div class="kpis">
      ${kpi('Техника в автопарке', p.fleet.length + ' ед.', 'по расчёту нужно ≈ ' + fmt(Mc.units) + ' ед.', 'ac')}
      ${kpi('Заправлено топлива', fmt(L) + ' л', month ? monthName(month) : 'за всё время')}
      ${kpi('Затраты на топливо', moneyS(C), 'цена ' + fmt(DB.settings.fuelPrice) + ' ' + cur() + '/л', 'bl')}
      ${kpi('Отклонение от нормы', (L - N >= 0 ? '+' : '') + fmt(L - N) + ' л', N ? fmt((L - N) / N * 100, 1) + '% ' + (L > N ? 'перерасход' : 'экономия') : '', L - N > N * 0.05 ? 'bad' : 'ok')}
      ${kpi('Топливо на весь проект', fmt(Mc.fuelL) + ' л', '≈ ' + moneyS(Mc.fuelL * DB.settings.fuelPrice))}
    </div>
    <div class="card"><h3>Потребность в технике на весь проект (расчёт) <span class="tools"><button class="btn-s" data-act="resetMach">Сбросить</button></span></h3>
      <div class="small muted mb">Экскаваторы и самосвалы считаются на странице «Котлован». Остальные значения можно менять.</div>
      <div class="tbl-wrap"><table><thead><tr><th>Техника</th><th class="n">Кол-во</th><th class="n">Моточасов на 1 ед.</th><th class="n">Расход л/ч</th><th class="n">Аренда / ч</th><th class="n">Топливо, л</th><th class="n">Топливо</th><th class="n">Аренда</th><th class="n">Итого</th></tr></thead><tbody>
      ${Mc.rows.map(r => r.pit ? `<tr><td>${r.name} <span class="small muted">${r.note || ''}</span> <span class="pill p-reserve">котлован</span></td><td class="n">${r.count}</td><td class="n">${fmt(r.hours)}</td><td class="n">${r.lph ? fmt(r.lph) : '—'}</td><td class="n">${r.rent ? fmt(r.rent) : '—'}</td><td class="n">${fmt(r.fuelL)}</td><td class="n muted" colspan="3">учтено в стоимости котлована</td></tr>`
        : `<tr><td>${r.name}${r.note ? `<div class="small muted">${r.note}</div>` : ''}</td><td class="n" style="width:80px">${cell(`mach.${r.key}.count`, r.count)}</td><td class="n" style="width:110px">${cell(`mach.${r.key}.hours`, Math.round(r.hours))}</td><td class="n" style="width:90px">${cell(`mach.${r.key}.lph`, r.lph)}</td><td class="n" style="width:110px">${cell(`mach.${r.key}.rent`, r.rent)}</td><td class="n">${fmt(r.fuelL)}</td><td class="n">${moneyS(r.fuelCost)}</td><td class="n">${moneyS(r.rentCost)}</td><td class="n"><b>${moneyS(r.total)}</b></td></tr>`).join('')}
      <tr class="tot"><td>Итого (без котлована)</td><td class="n">${fmt(Mc.units)}</td><td colspan="3"></td><td class="n">${fmt(Mc.fuelL)}</td><td colspan="2"></td><td class="n">${moneyS(Mc.total)}</td></tr></tbody></table></div></div>
    <div class="card"><h3>Автопарк <span class="tools"><button class="btn-s" data-act="vehAdd">+ Машина</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Название</th><th>Тип</th><th>Госномер</th><th>Водитель</th><th>Учёт</th><th class="n">Норма</th><th>Своя</th><th class="n">Работа</th><th class="n">Норма, л</th><th class="n">Факт, л</th><th class="n">Отклонение</th><th></th></tr></thead><tbody>
      ${st.map(({ v, s }, i) => `<tr><td style="min-width:180px">${cell(`fleet.${i}.name`, v.name, { type: 'text' })}</td><td>${cellSel(`fleet.${i}.type`, v.type, VEH_TYPES)}</td><td style="min-width:110px">${cell(`fleet.${i}.plate`, v.plate, { type: 'text' })}</td>
        <td style="min-width:150px">${cell(`fleet.${i}.driver`, v.driver, { type: 'text', attr: 'list="drvList"' })}</td><td>${cellSel(`fleet.${i}.mode`, v.mode, [['h', 'моточасы'], ['km', 'км']])}</td>
        <td class="n" style="width:90px">${cell(`fleet.${i}.norm`, v.norm)}<div class="small muted">${v.mode === 'h' ? 'л/ч' : 'л/100км'}</div></td><td style="text-align:center"><input type="checkbox" data-bind="fleet.${i}.own" ${v.own ? 'checked' : ''}></td>
        <td class="n">${fmt(s.qty)} ${v.mode === 'h' ? 'ч' : 'км'}</td><td class="n">${fmt(s.normL)}</td><td class="n">${fmt(s.liters)}</td><td class="n ${s.devPct > 5 ? 'bad' : 'ok'}">${s.n ? (s.dev >= 0 ? '+' : '') + fmt(s.devPct, 1) + '%' : '—'}</td>
        <td><button class="x" data-act="vehDel" data-id="${v.id}">✕</button></td></tr>`).join('') || '<tr><td colspan="12" class="muted">Добавьте технику</td></tr>'}
      </tbody></table></div><datalist id="drvList">${p.staff.map(s => `<option value="${esc(s.name)}">`).join('')}</datalist></div>
    <div class="card"><h3>Журнал заправок и работы техники <span class="tools"><label class="flex">Месяц: <input class="txt" type="month" data-arg="month" value="${month}"></label><button class="btn-s" data-act="fuelAll">Всё время</button></span></h3>
      <div class="form mb">
        <label class="fld"><span>Дата</span><input class="txt" type="date" id="fDate" value="${today()}"></label>
        <label class="fld"><span>Машина</span><select id="fVeh">${p.fleet.map(v => `<option value="${v.id}">${esc(v.name)}</option>`).join('')}</select></label>
        <label class="fld"><span>Отработано (моточасы или км)</span><input class="txt" type="number" id="fQty"></label>
        <label class="fld"><span>Заправлено, литров</span><input class="txt" type="number" id="fLit"></label>
        <label class="fld"><span>Цена литра, ${cur()}</span><input class="txt" type="number" id="fPrice" value="${DB.settings.fuelPrice}"></label>
        <label class="fld"><span>&nbsp;</span><button class="btn-p" data-act="fuelAdd">+ Записать</button></label>
      </div>
      <div class="tbl-wrap"><table><thead><tr><th>Дата</th><th>Машина</th><th class="n">Работа</th><th class="n">По норме, л</th><th class="n">Заправлено, л</th><th class="n">Сумма</th><th></th></tr></thead><tbody>
      ${log.slice(0, 200).map(f => { const v = names[f.vid] || { name: '—', mode: 'h', norm: 0 }; const nl = v.mode === 'h' ? f.qty * v.norm : f.qty * v.norm / 100; return `<tr><td>${fdate(f.date)}</td><td>${esc(v.name)}</td><td class="n">${fmt(f.qty)} ${v.mode === 'h' ? 'ч' : 'км'}</td><td class="n">${fmt(nl)}</td><td class="n ${f.liters > nl * 1.05 ? 'bad' : ''}">${fmt(f.liters)}</td><td class="n">${money(f.liters * f.price)}</td><td><button class="x" data-act="fuelDel" data-id="${f.id}">✕</button></td></tr>`; }).join('') || '<tr><td colspan="7" class="muted">Записей нет</td></tr>'}
      </tbody></table></div></div>`;
  }
};
ACT.resetMach = () => { P().mach = {}; save(); rerender(); };
ACT.vehAdd = () => { P().fleet.push({ id: uid(), name: 'Новая машина', type: 'Самосвал', plate: '', mode: 'km', norm: 35, driver: '', own: true, rent: 0 }); save(); rerender(); };
ACT.vehDel = async d => { const p = P(); if (!await ui.confirm('Удалить машину из автопарка?')) return; p.fleet = p.fleet.filter(v => v.id !== d.id); save(); rerender(); };
ACT.fuelAll = () => { pageArgs.month = ''; rerender(); };
ACT.fuelAdd = () => {
  const p = P(), vid = $('#fVeh').value, v = p.fleet.find(x => x.id === vid); if (!v) return toast('Сначала добавьте машину');
  const qty = num($('#fQty').value), liters = num($('#fLit').value), price = num($('#fPrice').value), date = $('#fDate').value || today();
  if (!liters && !qty) return toast('Введите литры или моточасы/км');
  const id = uid(); let txId = null;
  if (liters) txId = addTx(p, { date, type: 'out', cat: 'Техника и топливо', amount: Math.round(liters * price), who: 'АЗС', desc: `Топливо: ${v.name}, ${liters} л`, link: { kind: 'fuel', id } }).id;
  p.fuel.push({ id, date, vid, qty, liters, price, txId }); save(); rerender(); toast('Записано' + (txId ? ' и проведено в бухгалтерии' : ''));
};
ACT.fuelDel = async d => { const p = P(), f = p.fuel.find(x => x.id === d.id); if (!f || !await ui.confirm('Удалить запись?')) return; p.fuel = p.fuel.filter(x => x.id !== d.id); p.tx = p.tx.filter(t => t.id !== f.txId); save(); rerender(); };

/* ---------- График работ ---------- */
PAGES.schedule = {
  title: 'График строительства (этапы)',
  fullOnChange: true,
  render(p) {
    const st = p.stages, span = projSpan(p), t = today(), total = Math.max(1, span.days);
    const pos = d => daysBetween(span.start, d) / total * 100;
    const months = []; { const d = new Date(span.start + 'T00:00:00'); d.setDate(1); while (isoDate(d) <= span.end) { const ds = isoDate(d); months.push(ds); d.setMonth(d.getMonth() + 1); } }
    const pr = progress(p), late = st.filter(s => s.progress < 100 && addDays(s.start, s.dur) < t);
    return `
    <div class="kpis">
      ${kpi('Начало', fdate(span.start), '', 'bl')}${kpi('Окончание', fdate(span.end), fmt(span.days / 30.4, 1) + ' мес.', 'bl')}
      ${kpi('Общая готовность', fmt(pr, 1) + '%', progBar(pr), 'ac')}
      ${kpi('Просрочено этапов', late.length, late.map(s => esc(s.name)).slice(0, 2).join(', '), late.length ? 'bad' : 'ok')}
    </div>
    <div class="card"><h3>Диаграмма Ганта</h3><div class="gantt"><div class="gantt-in">
      <div class="g-row g-head"><div class="g-name">Этап</div><div class="g-track">${months.map((m, i) => {
        const l = pos(m < span.start ? span.start : m);
        // проект начался в конце месяца: подпись этого месяца налезала бы на следующую — не пишем её
        if (i === 0 && months[1] && pos(months[1]) - l < 4) return '';
        return `<div class="g-m" style="left:${l}%">${MONTHS_S[+m.slice(5, 7) - 1]} ${m.slice(2, 4)}</div>`; }).join('')}</div></div>
      ${st.map(s => { const end = addDays(s.start, s.dur), cls = s.progress >= 100 ? 'done' : end < t ? 'late' : ''; return `<div class="g-row"><div class="g-name" title="${esc(s.name)}">${esc(s.name)}</div><div class="g-track">
        ${t >= span.start && t <= span.end ? `<div class="g-today" style="left:${pos(t)}%"></div>` : ''}
        <div class="g-bar ${cls}" style="left:${pos(s.start)}%;width:${Math.max(0.6, s.dur / total * 100)}%" title="${fdate(s.start)} — ${fdate(end)}, ${s.progress}%"><i style="width:${s.progress}%"></i><span>${s.progress}%</span></div></div></div>`; }).join('')}
    </div></div><div class="legend mt"><span><i style="background:var(--pri)"></i>в работе</span><span><i style="background:var(--ok)"></i>завершено</span><span><i style="background:var(--bad)"></i>просрочено</span><span><i style="background:#c9d3e6"></i>осталось</span><span><i style="background:var(--bad);width:3px"></i>сегодня</span></div></div>
    <div class="card"><h3>Этапы <span class="tools"><button class="btn-s" data-act="stageAdd">+ Этап</button><button class="btn-s" data-act="stageShift">Сдвинуть график к дате начала проекта</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Этап</th><th>Начало</th><th class="n">Дней</th><th>Окончание</th><th class="n">Готово, %</th><th>Ответственный</th><th></th></tr></thead><tbody>
      ${st.map((s, i) => `<tr><td style="min-width:260px">${cell(`stages.${i}.name`, s.name, { type: 'text' })}</td><td>${cell(`stages.${i}.start`, s.start, { type: 'date' })}</td><td class="n" style="width:80px">${cell(`stages.${i}.dur`, s.dur)}</td>
        <td class="${s.progress < 100 && addDays(s.start, s.dur) < t ? 'bad' : ''}">${fdate(addDays(s.start, s.dur))}</td><td class="n" style="width:90px">${cell(`stages.${i}.progress`, s.progress, { attr: 'min="0" max="100"' })}</td>
        <td style="min-width:150px">${cell(`stages.${i}.resp`, s.resp, { type: 'text', attr: 'list="drvList2"' })}</td><td><button class="x" data-act="stageDel" data-i="${i}">✕</button></td></tr>`).join('')}
      </tbody></table></div><datalist id="drvList2">${p.staff.filter(s => ITR_ROLES.has(s.role)).map(s => `<option value="${esc(s.name)}">`).join('')}</datalist></div>`;
  }
};
ACT.stageAdd = () => { const p = P(); p.stages.push({ id: uid(), name: 'Новый этап', start: today(), dur: 30, progress: 0, resp: '' }); save(); rerender(); };
ACT.stageDel = async d => { if (!await ui.confirm('Удалить этап?')) return; P().stages.splice(+d.i, 1); save(); rerender(); };
ACT.stageShift = () => { const p = P(), sp = projSpan(p), sh = daysBetween(sp.start, p.startDate); if (!sh) return toast('График уже начинается с даты начала проекта'); p.stages.forEach(s => s.start = addDays(s.start, sh)); save(); rerender(); toast(`График сдвинут на ${sh} дн.`); };

/* ---------- Бухгалтерия ---------- */
PAGES.accounting = {
  title: 'Бухгалтерия: доходы и расходы',
  render(p, a) {
    const F = finance(p), m = a.month ?? '', ty = a.type || '', cat = a.cat || '', q = (a.q || '').toLowerCase();
    const list = p.tx.slice().sort((x, y) => y.date.localeCompare(x.date)).filter(t => (!m || t.date.startsWith(m)) && (!ty || t.type === ty) && (!cat || t.cat === cat) && (!q || (t.desc + ' ' + t.who + ' ' + t.cat).toLowerCase().includes(q)));
    const fi = sum(list.filter(t => t.type === 'in'), t => t.amount), fo = sum(list.filter(t => t.type === 'out'), t => t.amount);
    const mon = monthNow(), mo = sum(p.tx.filter(t => t.type === 'out' && t.date.startsWith(mon)), t => t.amount);
    const S = smetaCalc(p);
    return `
    <div class="kpis">
      ${kpi('Поступления всего', moneyS(F.inc), '', 'ok')}${kpi('Расходы всего', moneyS(F.out), '', 'bad')}
      ${kpi('Баланс (касса)', moneyS(F.bal), '', F.bal >= 0 ? 'ok' : 'bad')}${kpi('Расходы в этом месяце', moneyS(mo), monthName(mon), 'bl')}
    </div>
    <div class="card"><h3>Новая операция</h3><div class="form">
      <label class="fld"><span>Дата</span><input class="txt" type="date" id="txDate" value="${today()}"></label>
      <label class="fld"><span>Тип</span><select id="txType"><option value="out">Расход</option><option value="in">Поступление</option></select></label>
      <label class="fld"><span>Статья</span><select id="txCat">${opts(TX_OUT)}</select></label>
      <label class="fld"><span>Сумма, ${cur()}</span><input class="txt" type="number" id="txAmt"></label>
      <label class="fld"><span>Контрагент</span><input class="txt" id="txWho" list="whoList"></label>
      <label class="fld"><span>Описание</span><input class="txt" id="txDesc"></label>
    </div><datalist id="whoList">${[...new Set(p.tx.map(t => t.who).filter(Boolean))].slice(0, 100).map(w => `<option value="${esc(w)}">`).join('')}</datalist>
    <div class="flex mt"><button class="btn-p" data-act="txAdd">+ Провести операцию</button></div></div>
    <div class="card"><h3>Журнал операций <span class="tools">
      <input class="txt" type="month" data-arg="month" value="${m}" title="Месяц">
      <select data-arg="type"><option value="">Все типы</option>${opts([['in', 'Поступления'], ['out', 'Расходы']], ty)}</select>
      <select data-arg="cat"><option value="">Все статьи</option>${opts([...TX_OUT, ...TX_IN], cat)}</select>
      <input class="txt" data-arg="q" value="${esc(a.q || '')}" placeholder="Поиск…">
      ${m || ty || cat || q ? '<button class="btn-s" data-act="txReset">Сбросить</button>' : ''}
      <button class="btn-s" data-act="txCsv">⬇ Excel (CSV)</button></span></h3>
      <div class="small muted mb">Найдено: ${list.length} · поступления ${money(fi)} · расходы ${money(fo)}</div>
      <div class="tbl-wrap"><table><thead><tr><th>Дата</th><th>Тип</th><th>Статья</th><th>Описание</th><th>Контрагент</th><th class="n">Сумма</th><th></th></tr></thead><tbody>
      ${list.slice(0, 300).map(t => `<tr><td>${fdate(t.date)}</td><td>${t.type === 'in' ? pill('in', 'приход') : pill('out', 'расход')}</td><td>${esc(t.cat)}</td><td>${esc(t.desc)}${t.link ? ' <span class="small muted">🔗</span>' : ''}</td><td>${esc(t.who)}</td><td class="n ${t.type === 'in' ? 'ok' : 'bad'}"><b>${t.type === 'in' ? '+' : '−'}${money(t.amount)}</b></td><td><button class="x" data-act="txDel" data-id="${t.id}">✕</button></td></tr>`).join('') || '<tr><td colspan="7" class="muted">Нет операций</td></tr>'}
      </tbody></table></div>${list.length > 300 ? '<p class="small muted">Показаны первые 300. Используйте фильтры.</p>' : ''}</div>
    <div class="card"><h3>Расходы по статьям: факт / смета</h3>${hbars(S.lines.filter(l => l.plan || l.fact).map(l => ({ label: l.cat, plan: l.plan, fact: l.fact })))}</div>`;
  },
  onChange(el) { if (el.id === 'txType') $('#txCat').innerHTML = opts(el.value === 'in' ? TX_IN : TX_OUT); }
};
ACT.txAdd = () => {
  const amount = num($('#txAmt').value); if (amount <= 0) return toast('Введите сумму');
  addTx(P(), { date: $('#txDate').value || today(), type: $('#txType').value, cat: $('#txCat').value, amount, who: $('#txWho').value, desc: $('#txDesc').value });
  save(); rerender(); toast('Операция проведена');
};
function unlinkTx(p, t) {
  if (!t.link) return;
  if (t.link.kind === 'pay') p.pays = p.pays.filter(x => x.id !== t.link.id);
  if (t.link.kind === 'fuel') { const f = p.fuel.find(x => x.id === t.link.id); if (f) f.txId = null, f.liters = 0; }
  if (t.link.kind === 'apt') { const o = p.apts[t.link.id]; if (o && o.payments) o.payments = o.payments.filter(x => x.id !== t.link.payId); }
}
ACT.txDel = async d => { const p = P(), t = p.tx.find(x => x.id === d.id); if (!t || !await ui.confirm(`Удалить операцию на ${money(t.amount)}?` + (t.link ? '\nСвязанная запись (зарплата/топливо/оплата квартиры) тоже будет отменена.' : ''))) return; unlinkTx(p, t); p.tx = p.tx.filter(x => x.id !== d.id); save(); rerender(); };
ACT.txReset = () => { pageArgs = {}; rerender(); };
ACT.txCsv = () => { const p = P(); download('buhgalteriya.csv', csv([['Дата', 'Тип', 'Статья', 'Описание', 'Контрагент', 'Сумма'], ...p.tx.slice().sort((a, b) => a.date.localeCompare(b.date)).map(t => [fdate(t.date), t.type === 'in' ? 'Поступление' : 'Расход', t.cat, t.desc, t.who, t.type === 'in' ? t.amount : -t.amount])]), 'text/csv'); };

/* ---------- Продажи квартир ---------- */
PAGES.sales = {
  title: 'Продажи квартир (шахматка)',
  render(p, a) {
    const A = genApts(p), fr = a.rooms ?? '', fs = a.status || '';
    const c = s => A.filter(x => x.status === s);
    const sold = c('sold'), bk = c('booked');
    const soldArea = sum(sold, x => x.area), soldSum = sum(sold, x => x.price), paid = sum(A, x => x.paid);
    const debt = sum(A.filter(x => x.status === 'sold' || x.status === 'booked'), x => Math.max(0, x.price - x.paid));
    const secs = [...new Set(A.map(x => x.section))];
    const clients = A.filter(x => x.status !== 'free' && x.status !== 'reserve');
    return `
    <div class="kpis">
      ${kpi('Всего помещений', A.length, fmt(sum(A, x => x.area)) + ' м²', 'bl')}
      ${kpi('Продано', sold.length, fmt(soldArea) + ' м² · ' + moneyS(soldSum), 'ok')}
      ${kpi('Забронировано', bk.length, moneyS(sum(bk, x => x.price)), 'ac')}
      ${kpi('Свободно', c('free').length, moneyS(sum(c('free'), x => x.price)))}
      ${kpi('Получено оплат', moneyS(paid), 'долг покупателей ' + moneyS(debt), 'ok')}
      ${kpi('Средняя цена продажи', moneyS(soldArea ? soldSum / soldArea : 0) + '/м²', 'прайс ' + moneyS(p.b.pricePerM2) + '/м²')}
    </div>
    <div class="card"><h3>Шахматка <span class="tools">
      <select data-arg="rooms"><option value="">Все комнаты</option>${opts([['1', '1-комн.'], ['2', '2-комн.'], ['3', '3-комн.'], ['4', '4-комн.'], ['0', 'Коммерция']], fr)}</select>
      <select data-arg="status"><option value="">Все статусы</option>${opts(STATUSES, fs)}</select>
      <button class="btn-s" data-act="priceCsv">⬇ Прайс-лист</button></span></h3>
      <div class="chess-legend">${STATUSES.map(([k, t]) => `<span class="cl cl-${k}"><i></i>${t}<b>${c(k).length}</b></span>`).join('')}</div>
      <p class="chess-hint">нажмите на квартиру — откроется презентация для клиента: где она в доме, планировка, 3D, оплата и задаток</p>
      <div class="chess-wrap">${secs.map(s => {
        const fl = [...new Set(A.filter(x => x.section === s).map(x => x.floor))].sort((x, y) => y - x);
        const cols = Math.max(...fl.map(f => A.filter(x => x.section === s && x.floor === f).length));
        return `<div class="section"><div class="sec-h"><b>${(A.find(x => x.section === s) || {}).secName || "Подъезд " + s}</b><span>${A.filter(x => x.section === s && x.status === 'free').length} / ${A.filter(x => x.section === s).length}</span></div>${fl.map(f => `<div class="floor" style="--cols:${cols}"><span class="fl">${f}</span>${A.filter(x => x.section === s && x.floor === f).map(x => {
          const dim = (fr !== '' && String(x.rooms) !== fr) || (fs && x.status !== fs);
          return `<div class="apt st-${x.status}${dim ? ' dim' : ''}${x.rooms ? '' : ' wide'}" data-act="aptOpen" data-id="${x.id}" title="${x.buyer ? esc(x.buyer) + ' · ' : ''}${ST_NAME[x.status]}">`
            + `<div class="a-top"><b>№${x.num}</b><span>${x.rooms ? `${x.rooms}-комн.` : 'Коммерция'}</span></div>`
            + `<div class="a-area">${x.area} м²</div><div class="a-price">${short(x.price)}</div></div>`;
        }).join('')}</div>`).join('')}</div>`;
      }).join('')}</div></div>
    <div class="card"><h3>Покупатели и оплаты</h3><div class="tbl-wrap"><table><thead><tr><th>Кв.</th><th>Покупатель</th><th>Телефон</th><th>Статус</th><th>Условия</th><th class="n">Цена</th><th class="n">Оплачено</th><th class="n">Остаток</th><th></th></tr></thead><tbody>
      ${clients.map(x => `<tr><td><b>${x.rooms ? '№' + x.num : x.num}</b> <span class="small muted">${x.rooms ? x.rooms + 'к, ' : ''}${x.area} м², эт. ${x.floor}</span></td><td>${esc(x.buyer)}</td><td>${esc(x.phone)}</td><td>${pill(x.status, ST_NAME[x.status])}</td><td class="small">${esc(x.o.plan || '')}</td>
        <td class="n">${money(x.price)}</td><td class="n ok">${money(x.paid)}</td><td class="n ${x.price - x.paid > 0 ? 'bad' : ''}">${money(x.price - x.paid)}</td><td><button class="btn-s" data-act="aptOpen" data-id="${x.id}">Открыть</button></td></tr>`).join('') || '<tr><td colspan="9" class="muted">Продаж пока нет</td></tr>'}
    </tbody></table></div></div>`;
  }
};
const PLANS = LISTS.pay_plans;   // справочник компании
ACT.apt = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id); if (!a) return;
  const o = a.o, pays = o.payments || [], rest = a.price - a.paid, mo = parseInt((o.plan || '').replace(/\D/g, '')) || 0;
  openModal(`<h2>${a.rooms ? `Квартира №${a.num} · ${a.rooms}-комнатная` : 'Коммерческое помещение ' + a.num}</h2>
  <div class="muted">Подъезд ${a.section}, этаж ${a.floor}, площадь ${a.area} м² · прайс ${money(a.basePrice)} (${money(a.basePrice / a.area)}/м²)</div>
  <div class="form mt">
    <label class="fld"><span>Статус</span><select id="mStatus">${opts(STATUSES, a.status)}</select></label>
    <label class="fld"><span>Покупатель (ФИО)</span><input class="txt" id="mBuyer" value="${esc(a.buyer)}"></label>
    <label class="fld"><span>Телефон</span><input class="txt" id="mPhone" value="${esc(a.phone)}" placeholder="+998"></label>
    <label class="fld"><span>Цена продажи, ${cur()}</span><input class="txt" type="number" id="mPrice" value="${a.price}"></label>
    <label class="fld"><span>Условия оплаты</span><select id="mPlan" data-free="Своё условие оплаты (число — месяцев рассрочки)"><option value="">—</option>${optsFree(PLANS, o.plan)}</select></label>
    <label class="fld"><span>Примечание</span><input class="txt" id="mNote" value="${esc(o.note || '')}"></label>
  </div>
  <div class="flex mt"><button class="btn-p" data-act="aptSave" data-id="${a.id}">Сохранить</button>${a.rooms ? `<button data-act="aptDesign" data-id="${a.id}">🛋 Дизайн квартиры в 3D</button><button data-act="aptPano" data-id="${a.id}">🌐 Тур 360°</button>` : ''}</div>
  <h3 class="mt">Оплаты: ${money(a.paid)} из ${money(a.price)} <span class="${rest > 0 ? 'bad' : 'ok'}">(остаток ${money(rest)})</span></h3>
  ${progBar(a.price ? a.paid / a.price * 100 : 0, 'var(--ok)')}
  ${mo && rest > 0 ? `<p class="small muted">${esc(o.plan)}: ежемесячный платёж ≈ <b>${money(rest / mo)}</b></p>` : ''}
  <table class="mt"><tbody>${pays.map(x => `<tr><td>${fdate(x.date)}</td><td class="n ok">${money(x.amount)}</td><td><button class="x" data-act="aptPayDel" data-id="${a.id}" data-pay="${x.id}">✕</button></td></tr>`).join('') || '<tr><td class="muted">Оплат пока нет</td></tr>'}</tbody></table>
  <div class="flex mt"><input class="txt" type="date" id="mPayDate" value="${today()}"><input class="txt" type="number" id="mPayAmt" placeholder="Сумма оплаты" value="${rest > 0 ? (mo ? Math.round(rest / mo) : rest) : ''}"><button data-act="aptPay" data-id="${a.id}">+ Внести оплату</button></div>
  <p class="small muted">Оплата автоматически проводится в бухгалтерии как поступление.</p>
  ${(o.history || []).length ? `<h3 class="mt">${T('Прежние сделки')}</h3><table><tbody>${o.history.slice().reverse().map(h => `<tr><td class="small">${fdate(h.closed)}</td><td>${esc(h.buyer || '—')}${h.phone ? ' · ' + esc(h.phone) : ''}</td><td class="n">${money(h.paid || 0)}</td></tr>`).join('')}</tbody></table><p class="small muted">${T('Оплаты прежних покупателей не учитываются у нового. Задаток, который вернули, оформите расходом.')}</p>` : ''}`);
};
function aptObj(p, id) { return p.apts[id] = p.apts[id] || {}; }
/* Квартира освободилась — сделка закрыта: покупатель и его оплаты уходят в историю квартиры, следующий покупатель начинает с нуля
   (так же делает сервер; деньги в бухгалтерии объекта остаются — возврат задатка оформляется расходом) */
function aptArchive(o) {
  const pays = o.payments || [];
  if (pays.length || o.buyer || o.phone) {
    o.history = (o.history || []).concat([{ deal: o.deal, buyer: o.buyer || '', phone: o.phone || '', plan: o.plan || '', payments: pays, paid: pays.reduce((s, x) => s + (+x.amount || 0), 0), closed: today() }]).slice(-20);
  }
  o.payments = []; delete o.deal; o.buyer = ''; o.phone = ''; o.plan = '';
}
ACT.aptSave = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), o = aptObj(p, d.id), price = num($('#mPrice').value);
  o.status = $('#mStatus').value; o.buyer = $('#mBuyer').value.trim(); o.phone = $('#mPhone').value.trim(); o.plan = $('#mPlan').value; o.note = $('#mNote').value;
  if (o.status === 'free' || o.status === 'reserve') aptArchive(o);
  if (price && price !== a.basePrice) o.price = price; else delete o.price;
  save(); closeModal(); rerender(); toast('Сохранено');
};
ACT.aptPay = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), amt = num($('#mPayAmt').value); if (amt <= 0) return toast('Введите сумму');
  const o = aptObj(p, d.id); o.payments = o.payments || []; const id = uid(), date = $('#mPayDate').value || today();
  if (!o.buyer && $('#mBuyer').value.trim()) o.buyer = $('#mBuyer').value.trim();
  if (!o.status || o.status === 'free') o.status = 'booked';
  const tx = addTx(p, { date, type: 'in', cat: a.rooms ? 'Продажа квартир' : 'Продажа коммерции', amount: amt, who: o.buyer || '', desc: `Оплата кв. №${a.num}`, link: { kind: 'apt', id: a.id, payId: id } });
  o.payments.push({ id, date, amount: amt, txId: tx.id }); save(); rerender(); ACT.apt(d); toast('Оплата проведена');
};
ACT.aptPayDel = async d => { const p = P(), o = p.apts[d.id]; const x = o.payments.find(z => z.id === d.pay); if (!await ui.confirm('Удалить оплату?')) return; o.payments = o.payments.filter(z => z.id !== d.pay); p.tx = p.tx.filter(t => t.id !== x.txId); save(); rerender(); ACT.apt(d); };
ACT.aptDesign = d => { closeModal(); go('design', { apt: d.id }); };
ACT.priceCsv = () => { const p = P(); download('prais-list.csv', csv([['№', 'Подъезд', 'Этаж', 'Комнат', 'Площадь', 'Цена', 'Цена за м²', 'Статус'], ...genApts(p).map(a => [a.num, a.secName || a.section, a.floor, a.rooms || (a.kind && a.kind !== "flat" && a.kind !== "commercial" ? ({ office: "офис", parking: "паркинг", storage: "кладовая" })[a.kind] : "коммерция"), a.area, a.price, Math.round(a.price / a.area), ST_NAME[a.status]])]), 'text/csv'); };

/* ---------- Дизайн квартиры (3D) ---------- */
let designCfg = null;
PAGES.design = {
  title: 'Дизайн квартиры в 3D',
  render(p, a) {
    const A = genApts(p).filter(x => x.rooms > 0), apt = a.apt ? A.find(x => x.id === a.apt) : null, d = p.design;
    const rooms = apt ? Math.min(3, apt.rooms) : d.rooms, area = apt ? apt.area : d.area, style = (apt && apt.o.style) || d.style;
    designCfg = { rooms, area, style };
    const S = STYLES[style] || STYLES.modern;
    return `${photoGallery(style)}<div class="design">
      <div>
        <div class="card"><h3>Квартира</h3>
          <label class="fld"><span>Выбрать из шахматки</span><select data-arg="apt"><option value="">Свободная планировка</option>${A.map(x => `<option value="${x.id}"${apt && apt.id === x.id ? ' selected' : ''}>№${x.num} · ${x.rooms}к · ${x.area} м² · эт. ${x.floor} · ${ST_NAME[x.status]}</option>`).join('')}</select></label>
          ${apt ? `<div class="big-res mt">${rbox('Площадь', apt.area + ' м²')}${rbox('Цена', moneyS(apt.price), 1)}</div>${apt.rooms > 3 ? '<p class="small muted">Планировка показана как 3-комнатная.</p>' : ''}`
          : `<div class="form mt">${field('design.rooms', 'Комнат', { options: [[1, '1-комнатная'], [2, '2-комнатная'], [3, '3-комнатная']], re: 1 })}${field('design.area', 'Площадь', { unit: 'м²', re: 1 })}</div>`}
        </div>
        <div class="card"><h3>Стиль интерьера</h3><div class="styles">
          ${Object.entries(STYLES).map(([k, s]) => `<button class="style-btn${k === style ? ' on' : ''}" data-act="d3Style" data-style="${k}">${s.name}<div class="sw">${[s.wall, s.floor, s.fabric, s.accent, s.wood].map(c => `<i style="background:${c}"></i>`).join('')}</div></button>`).join('')}
        </div>
          <div class="big-res mt">${rbox('Ремонт «под ключ»', `<span id="renoCost">${moneyS(S.price * area)}</span>`, 1)}${rbox('Цена за м²', `<span id="renoM2">${money(S.price)}</span>`)}</div>
          <p class="small muted">Ориентировочная стоимость ремонта с мебелью в выбранном стиле.</p></div>
        <div class="card"><h3>Комнаты</h3><ul class="rooms-list" id="roomList"></ul><p class="small muted">Нажмите на комнату — камера перейдёт в неё.</p></div>
      </div>
      <div class="viewer" id="viewer">
        <div class="v-tools">
          <button data-act="d3View" data-v="persp">🏠 Обзор</button><button data-act="d3View" data-v="top">📐 План сверху</button>
          <button data-act="d3Pano" id="bPano" class="pano-btn">🌐 Тур 360°</button><button data-act="d3Walk" id="bWalk">🚶 Прогулка внутри</button><button data-act="d3Night" id="bNight">🌙 Вечер</button>
          <button data-act="d3Shot">📷 Снимок</button><button data-act="d3HQ" id="bHQ">✨ Фото-качество</button>
        </div>
        <div class="v-help" id="vHelp">Мышь: вращать · колесо: приблизить · правая кнопка: сдвиг</div>
        <div class="v-move"><button data-hold="1">▲</button><button data-hold="-1">▼</button></div>
      </div></div>`;
  },
  mounted() {
    d3Init($('#viewer'), designCfg);
    if (pageArgs.pano) setTimeout(() => d3Pano(), 300);
    const rl = $('#roomList'); if (rl && D3.plan) rl.innerHTML = D3.plan.rooms.map((r, i) => `<li data-act="d3Room" data-i="${i}"><span>${r.name}</span><span class="muted">${fmt(r.w * r.d, 1)} м² <button class="btn-s" data-act="d3PanoRoom" data-i="${i}" title="Обзор 360°">🌐 360°</button></span></li>`).join('');
  },
  destroy() { d3Dispose(); }
};
ACT.d3Style = d => {
  const p = P(), id = pageArgs.apt; if (id) aptObj(p, id).style = d.style; else p.design.style = d.style; save();
  $$('.style-btn').forEach(b => b.classList.toggle('on', b.dataset.style === d.style));
  const S = STYLES[d.style]; designCfg.style = d.style; $('#renoCost').textContent = moneyS(S.price * designCfg.area); $('#renoM2').textContent = money(S.price);
  d3SetStyle(d.style);
  const pc = $('.ph-card'); if (pc) pc.outerHTML = photoGallery(d.style);
};
ACT.d3View = d => d3View(d.v);
ACT.d3Walk = () => d3SetWalk(!D3.walk);
ACT.d3Night = () => d3SetNight(!D3.night);
ACT.d3Shot = () => d3Shot();
ACT.d3Pano = () => D3.panoOn ? d3PanoExit() : d3Pano();
ACT.d3PanoRoom = (d, el) => { d3Pano(+d.i); };
ACT.d3PanoExit = () => d3PanoExit();
ACT.d3PanoAuto = () => { D3.panoAuto = !D3.panoAuto; d3PanoBar(); };
ACT.d3PanoGyro = () => { d3PanoGyro(!D3.gyroOn); d3PanoBar(); };
ACT.d3Full = () => { const v = $('#viewer'); if (!document.fullscreenElement) (v.requestFullscreen || v.webkitRequestFullscreen || (() => { })).call(v); else document.exitFullscreen(); };
ACT.aptPano = d => { closeModal(); go('design', { apt: d.id, pano: 1 }); };
ACT.d3HQ = () => d3ToggleHQ();
ACT.d3Room = d => d3GoRoom(+d.i);

/* ---------- Настройки ---------- */
PAGES.settings = {
  title: 'Настройки и данные',
  render(p) {
    return `<div class="card"><h3>Общие настройки</h3><div class="form">
      ${field('S:company', 'Название компании', { type: 'text', re: 1 })}${field('S:currency', 'Валюта', { type: 'text', hint: 'сум, ₽, $, ₸ … (цены не пересчитываются)', re: 1 })}
      ${field('S:fuelPrice', 'Цена дизельного топлива', { unit: 'за литр' })}${field('S:normHours', 'Норма часов в смене', { unit: 'ч' })}
      ${field('S:overtimeK', 'Коэффициент сверхурочных', { hint: '1.5 = полуторная оплата' })}
    </div></div>
    <div class="card"><h3>Проекты</h3><div class="tbl-wrap"><table><thead><tr><th>Проект</th><th>Статус</th><th>Начало</th><th class="n">Этажей</th><th class="n">Квартир</th><th></th></tr></thead><tbody>
      ${DB.projects.map(x => `<tr${x.id === p.id ? ' style="background:#fff8ec"' : ''}><td><b>${esc(x.name)}</b><div class="small muted">${esc(x.address)}</div></td><td>${esc(x.status)}</td><td>${fdate(x.startDate)}</td><td class="n">${x.b.floors}</td><td class="n">${bld(x).apts}</td>
        <td style="white-space:nowrap">${x.id !== p.id ? `<button class="btn-s" data-act="projOpen" data-id="${x.id}">Открыть</button>` : '<span class="pill p-free">текущий</span>'} <button class="btn-s" data-act="projCopy" data-id="${x.id}">Копия</button> <button class="btn-s btn-d" data-act="projDel" data-id="${x.id}">Удалить</button></td></tr>`).join('')}
    </tbody></table></div><div class="flex mt"><button class="btn-p" data-act="newProject">+ Новый проект</button></div></div>
    <div class="card"><h3>Резервная копия</h3>
      <p class="muted">Все данные хранятся в этом браузере на этом компьютере. Регулярно сохраняйте резервную копию — её можно перенести на другой компьютер.</p>
      <div class="flex"><button class="btn-p" data-act="exportJson">⬇ Сохранить копию (файл .json)</button>
      <label class="btn">⬆ Загрузить копию<input type="file" id="importFile" accept=".json,application/json" hidden></label>
      <button class="btn-d" data-act="resetAll">Сбросить всё и загрузить демо</button></div></div>`;
  }
};
ACT.projOpen = d => { DB.currentId = d.id; save(); go('home'); };
ACT.projCopy = d => { const src = DB.projects.find(x => x.id === d.id), c = JSON.parse(JSON.stringify(src)); c.id = uid(); c.name = src.name + ' (копия)'; DB.projects.push(c); save(); rerender(); toast('Копия создана'); };
ACT.projDel = async d => {
  if (DB.projects.length < 2) return toast('Нельзя удалить единственный проект');
  const x = DB.projects.find(z => z.id === d.id); if (!await ui.confirm(`Удалить проект «${x.name}» со всеми данными? Это нельзя отменить.`)) return;
  DB.projects = DB.projects.filter(z => z.id !== d.id); if (DB.currentId === d.id) DB.currentId = DB.projects[0].id; save(); render();
};
ACT.exportJson = () => download(`stroycrm_${today()}.json`, JSON.stringify(DB, null, 1), 'application/json');
ACT.resetAll = async () => { if (!await ui.confirm('Удалить ВСЕ данные и загрузить демо-проект? Сначала сохраните резервную копию!')) return; localStorage.removeItem(STORE_KEY); load(); go('home'); };
function importJson(file) {
  const r = new FileReader();
  r.onload = async () => {
    try { const d = JSON.parse(r.result); if (!d.projects || !d.settings) throw new Error('неверный формат'); if (!await ui.confirm('Заменить текущие данные загруженной копией?', { danger: true, ok: T('Заменить') })) return; DB = d; DB.projects.forEach(migrate); if (!P()) DB.currentId = DB.projects[0].id; save(); go('dashboard'); toast('Данные загружены'); }
    catch (e) { toast('Ошибка файла: ' + e.message); }
  };
  r.readAsText(file);
}
