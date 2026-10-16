'use strict';
/* ===== Страницы: панель, проект, котлован, материалы, бригады, смета ===== */
const PAGES = {}, ACT = {};

/* ---------- Панель ---------- */
PAGES.dashboard = {
  title: 'Панель управления',
  render(p) {
    const S = smetaCalc(p), F = S.F, A = genApts(p), pr = progress(p), pit = pitCalc(p), L = labCalc(p), span = projSpan(p);
    const sold = A.filter(a => a.status === 'sold'), bk = A.filter(a => a.status === 'booked'), free = A.filter(a => a.status === 'free');
    const t = today();
    const act = p.staff.filter(s => s.active !== false).length;
    const stages = p.stages.slice().sort((a, b) => a.start.localeCompare(b.start));
    const now = stages.filter(s => s.progress < 100 && s.start <= addDays(t, 30)).slice(0, 8);
    const last = p.tx.slice().sort((a, b) => b.date.localeCompare(a.date)).slice(0, 8);
    const soldSum = sum(sold, a => a.price), paid = sum(A, a => a.paid);
    const late = p.stages.filter(s => s.progress < 100 && addDays(s.start, s.dur) < t).length;
    return `
    <div class="kpis">
      ${kpi('Себестоимость по смете', moneyS(S.total), fmt(S.perM2) + ' ' + cur() + ' за м² общей площади', 'ac')}
      ${kpi('Плановая выручка', moneyS(S.revenue), 'прибыль ' + moneyS(S.profit) + ' (' + fmt(S.margin, 1) + '%)', S.profit >= 0 ? 'ok' : 'bad')}
      ${kpi('Расходы факт', moneyS(F.out), fmt(S.total ? F.out / S.total * 100 : 0, 1) + '% от сметы', 'bl')}
      ${kpi('Касса (баланс)', moneyS(F.bal), 'поступило ' + moneyS(F.inc), F.bal >= 0 ? 'ok' : 'bad')}
      ${kpi('Готовность объекта', fmt(pr, 1) + ' %', progBar(pr))}
      ${kpi('Продано квартир', `${sold.length} из ${A.length}`, `бронь ${bk.length} · свободно ${free.length}`, 'ok')}
      ${kpi('Сотрудников', act, 'потребность по расчёту ≈ ' + fmt(L.peak + L.itrCount) + ' чел.', 'bl')}
      ${kpi('Срок строительства', fmt(span.days / 30.4, 1) + ' мес.', fdate(span.start) + ' — ' + fdate(span.end) + '<br>' + (late ? `<span class="bad">просрочено этапов: ${late}</span>` : 'просрочек нет'), late ? 'bad' : '')}
    </div>
    <div class="grid g2">
      <div class="card"><h3>Ход строительства <span class="tools"><button class="btn-s" data-act="go" data-page="schedule">График →</button></span></h3>
        ${now.map(s => { const end = addDays(s.start, s.dur), lt = end < t; return `<div class="hb"><div class="lb"><span>${esc(s.name)}</span><span class="${lt ? 'bad' : ''}">${s.progress}% · до ${fdate(end)}</span></div>${progBar(s.progress, lt ? 'var(--bad)' : '')}</div>`; }).join('') || '<p class="muted">Все этапы завершены</p>'}
      </div>
      <div class="card"><h3>Бюджет: факт / смета <span class="tools"><button class="btn-s" data-act="go" data-page="smeta">Смета →</button></span></h3>
        ${hbars(S.lines.filter(l => l.plan || l.fact).map(l => ({ label: l.cat, plan: l.plan, fact: l.fact })))}
        <div class="legend mt"><span><i style="background:#cdd6e6"></i>смета</span><span><i style="background:var(--pri)"></i>факт</span><span><i style="background:var(--bad)"></i>перерасход</span></div>
      </div>
    </div>
    <div class="grid g2">
      <div class="card"><h3>Продажи <span class="tools"><button class="btn-s" data-act="go" data-page="sales">Шахматка →</button></span></h3>
        <div style="display:flex;height:26px;border-radius:7px;overflow:hidden;margin-bottom:10px">
          ${[['sold', sold.length], ['booked', bk.length], ['free', free.length], ['reserve', A.length - sold.length - bk.length - free.length]].map(([k, n]) => n ? `<div class="st-${k}" style="flex:${n}" title="${ST_NAME[k]}: ${n}"></div>` : '').join('')}
        </div>
        <div class="big-res">
          ${rbox('Продано на сумму', moneyS(soldSum), 1)}${rbox('Получено оплат', moneyS(paid))}${rbox('Долг покупателей', moneyS(sum(A.filter(a => a.status !== 'free'), a => Math.max(0, a.price - a.paid))))}${rbox('Котлован', fmt(pit.V) + ' м³ грунта')}
        </div>
      </div>
      <div class="card"><h3>Последние операции <span class="tools"><button class="btn-s" data-act="go" data-page="accounting">Бухгалтерия →</button></span></h3>
        <div class="tbl-wrap"><table><tbody>${last.map(x => `<tr><td class="small muted">${fdate(x.date)}</td><td>${esc(x.desc || x.cat)}<div class="small muted">${esc(x.who)}</div></td><td class="n ${x.type === 'in' ? 'ok' : 'bad'}">${x.type === 'in' ? '+' : '−'}${moneyS(x.amount)}</td></tr>`).join('') || '<tr><td class="muted">Операций пока нет</td></tr>'}</tbody></table></div>
      </div>
    </div>`;
  }
};

/* ---------- Проект и здание ---------- */
function bldSvg(p) {
  const b = p.b, W = 640, all = b.floors + b.underground, fh = Math.max(8, Math.min(26, 330 / Math.max(1, all))), top = 30;
  const g = top + b.floors * fh, H = g + b.underground * fh + 24, secs = Math.max(1, b.sections), sw = (W - 60) / secs;
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect x="0" y="0" width="${W}" height="${g}" fill="#eaf2fb"/><rect x="0" y="${g}" width="${W}" height="${H - g}" fill="#d9cdb8"/>`;
  s += `<rect x="28" y="${top - 8}" width="${W - 56}" height="8" fill="#5b6475"/>`;
  for (let i = 0; i < secs; i++) {
    const x = 30 + i * sw;
    for (let f = 1; f <= b.floors; f++) {
      const y = g - f * fh, com = b.commercialFirst && f === 1;
      s += `<rect x="${x}" y="${y}" width="${sw - 2}" height="${fh - 1}" fill="${com ? '#cfd8e3' : '#f4efe6'}" stroke="#b9b1a3" stroke-width=".6"/>`;
      const nw = com ? 3 : Math.max(2, b.aptsPerFloor * 2), ww = (sw - 2) / nw;
      for (let k = 0; k < nw; k++) s += `<rect x="${x + k * ww + ww * 0.22}" y="${y + fh * 0.22}" width="${ww * 0.56}" height="${fh * (com ? 0.66 : 0.5)}" fill="#7fa6c9" rx="1"/>`;
    }
    for (let f = 1; f <= b.underground; f++) s += `<rect x="${x}" y="${g + (f - 1) * fh}" width="${sw - 2}" height="${fh - 1}" fill="#9a8f7e" stroke="#7d7364" stroke-width=".6"/>`;
    s += `<text x="${x + sw / 2}" y="${H - 6}" text-anchor="middle" font-size="11" fill="#444">Подъезд ${i + 1}</text>`;
  }
  s += `<line x1="0" y1="${g}" x2="${W}" y2="${g}" stroke="#6b5b45" stroke-width="2"/><text x="6" y="${top + 12}" font-size="11" fill="#555">${b.floors} эт.</text><text x="${W - 6}" y="${top + 12}" text-anchor="end" font-size="11" fill="#555">H = ${fmt(b.floors * b.floorH, 1)} м</text></svg>`;
  return s;
}
/* Проверки параметров здания: высота этажа и расхождение с перечнем помещений (обновляются при вводе) */
function bldChecks(p) {
  const b = p.b, out = [], fh = +b.floorH || 0;
  // каждая фраза — отдельным элементом, числа — отдельно: так фразы переводятся целиком
  if (fh && (fh < 2.4 || fh > 6)) out.push(`<div class="warnbox"><div><b>Проверьте высоту этажа</b> <b class="notr">(${fmt(fh, 1)}</b> <b>м</b><b>)</b></div>
    <div>Это высота одного этажа в метрах (обычно 3,0–3,3 м), а не количество этажей.</div>
    <div><span>В 3D дом показан с высотой этажа</span> <b class="notr">${fmt(viewFloorH(b), 1)}</b> <b>м</b></div></div>`);
  if (hasInv(p)) {
    const I = invShape(p);
    if (I.floors !== +b.floors || I.sections !== +b.sections) out.push(`<div class="warnbox"><div><b>Параметры здания и перечень помещений не совпадают.</b></div>
      <table class="mini-tbl"><tr><th></th><th>Этажей</th><th>Подъездов</th><th>Помещения</th></tr>
        <tr><td>Параметры здания</td><td><b>${fmt(b.floors)}</b></td><td><b>${fmt(b.sections)}</b></td><td>—</td></tr>
        <tr><td>Перечень помещений</td><td><b>${fmt(I.floors)}</b></td><td><b>${fmt(I.sections)}</b></td><td><b>${fmt(I.units)}</b></td></tr></table>
      <div>Продажи и шахматка идут по перечню помещений (Объекты → Помещения).</div>
      <div>Дом в 3D строится по большему из двух, чтобы перечень, заведённый не целиком, не уменьшал дом.</div>
      <button class="btn-s mt" data-act="bFromInv">Взять этажи и подъезды из перечня</button></div>`);
  }
  return out.join('');
}
ACT.bFromInv = () => { const p = P(), I = invShape(p); p.b.floors = I.floors; p.b.sections = I.sections; p.b.aptsPerFloor = I.apf; save(); rerender(); toast('Этажи и подъезды взяты из перечня помещений'); };
PAGES.project = {
  title: 'Проект и параметры здания',
  render(p) {
    return `
    <div class="card"><h3>Общие сведения</h3><div class="form">
      ${field('name', 'Название проекта', { type: 'text', re: 1 })}
      ${field('address', 'Адрес / участок', { type: 'text' })}
      ${field('status', 'Статус', { options: ['Проектирование', 'Разрешения', 'Строительство', 'Отделка', 'Сдан', 'Приостановлен'] })}
      ${field('startDate', 'Дата начала работ', { type: 'date' })}
    </div></div>
    <div class="card"><h3>Параметры здания</h3><div class="form">
      ${field('b.plot', 'Площадь участка', { unit: 'м²' })}
      ${field('b.floors', 'Этажей (надземных)', { unit: 'эт.' })}
      ${field('b.underground', 'Подземных этажей', { unit: 'эт.' })}
      ${field('b.sections', 'Подъездов (секций)', { unit: 'шт' })}
      ${field('b.aptsPerFloor', 'Квартир на этаже (в подъезде)', { unit: 'шт' })}
      ${field('b.length', 'Длина здания', { unit: 'м' })}
      ${field('b.width', 'Ширина здания', { unit: 'м' })}
      ${field('b.floorH', 'Высота этажа', { unit: 'м', hint: 'одного этажа, обычно 3,0–3,3 м' })}
      ${field('b.sellableK', 'Коэф. продаваемой площади', { hint: '0.70–0.80 (без стен, лестниц, МОП)' })}
      ${field('b.mix', 'Комнатность квартир на этаже', { type: 'text', hint: 'по порядку, например 1,2,2,3' })}
      ${field('b.commercialFirst', '1-й этаж — коммерция', { type: 'checkbox' })}
    </div></div>
    <div class="card"><h3>Цены продажи</h3><div class="form">
      ${field('b.pricePerM2', 'Цена квартиры за м²', { unit: cur() })}
      ${field('b.commercialPrice', 'Цена коммерции за м²', { unit: cur() })}
      ${field('b.floorStep', 'Наценка за каждый этаж выше', { unit: '%' })}
    </div></div>
    <div id="results">${this.results(p)}</div>${site3dCard(p)}`;
  },
  mounted() { s3Init($('#site3d')); },
  destroy() { s3Dispose(); },
  onInput(el) { if (/^b\./.test(el.dataset.bind || '')) { clearTimeout(S3.t); S3.t = setTimeout(() => { s3Rebuild(); const s = $('#s3stats'); if (s) s.innerHTML = s3StatsHtml(P()); }, 450); } },
  results(p) {
    const B = bld(p), A = genApts(p), by = {};
    A.forEach(a => { const k = a.rooms ? a.rooms + '-комнатные' : 'Коммерция'; by[k] = by[k] || { n: 0, area: 0, sum: 0 }; by[k].n++; by[k].area += a.area; by[k].sum += a.price; });
    return `${bldChecks(p)}<div class="card res"><h3>Расчёт здания</h3><div class="big-res">
      ${rbox('Площадь застройки', fmt(B.fp) + ' м²')}${rbox('Застройка участка', fmt(B.coverage, 1) + ' %')}
      ${rbox('Общая площадь надземная', fmt(B.totalAbove) + ' м²', 1)}${rbox('Подземная площадь', fmt(B.under) + ' м²')}
      ${rbox('Продаваемая жилая', fmt(B.sellable) + ' м²', 1)}${rbox('Коммерция', fmt(B.commercial) + ' м²')}
      ${rbox('Квартир всего', fmt(B.apts), 1)}${rbox('Средняя площадь квартиры', fmt(B.avgApt, 1) + ' м²')}
      ${rbox('Высота здания', fmt(B.height, 1) + ' м')}${rbox('Строительный объём', fmt(B.volume) + ' м³')}
    </div></div>
    <div>
      <div class="card"><h3>Квартирография</h3><div class="tbl-wrap"><table><thead><tr><th>Тип</th><th class="n">Кол-во</th><th class="n">Ср. площадь</th><th class="n">Площадь</th><th class="n">Стоимость</th></tr></thead><tbody>
        ${Object.entries(by).map(([k, v]) => `<tr><td>${k}</td><td class="n">${v.n}</td><td class="n">${fmt(v.area / v.n, 1)} м²</td><td class="n">${fmt(v.area)} м²</td><td class="n">${moneyS(v.sum)}</td></tr>`).join('')}
        <tr class="tot"><td>Итого</td><td class="n">${A.length}</td><td></td><td class="n">${fmt(sum(A, a => a.area))} м²</td><td class="n">${moneyS(sum(A, a => a.price))}</td></tr>
      </tbody></table></div></div>
      
    </div>`;
  }
};

/* ---------- Котлован ---------- */
function pitSvg(p, c) {
  const t = p.pit, W = 560, H = 210, sc = Math.min((W - 80) / c.Lt, 120 / Math.max(1, t.depth));
  const tw = c.Lt * sc, bw = t.length * sc, d = t.depth * sc, x0 = (W - tw) / 2, y0 = 50;
  return `<svg viewBox="0 0 ${W} ${H}" class="bsvg">
   <rect x="0" y="0" width="${W}" height="${y0}" fill="#eaf2fb"/><rect x="0" y="${y0}" width="${W}" height="${H - y0}" fill="#cbb48f"/>
   <polygon points="${x0},${y0} ${x0 + tw},${y0} ${x0 + (tw + bw) / 2},${y0 + d} ${x0 + (tw - bw) / 2},${y0 + d}" fill="#f6f1e7" stroke="#8a6d45" stroke-width="2"/>
   <text x="${W / 2}" y="${y0 - 10}" text-anchor="middle" font-size="12" fill="#333">по верху ${fmt(c.Lt, 1)} × ${fmt(c.Wt, 1)} м</text>
   <text x="${W / 2}" y="${y0 + d + 18}" text-anchor="middle" font-size="12" fill="#333">по дну ${fmt(t.length, 1)} × ${fmt(t.width, 1)} м</text>
   <text x="${W - 8}" y="${y0 + d + 36}" text-anchor="end" font-size="12" fill="#333">H = ${fmt(t.depth, 1)} м, откос 1:${t.slope}</text>
   <text x="${W / 2}" y="${y0 + d / 2 + 5}" text-anchor="middle" font-size="15" font-weight="700" fill="#8a4b00">${fmt(c.V)} м³</text></svg>`;
}
PAGES.pit = {
  title: 'Котлован, грунт, экскаваторы и самосвалы',
  render(p) {
    return `
    <div class="note">Введите размеры котлована и параметры техники — система посчитает объём грунта (по формуле призматоида с откосами), количество рейсов, сколько нужно экскаваторов, самосвалов, водителей, расход топлива и стоимость. <button class="btn-s" data-act="pitFromBuilding">Взять размеры из здания</button></div>
    <div class="grid g2">
      <div class="card"><h3>Котлован</h3><div class="form">
        ${field('pit.length', 'Длина по дну', { unit: 'м' })}${field('pit.width', 'Ширина по дну', { unit: 'м' })}
        ${field('pit.depth', 'Глубина', { unit: 'м' })}${field('pit.slope', 'Заложение откоса (m)', { hint: 'песок 1.0, суглинок 0.5, глина 0.25' })}
        ${field('pit.looseK', 'Коэф. разрыхления', { hint: 'песок 1.1–1.2, суглинок 1.2–1.3, глина 1.3' })}
        ${field('pit.backfillPct', 'Оставить на обратную засыпку', { unit: '%' })}
      </div></div>
      <div class="card"><h3>Режим работы</h3><div class="form">
        ${field('pit.shiftH', 'Часов в смене', { unit: 'ч' })}${field('pit.shifts', 'Смен в сутки', { unit: 'смен' })}
        ${field('pit.dumpFee', 'Плата за полигон', { unit: cur() + '/м³' })}
        ${field('pit.rentInclFuel', 'Аренда уже включает топливо и водителей', { type: 'checkbox' })}
      </div></div>
      <div class="card"><h3>Экскаваторы</h3><div class="form">
        ${field('pit.excCount', 'Количество экскаваторов', { unit: 'шт' })}${field('pit.excProd', 'Производительность одного', { unit: 'м³/ч' })}
        ${field('pit.excFuel', 'Расход топлива', { unit: 'л/ч' })}${field('pit.excRent', 'Аренда', { unit: cur() + '/ч' })}
        ${field('pit.operatorRate', 'Оплата машиниста', { unit: cur() + '/ч' })}
      </div></div>
      <div class="card"><h3>Самосвалы и вывоз</h3><div class="form">
        ${field('pit.truckCap', 'Вместимость кузова', { unit: 'м³' })}${field('pit.distance', 'Расстояние до отвала', { unit: 'км' })}
        ${field('pit.speed', 'Средняя скорость', { unit: 'км/ч' })}${field('pit.unloadMin', 'Разгрузка', { unit: 'мин' })}
        ${field('pit.truckL100', 'Расход топлива', { unit: 'л/100км' })}${field('pit.truckRentTrip', 'Стоимость рейса', { unit: cur() })}
        ${field('pit.driverRate', 'Оплата водителя', { unit: cur() + '/ч' })}
      </div></div>
    </div>
    <div id="results">${this.results(p)}</div>`;
  },
  results(p) {
    const c = pitCalc(p), fp = DB.settings.fuelPrice;
    return `<div class="card res"><h3>Результат расчёта котлована</h3>
      <div class="grid g2"><div>${pitSvg(p, c)}</div><div class="big-res">
        ${rbox('Объём грунта (плотный)', fmt(c.V) + ' м³', 1)}${rbox('К вывозу (разрыхлённый)', fmt(c.Vloose) + ' м³', 1)}
        ${rbox('Оставить для засыпки', fmt(c.Vkeep) + ' м³')}${rbox('Рейсов самосвалов', fmt(c.trips), 1)}
        ${rbox('Срок работ', fmt(c.days) + ' дн. (' + fmt(c.excH) + ' ч)')}${rbox('Цикл самосвала', fmt(c.cycle) + ' мин')}
      </div></div>
      <h4>Потребность в технике и людях</h4><div class="big-res">
        ${rbox('Экскаваторов', fmt(p.pit.excCount) + ' шт', 1)}${rbox('Самосвалов нужно', fmt(c.trucks) + ' шт', 1)}
        ${rbox('Самосвалов на 1 экскаватор', fmt(c.trucksPerExc))}${rbox('Рейсов 1 машины за смену', fmt(c.tripsPerShift))}
        ${rbox('Водителей самосвалов', fmt(c.drivers) + ' чел.', 1)}${rbox('Машинистов экскаватора', fmt(c.operators) + ' чел.', 1)}
        ${rbox('Пробег самосвалов', fmt(c.km) + ' км')}${rbox('Погрузка 1 машины', fmt(c.loadMin, 1) + ' мин')}
      </div>
      <h4>Топливо</h4><div class="big-res">
        ${rbox('Экскаваторы', fmt(c.excFuel) + ' л')}${rbox('Самосвалы', fmt(c.truckFuel) + ' л')}
        ${rbox('Всего топлива', fmt(c.fuel) + ' л', 1)}${rbox('Стоимость топлива', moneyS(c.fuelCost) + `<div class="small muted">по ${fmt(fp)} ${cur()}/л</div>`)}
      </div>
      <h4>Стоимость работ</h4><div class="tbl-wrap"><table><tbody>
        <tr><td>Аренда экскаваторов (${fmt(c.excMh)} маш.-ч)</td><td class="n">${money(c.excRent)}</td></tr>
        <tr><td>Рейсы самосвалов (${fmt(c.trips)} × ${money(p.pit.truckRentTrip)})</td><td class="n">${money(c.truckRent)}</td></tr>
        <tr><td>Топливо ${c.incl ? '<span class="muted">(справочно, входит в аренду)</span>' : ''}</td><td class="n${c.incl ? ' muted' : ''}">${money(c.fuelCost)}</td></tr>
        <tr><td>Зарплата машинистов и водителей ${c.incl ? '<span class="muted">(справочно)</span>' : ''}</td><td class="n${c.incl ? ' muted' : ''}">${money(c.wages)}</td></tr>
        <tr><td>Полигон / утилизация грунта</td><td class="n">${money(c.dump)}</td></tr>
        <tr class="tot"><td>Итого котлован</td><td class="n">${money(c.total)}</td></tr>
        <tr><td class="muted">Стоимость 1 м³ грунта</td><td class="n muted">${money(c.V ? c.total / c.V : 0)}</td></tr>
      </tbody></table></div></div>`;
  }
};
ACT.pitFromBuilding = () => { const p = P(); p.pit.length = p.b.length + 2; p.pit.width = p.b.width + 2; p.pit.depth = r1(p.b.underground * p.b.floorH + 1.2); save(); rerender(); toast('Размеры котлована взяты из габаритов здания + 1 м рабочей зоны'); };

/* ---------- Материалы ---------- */
PAGES.materials = {
  title: 'Материалы и объёмы работ',
  fullOnChange: true,
  render(p) {
    const M = matCalc(p); let g = '';
    const rows = M.rows.map(r => {
      let h = '';
      if (r.group !== g) { g = r.group; h += `<tr class="grp"><td colspan="5">${esc(g)}</td><td class="n">${moneyS(M.groups[g])}</td><td></td></tr>`; }
      if (r.extra) h += `<tr><td>${cell(`extraMat.${r.i}.name`, r.name, { type: 'text' })}</td><td>${cell(`extraMat.${r.i}.unit`, r.unit, { type: 'text' })}</td><td class="n">${cell(`extraMat.${r.i}.qty`, r.qty)}</td><td></td><td class="n">${cell(`extraMat.${r.i}.price`, r.price)}</td><td class="n">${money(r.sum)}</td><td><button class="x" data-act="delMat" data-i="${r.i}">✕</button></td></tr>`;
      else h += `<tr><td>${esc(r.name)}</td><td>${r.unit}</td><td class="n">${fmt(r.qty, r.qty < 100 ? 1 : 0)}</td><td></td><td class="n" style="width:150px">${cell('prices.' + r.key, r.price)}</td><td class="n">${money(r.sum)}</td><td></td></tr>`;
      return h;
    }).join('');
    return `
    <div class="note">🧮 <b>Это расчёт, а не склад.</b> Программа считает, сколько материалов понадобится на всё здание по его параметрам и нормативам, и примерную стоимость по ценам ниже. Что реально куплено и лежит на складе — в разделе «Склад».${SAAS && SAAS.stock ? ` <a href="/company/stock">${T('Открыть склад')} →</a>` : ''}</div>
    <div class="card"><h3>Нормативы конструкций</h3><div class="form">
      ${field('m.slabThk', 'Толщина фундаментной плиты', { unit: 'м' })}${field('m.floorThk', 'Толщина перекрытия', { unit: 'м' })}
      ${field('m.wallsK', 'Монолит стен и колонн', { unit: 'м³/м²', hint: 'на 1 м² этажа, обычно 0.10–0.15' })}${field('m.rebarK', 'Армирование', { unit: 'т/м³', hint: '0.09–0.13 т на 1 м³ бетона' })}
      ${field('m.extWallThk', 'Толщина наружных стен', { unit: 'м' })}${field('m.partitionsK', 'Перегородок на 1 м² пола', { unit: 'м²' })}
      ${field('m.glazingK', 'Доля остекления фасада', { hint: '0.18–0.30' })}${field('m.boardK', 'Доска на 1 м² опалубки', { unit: 'м³', hint: 'обычно 0.003–0.006' })}${field('m.beamK', 'Брус на 1 м² опалубки', { unit: 'м³', hint: 'обычно 0.002–0.004' })}${field('m.brickFacing', 'Облицовка кирпичом', { type: 'checkbox' })}
    </div></div>
    <div id="results">${this.results(p)}</div>
    <div class="card"><h3>Сколько материалов нужно (расчёт) <span class="tools"><button class="btn-s" data-act="addMat">+ Своя позиция</button><button class="btn-s" data-act="matCsv">⬇ Excel (CSV)</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Материал</th><th>Ед.</th><th class="n">Нужно</th><th></th><th class="n">Цена за ед., ${cur()}</th><th class="n">Сумма по расчёту</th><th></th></tr></thead>
      <tbody>${rows}<tr class="tot"><td colspan="5">ИТОГО по расчёту</td><td class="n">${money(M.total)}</td><td></td></tr></tbody></table></div></div>`;
  },
  results(p) {
    const { q } = matQty(p), M = matCalc(p);
    return `<div class="card res"><h3>Объёмы работ (расчёт)</h3><div class="big-res">
      ${rbox('Бетон всего', fmt(q.concreteAll) + ' м³', 1)}${rbox('Арматура', fmt(q.rebar, 1) + ' т', 1)}${rbox('Кладка газоблок', fmt(q.blocks) + ' м³')}
      ${rbox('Кирпич облицовочный', fmt(q.bricks) + ' шт')}${rbox('Окна и витражи', fmt(q.windows) + ' м²')}${rbox('Фасад', fmt(q.extNet) + ' м²')}
      ${rbox('Штукатурка стен', fmt(q.plasterArea) + ' м²')}${rbox('Кабель', fmt(q.cable) + ' м')}${rbox('Доска + брус', fmt(q.board + q.beam, 1) + ' м³')}${rbox('Материалы по расчёту', moneyS(M.total), 1)}
    </div></div>`;
  }
};
ACT.addMat = () => { P().extraMat.push({ name: 'Новая позиция', unit: 'шт', qty: 1, price: 0 }); save(); rerender(); };
ACT.delMat = d => { P().extraMat.splice(+d.i, 1); save(); rerender(); };
ACT.matCsv = () => { const M = matCalc(P()); download('materialy.csv', csv([['Группа', 'Материал', 'Ед.', 'Количество', 'Цена', 'Сумма'], ...M.rows.map(r => [r.group, r.name, r.unit, Math.round(r.qty * 10) / 10, r.price, Math.round(r.sum)]), ['', 'ИТОГО', '', '', '', Math.round(M.total)]]), 'text/csv'); };

/* ---------- Бригады ---------- */
PAGES.labour = {
  title: 'Бригады: расчёт количества рабочих',
  fullOnChange: true,
  render(p) {
    const L = labCalc(p); let g = '';
    const rows = L.rows.map(r => {
      let h = '';
      if (r.group !== g) { g = r.group; h += `<tr class="grp"><td colspan="9">${g} — ${L.groups[g]} чел.</td></tr>`; }
      return h + `<tr><td><b>${r.name}</b></td><td class="n">${fmt(r.qty, r.qty < 100 ? 1 : 0)} ${r.unit}</td>
        <td class="n" style="width:110px">${cell(`labour.${r.key}.norm`, r.norm)}</td><td class="n">${fmt(r.pd)}</td>
        <td class="n" style="width:90px">${cell(`labour.${r.key}.days`, r.days)}</td><td class="n"><b style="font-size:16px">${r.workers}</b></td>
        <td class="n" style="width:130px">${cell(`labour.${r.key}.rate`, r.rate)}</td><td class="n">${money(r.cost)}</td></tr>`;
    }).join('');
    const h = L.helpers, c = L.crane;
    return `
    <div class="note">Количество рабочих = объём работ ÷ выработку одного человека за смену ÷ срок выполнения. Меняйте выработку, срок или ставку прямо в таблице — всё пересчитается.</div>
    <div class="kpis">
      ${kpi('Рабочих по расчёту', fmt(L.workersTotal) + ' чел.', 'пиковая численность на площадке ≈ ' + fmt(L.peak), 'ac')}
      ${kpi('ИТР и управление', fmt(L.itrCount) + ' чел.', 'прорабов ' + L.itr.find(i => i.key === 'prorab').count + ', мастеров ' + L.itr.find(i => i.key === 'master').count, 'bl')}
      ${kpi('Трудоёмкость', fmt(L.pdTotal) + ' чел.-смен', 'срок проекта ≈ ' + fmt(L.months, 1) + ' мес.')}
      ${kpi('Фонд оплаты труда', moneyS(L.workCost + L.itrCost), 'рабочие ' + moneyS(L.workCost) + ' · ИТР ' + moneyS(L.itrCost), 'ok')}
    </div>
    <div class="card"><h3>Рабочие профессии <span class="tools"><button class="btn-s" data-act="resetLab">Сбросить к нормативам</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Профессия</th><th class="n">Объём работ</th><th class="n">Выработка / смена</th><th class="n">Чел.-смен</th><th class="n">Срок, дней</th><th class="n">Нужно людей</th><th class="n">Ставка за смену</th><th class="n">ФОТ</th></tr></thead>
      <tbody>${rows}
        <tr class="grp"><td colspan="9">Обслуживание</td></tr>
        <tr><td><b>Крановщики</b> <span class="small muted">(${p.b.sections} кран × 2 смены)</span></td><td></td><td></td><td class="n">${fmt(c.pd)}</td><td class="n">${cell('labour.crane.days', c.days)}</td><td class="n">${cell('labour.crane.count', c.count)}</td><td class="n">${cell('labour.crane.rate', c.rate)}</td><td class="n">${money(c.cost)}</td></tr>
        <tr><td><b>Разнорабочие</b> <span class="small muted">(% от трудоёмкости: ${cell('labour.helpers.pct', h.pct, { attr: 'style="width:60px;display:inline-block"' })})</span></td><td></td><td></td><td class="n">${fmt(h.pd)}</td><td class="n">${cell('labour.helpers.days', h.days)}</td><td class="n"><b style="font-size:16px">${h.workers}</b></td><td class="n">${cell('labour.helpers.rate', h.rate)}</td><td class="n">${money(h.cost)}</td></tr>
        <tr class="tot"><td colspan="5">Итого рабочие</td><td class="n">${fmt(L.workersTotal)}</td><td></td><td class="n">${money(L.workCost)}</td></tr>
      </tbody></table></div></div>
    <div class="card"><h3>ИТР, прорабы и управление</h3>
      <div class="tbl-wrap"><table><thead><tr><th>Должность</th><th class="n">Кол-во</th><th class="n">Оклад в месяц</th><th class="n">Месяцев</th><th class="n">Сумма</th></tr></thead><tbody>
      ${L.itr.map(r => `<tr><td>${r.name}${r.auto ? ' <span class="pill p-free">авто</span>' : ''}</td><td class="n" style="width:90px">${cell(`itr.${r.key}.count`, r.count)}</td><td class="n" style="width:140px">${cell(`itr.${r.key}.rate`, r.rate)}</td><td class="n" style="width:90px">${cell(`itr.${r.key}.months`, r.months)}</td><td class="n">${money(r.cost)}</td></tr>`).join('')}
      <tr class="tot"><td>Итого ИТР</td><td class="n">${L.itrCount}</td><td></td><td></td><td class="n">${money(L.itrCost)}</td></tr></tbody></table></div></div>`;
  }
};
ACT.resetLab = async () => { if (!await ui.confirm('Сбросить выработку, сроки и ставки к нормативам?')) return; const p = P(); p.labour = {}; p.itr = {}; save(); rerender(); };

/* ---------- Смета ---------- */
PAGES.smeta = {
  title: 'Сводная смета и прибыль',
  render(p) {
    return `<div class="card"><h3>Накладные и прочие расходы <span class="tools"><button class="btn-s" data-act="print">🖨 Печать</button></span></h3><div class="form">
      ${field('overhead.designPct', 'Проектирование', { unit: '%' })}${field('overhead.overheadPct', 'Накладные расходы', { unit: '%' })}
      ${field('overhead.reservePct', 'Непредвиденные', { unit: '%' })}${field('overhead.taxPct', 'Налоги от выручки', { unit: '%' })}
      ${field('overhead.land', 'Земля (стоимость участка)', { unit: cur() })}${field('overhead.permits', 'Подключение к сетям (ТУ)', { unit: cur() })}
      ${field('overhead.subcontract', 'Субподряд', { unit: cur() })}
    </div></div><div id="results">${this.results(p)}</div>`;
  },
  results(p) {
    const S = smetaCalc(p), B = S.B;
    return `<div class="kpis">
      ${kpi('Себестоимость проекта', moneyS(S.total), 'по смете', 'ac')}
      ${kpi('Себестоимость 1 м²', money(S.perM2), 'общей площади ' + fmt(B.total) + ' м²')}
      ${kpi('Себестоимость 1 м² продаваемой', money(S.perSell), fmt(B.sellable + B.commercial) + ' м² к продаже')}
      ${kpi('Выручка (все квартиры)', moneyS(S.revenue), 'ср. ' + money(S.revenue / Math.max(1, B.sellable + B.commercial)) + '/м²', 'bl')}
      ${kpi('Прибыль', moneyS(S.profit), 'рентабельность ' + fmt(S.margin, 1) + '%', S.profit >= 0 ? 'ok' : 'bad')}
    </div>
    <div class="card"><h3>Статьи затрат: смета / факт</h3><div class="tbl-wrap"><table><thead><tr><th>Статья</th><th class="n">Смета</th><th class="n">Доля</th><th class="n">Факт</th><th class="n">Остаток</th><th style="width:160px">Освоение</th></tr></thead><tbody>
      ${S.lines.map(l => `<tr><td>${esc(l.name)}</td><td class="n">${money(l.plan)}</td><td class="n">${fmt(S.total ? l.plan / S.total * 100 : 0, 1)}%</td><td class="n">${money(l.fact)}</td><td class="n ${l.plan - l.fact < 0 ? 'bad' : ''}">${money(l.plan - l.fact)}</td><td>${progBar(l.plan ? l.fact / l.plan * 100 : (l.fact ? 100 : 0), l.fact > l.plan ? 'var(--bad)' : '')}</td></tr>`).join('')}
      <tr class="tot"><td>ИТОГО</td><td class="n">${money(S.total)}</td><td class="n">100%</td><td class="n">${money(S.fact)}</td><td class="n">${money(S.total - S.fact)}</td><td>${progBar(S.total ? S.fact / S.total * 100 : 0)}</td></tr>
    </tbody></table></div></div>`;
  }
};
ACT.print = () => window.print();
