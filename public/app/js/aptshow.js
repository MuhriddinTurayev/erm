'use strict';
/* ===== Квартира для клиента: расположение, планировка, 3D, оплата, задаток ===== */
function aptPos(p, a) {
  const b = p.b, apf = b.aptsPerFloor, cols = Math.ceil(apf / 2);
  if (!a.rooms) return { cols: 1, col: 0, row: 'south', corner: true, comm: true };
  const row = a.idx < cols ? 'south' : 'north', col = row === 'south' ? a.idx : (a.idx - cols);
  const cRow = row === 'south' ? cols : apf - cols;
  const corner = (col === 0 && a.section === 1) || (col === cRow - 1 && a.section === b.sections);
  return { cols: cRow, col, row, corner, comm: false };
}
function aptFacts(p, a) {
  const b = p.b, pos = aptPos(p, a), h = (a.floor - 1) * b.floorH, f = [];
  f.push(['🏢', 'Подъезд', `${a.section} из ${b.sections}`]);
  f.push(['🔢', 'Этаж', `${a.floor} из ${b.floors}` + (a.floor === b.floors ? ' · последний' : a.floor === (b.commercialFirst ? 2 : 1) ? ' · нижний жилой' : '')]);
  f.push(['📏', 'Высота пола над землёй', `${fmt(h, 1)} м`]);
  f.push(['📍', 'Положение в доме', pos.corner ? 'угловая — окна на две стороны' : 'в середине секции — тёплая, меньше наружных стен']);
  f.push(['🧭', 'Окна выходят', pos.row === 'south' ? 'во двор, на юг — солнечная сторона' : 'на улицу и парковку, на север — не жарко летом']);
  f.push(['🌳', 'Из окон видно', a.floor >= 9 ? 'панорама города поверх соседних домов' : a.floor >= 4 ? (pos.row === 'south' ? 'двор, детская площадка и кроны деревьев' : 'улица и соседние дома') : (pos.row === 'south' ? 'уровень крон деревьев, двор рядом' : 'улица, близко к земле')]);
  f.push(['🛗', 'Лифт', b.floors > 9 ? '2 лифта в подъезде' : '1 лифт в подъезде']);
  return f;
}
/* ---------- схема фасада с отметкой квартиры ---------- */
function aptFacadeSvg(p, a) {
  const b = p.b, pos = aptPos(p, a), secs = b.sections, W = 600, fh = Math.max(9, Math.min(24, 300 / b.floors)), top = 26, g = top + b.floors * fh, H = g + 30, sw = (W - 40) / secs;
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect width="${W}" height="${g}" fill="#eaf2fb"/><rect y="${g}" width="${W}" height="${H - g}" fill="#cfe3c2"/>`;
  for (let k = 0; k < secs; k++) {
    const x0 = 20 + k * sw;
    for (let f = 1; f <= b.floors; f++) {
      const y = g - f * fh, com = b.commercialFirst && f === 1, cols = com ? 1 : pos.cols;
      for (let c = 0; c < cols; c++) {
        const cw = (sw - 4) / cols, x = x0 + 2 + c * cw, sel = (com && !a.rooms && k + 1 === a.section) || (!com && a.rooms && k + 1 === a.section && f === a.floor && c === pos.col);
        s += `<rect x="${x + 1}" y="${y + 1}" width="${cw - 2}" height="${fh - 2}" fill="${sel ? '#f08c00' : com ? '#d5dde7' : '#f4efe6'}" stroke="${sel ? '#b45309' : '#c9c1b2'}" stroke-width="${sel ? 2.5 : .6}" rx="1"/>`;
        if (!sel) for (let w = 0; w < 2; w++) s += `<rect x="${x + cw * (0.18 + w * 0.42)}" y="${y + fh * 0.25}" width="${cw * 0.22}" height="${fh * 0.45}" fill="#8db0cf"/>`;
      }
    }
    s += `<text x="${x0 + sw / 2}" y="${H - 9}" text-anchor="middle" font-size="12" fill="#3f4b3a">Подъезд ${k + 1}</text>`;
  }
  if (a.rooms) { const y = g - a.floor * fh + fh / 2 + 4; s += `<text x="${W - 4}" y="${y}" text-anchor="end" font-size="12" font-weight="700" fill="#b45309">◀ ${a.floor} эт.</text>`; }
  s += `<line x1="0" y1="${g}" x2="${W}" y2="${g}" stroke="#5b6b4a" stroke-width="2"/><text x="6" y="16" font-size="12" fill="#445">Фасад ${pos.row === 'south' ? 'со стороны двора (юг)' : 'со стороны улицы (север)'}</text></svg>`;
  return s;
}
/* ---------- схема этажа ---------- */
function aptFloorSvg(p, a) {
  const b = p.b, A = genApts(p).filter(x => x.section === a.section && x.floor === a.floor), apf = b.aptsPerFloor, cols = Math.ceil(apf / 2), ncols = apf - cols;
  const W = 620, H = 330, x0 = 60, y0 = 46, w = W - 120, h = H - 100, coreW = w * 0.16, corrH = h * 0.16, rowH = (h - corrH) / 2;
  const col = st => ({ free: '#dcfce7', booked: '#fef3c7', sold: '#fee2e2', reserve: '#e2e8f0' }[st]);
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect width="${W}" height="${H}" fill="#f8fafc"/>`;
  s += `<text x="${W / 2}" y="22" text-anchor="middle" font-size="13" fill="#475569">▲ Улица и парковка (север)</text><text x="${W / 2}" y="${H - 12}" text-anchor="middle" font-size="13" fill="#15803d">▼ Двор: детская площадка, сквер (юг)</text>`;
  if (a.section > 1) s += `<text x="12" y="${y0 + h / 2}" font-size="11" fill="#94a3b8">◀ подъезд ${a.section - 1}</text>`;
  if (a.section < b.sections) s += `<text x="${W - 12}" y="${y0 + h / 2}" text-anchor="end" font-size="11" fill="#94a3b8">подъезд ${a.section + 1} ▶</text>`;
  s += `<rect x="${x0}" y="${y0}" width="${w}" height="${h}" fill="#fff" stroke="#334155" stroke-width="3"/>`;
  s += `<rect x="${x0}" y="${y0 + rowH}" width="${w}" height="${corrH}" fill="#eef2f7"/><text x="${x0 + 8}" y="${y0 + rowH + corrH / 2 + 4}" font-size="10.5" fill="#64748b">коридор</text>`;
  s += `<rect x="${x0 + w / 2 - coreW / 2}" y="${y0 + rowH - 6}" width="${coreW}" height="${corrH + 12}" fill="#cbd5e1" stroke="#64748b"/><text x="${x0 + w / 2}" y="${y0 + rowH + corrH / 2 + 4}" text-anchor="middle" font-size="10.5" fill="#334155">лестница · лифт</text>`;
  const draw = (ap, i, n, south) => {
    const cw = w / n, x = x0 + i * cw, y = south ? y0 + rowH + corrH : y0, sel = ap.id === a.id;
    s += `<rect x="${x + 2}" y="${y + 2}" width="${cw - 4}" height="${rowH - 4}" fill="${sel ? '#fed7aa' : col(ap.status)}" stroke="${sel ? '#ea580c' : '#94a3b8'}" stroke-width="${sel ? 4 : 1.2}"/>`;
    s += `<text x="${x + cw / 2}" y="${y + rowH / 2 - 6}" text-anchor="middle" font-size="${sel ? 15 : 13}" font-weight="700" fill="${sel ? '#9a3412' : '#334155'}">№${ap.num} · ${ap.rooms}к</text><text x="${x + cw / 2}" y="${y + rowH / 2 + 12}" text-anchor="middle" font-size="12" fill="#475569">${ap.area} м² · ${T(ST_NAME[ap.status])}</text>`;
    if (sel) s += `<text x="${x + cw / 2}" y="${y + rowH / 2 + 30}" text-anchor="middle" font-size="12" font-weight="700" fill="#ea580c">★ ваша квартира</text>`;
    s += `<line x1="${x + cw * 0.25}" y1="${south ? y + rowH - 1 : y + 1}" x2="${x + cw * 0.75}" y2="${south ? y + rowH - 1 : y + 1}" stroke="#60a5fa" stroke-width="5"/>`;
  };
  A.filter(x => x.idx < cols).forEach((ap, i) => draw(ap, i, cols, true));
  A.filter(x => x.idx >= cols).forEach((ap, i) => draw(ap, i, ncols, false));
  s += `<g transform="translate(${W - 34},${H - 70})"><circle r="16" fill="#fff" stroke="#94a3b8"/><path d="M0 -12 L5 4 L0 0 L-5 4Z" fill="#dc2626"/><text y="-19" text-anchor="middle" font-size="10" fill="#dc2626">С</text></g></svg>`;
  return s;
}
/* ---------- планировка квартиры ---------- */
function aptPlanSvg(a) {
  const plan = planApt(Math.min(3, a.rooms || 1), a.area), W = 620, H = 380, pad = 30, sc = Math.min((W - pad * 2) / plan.W, (H - pad * 2 - 20) / plan.D), ox = (W - plan.W * sc) / 2, oz = pad;
  const fill = { kl: '#fef3c7', bedroom: '#e0e7ff', room: '#e0e7ff', kitchen: '#fde68a', corr: '#f1f5f9', bath: '#cffafe', wc: '#cffafe', hall: '#f1f5f9', closet: '#ede9fe' };
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect width="${W}" height="${H}" fill="#fff"/>`;
  plan.rooms.forEach(r => {
    const x = ox + r.x * sc, y = oz + r.z * sc, w = r.w * sc, h = r.d * sc;
    s += `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fill[r.type] || '#f8fafc'}" stroke="#1e293b" stroke-width="2.5"/>`;
    s += `<text x="${x + w / 2}" y="${y + h / 2 - 2}" text-anchor="middle" font-size="${w > 90 ? 13 : 10.5}" font-weight="600" fill="#1e293b">${T(r.name)}</text><text x="${x + w / 2}" y="${y + h / 2 + 14}" text-anchor="middle" font-size="11" fill="#475569">${fmt(r.w * r.d, 1)} м²</text>`;
  });
  plan.walls.forEach(wl => (wl.ops || []).forEach(o => {
    const a0 = (o.at - o.w / 2) * sc, a1 = (o.at + o.w / 2) * sc, c = wl.c * sc;
    const xy = wl.axis === 'x' ? [ox + a0, oz + c, ox + a1, oz + c] : [ox + c, oz + a0, ox + c, oz + a1];
    s += `<line x1="${xy[0]}" y1="${xy[1]}" x2="${xy[2]}" y2="${xy[3]}" stroke="${o.kind === 'window' ? '#3b82f6' : '#fff'}" stroke-width="${o.kind === 'window' ? 6 : 5}"/>`;
  }));
  s += `<text x="${W / 2}" y="16" text-anchor="middle" font-size="12" fill="#2563eb">▲ окна</text><text x="${W / 2}" y="${H - 6}" text-anchor="middle" font-size="12" fill="#64748b">вход из коридора ▼ · габариты ≈ ${fmt(plan.W, 1)} × ${fmt(plan.D, 1)} м</text></svg>`;
  return { svg: s, plan };
}
/* ---------- расчёт оплаты ---------- */
const PAY_PLANS = [['full', 'Полная оплата'], ['r6', 'Рассрочка 6 мес'], ['r12', 'Рассрочка 12 мес'], ['r24', 'Рассрочка 24 мес'], ['r36', 'Рассрочка 36 мес'], ['ipo', 'Ипотека 15 лет']];
function aptCalc(p, a) {
  const c = (p.apts[a.id] && p.apts[a.id].calc) || {}, plan = c.plan || 'r12', disc = c.disc ?? (plan === 'full' ? 5 : 0), firstPct = c.first ?? (plan === 'full' ? 100 : 30), rate = c.rate ?? 18;
  const price = Math.round(a.price * (1 - disc / 100) / 1000) * 1000, first = plan === 'full' ? price : Math.round(price * firstPct / 100 / 1000) * 1000, rest = price - first;
  let months = 0, monthly = 0;
  if (plan.startsWith('r')) { months = +plan.slice(1); monthly = rest / months; }
  if (plan === 'ipo') { months = 180; const r = rate / 100 / 12; monthly = rest * r / (1 - Math.pow(1 + r, -months)); }
  return { plan, disc, firstPct, rate, price, first, rest, months, monthly, total: plan === 'ipo' ? first + monthly * months : price };
}
/* ---------- страница ---------- */
PAGES.apt = {
  title: 'Квартира для клиента',
  fullOnChange: el => /^apts\./.test(el.dataset.bind || ''),
  render(p, args) {
    const A = genApts(p), a = A.find(x => x.id === args.id);
    if (!a) return aptPicker(p, args);
    const tab = args.tab || 'house', o = a.o, pos = aptPos(p, a), ppm = a.price / a.area, booked = a.status === 'booked' || a.status === 'sold';
    const tabs = [['house', '🏢 В доме'], ['route', '🚶 Как пройти'], ['plan', '📐 Планировка'], ...(a.rooms ? [['interior', '🛋 3D-интерьер'], ['photo', '📸 Фото']] : []), ['pay', '💰 Цена и оплата']];
    let body = '';
    if (tab === 'house') body = `<div class="grid apt-house"><div class="site-viewer" id="aptSite"><div class="v-tools" id="s3Tools"></div></div>
      <div class="card" style="margin:0"><h3>Где находится квартира</h3>${aptFacts(p, a).map(([ic, k, v]) => `<div class="fact"><span>${ic}</span><div><small>${k}</small><b>${v}</b></div></div>`).join('')}
      <div class="flex mt"><button class="btn-p" data-act="aptWin">🪟 Вид из окна</button><button data-act="aptFocus">🎯 Показать квартиру</button></div></div></div>
      <div class="card mt"><h3>Схема фасада</h3>${aptFacadeSvg(p, a)}</div>`;
    if (tab === 'route') body = aptRouteHtml(p, a);
    if (tab === 'plan') { const P2 = a.rooms ? aptPlanSvg(a) : null;
      body = `<div class="grid g2"><div class="card"><h3>План ${a.floor} этажа, подъезд ${a.section}</h3>${a.rooms ? aptFloorSvg(p, a) : '<p class="muted">Коммерческое помещение занимает весь первый этаж секции.</p>'}
        <div class="legend mt"><span><i style="background:#fed7aa;border:2px solid #ea580c"></i>эта квартира</span><span><i style="background:#dcfce7"></i>свободна</span><span><i style="background:#fef3c7"></i>бронь</span><span><i style="background:#fee2e2"></i>продана</span><span><i style="background:#60a5fa"></i>окна</span></div></div>
        ${P2 ? `<div class="card"><h3>Планировка квартиры №${a.num} · ${a.area} м²</h3>${P2.svg}
        <table class="mt"><tbody>${P2.plan.rooms.map(r => `<tr><td>${T(r.name)}</td><td class="n">${fmt(r.w * r.d, 1)} м²</td></tr>`).join('')}</tbody></table></div>` : ''}</div>`; }
    if (tab === 'interior') body = `<div class="flex mb">${Object.entries(STYLES).map(([k, s]) => `<button class="btn-s${(o.style || 'modern') === k ? ' btn-p' : ''}" data-act="aptStyle" data-style="${k}">${s.name}</button>`).join('')}<span class="right"></span><button class="pano-btn btn-s" data-act="d3Pano">🌐 Тур 360°</button></div>
      <div class="viewer" id="viewer" style="height:70vh"><div class="v-tools"><button data-act="d3View" data-v="persp">🏠 Обзор</button><button data-act="d3View" data-v="top">📐 План сверху</button><button data-act="d3Walk" id="bWalk">🚶 Прогулка</button><button data-act="d3Night" id="bNight">🌙 Вечер</button><button data-act="d3HQ" id="bHQ">✨</button></div><div class="v-help" id="vHelp"></div><div class="v-move"><button data-hold="1">▲</button><button data-hold="-1">▼</button></div></div>`;
    if (tab === 'photo') body = photoGallery(o.style || 'modern');
    if (tab === 'pay') body = `<div id="results">${this.results(p, args)}</div>`;
    const ld = args.lead ? p.leads.find(l => l.id === args.lead) : null;
    return `${ld ? `<div class="note">👤 Клиент: <b>${esc(ld.name)}</b> · <a href="${telHref(ld.phone)}">${esc(ld.phone)}</a> · ищет: ${esc(leadWant(ld) || 'не указано')} <button class="btn-s" data-act="leadOpen" data-id="${ld.id}">карточка лида</button></div>` : ''}<div class="apt-head">
      <div><div class="small muted">ЖК «${esc(p.name.replace(/^ЖК\s*/, '').replace(/[«»]/g, ''))}» · подъезд ${a.section} · ${a.floor} этаж</div>
        <h2>${a.rooms ? `${a.rooms}-комнатная квартира №${a.num}` : 'Коммерческое помещение ' + a.num}</h2>
        <div class="flex"><span class="pill p-${a.status}">${T(ST_NAME[a.status])}</span><span class="pill p-reserve">${a.area} м²</span><span class="pill p-reserve">${pos.corner ? 'угловая' : 'в середине'}</span><span class="pill p-reserve">${pos.row === 'south' ? 'окна во двор' : 'окна на улицу'}</span>${o.bookedUntil && a.status === 'booked' ? `<span class="pill p-booked">бронь до ${fdate(o.bookedUntil)}</span>` : ''}</div></div>
      <div class="apt-price"><b>${money(a.price)}</b><span>${money(ppm)} за м²</span>
        <div class="flex mt">${!booked ? '<button class="btn-p big-btn" data-act="aptBookForm" data-id="' + a.id + '">✍️ Забронировать и внести задаток</button>' : ''}
        ${a.status === 'booked' ? `<button class="btn-p" data-act="aptSell" data-id="${a.id}">✅ Оформить продажу</button><button data-act="aptUnbook" data-id="${a.id}">Снять бронь</button>` : ''}
        ${booked ? `<button data-act="apt" data-id="${a.id}">💵 Платежи покупателя</button><button data-act="aptDocs" data-id="${a.id}">🖨 Документы</button>` : ''}</div></div>
    </div>
    <div class="flex mb seg">${tabs.map(([k, l]) => `<button class="${k === tab ? 'btn-p' : ''}" data-act="aptTab" data-tab="${k}">${l}</button>`).join('')}<span class="right"></span><button class="btn-s" data-act="aptPrev" data-id="${a.id}">◀</button><button class="btn-s" data-act="aptNext" data-id="${a.id}">▶</button><button class="btn-s" data-act="go" data-page="sales">▦ Шахматка</button></div>
    ${body}`;
  },
  results(p, args) {
    const a = genApts(p).find(x => x.id === args.id), c = aptCalc(p, a), base = `apts.${a.id}.calc`;
    const rows = []; if (c.months && c.plan !== 'ipo') { let d = new Date(today() + 'T00:00:00'); for (let i = 1; i <= Math.min(c.months, 36); i++) { d.setMonth(d.getMonth() + 1); rows.push([i, isoDate(d), c.monthly]); } }
    return `<div class="grid g2"><div class="card"><h3>Калькулятор оплаты</h3><div class="form">
      <label class="fld"><span>Условия</span><select data-bind="${base}.plan">${opts(PAY_PLANS, c.plan)}</select></label>
      <label class="fld"><span>Скидка, %</span><input class="txt" type="number" step="0.5" data-bind="${base}.disc" value="${c.disc}"></label>
      ${c.plan !== 'full' ? `<label class="fld"><span>Первый взнос, %</span><input class="txt" type="number" step="5" data-bind="${base}.first" value="${c.firstPct}"></label>` : ''}
      ${c.plan === 'ipo' ? `<label class="fld"><span>Ставка банка, % годовых</span><input class="txt" type="number" step="0.5" data-bind="${base}.rate" value="${c.rate}"></label>` : ''}</div>
      <div class="big-res mt">${rbox('Цена со скидкой', money(c.price), 1)}${rbox('Первый взнос', money(c.first), 1)}${c.months ? rbox(c.plan === 'ipo' ? 'Платёж банку в месяц' : 'Ежемесячный платёж', money(c.monthly), 1) + rbox('Срок', c.months + ' мес') : ''}${c.plan === 'ipo' ? rbox('Всего с процентами', money(c.total)) : ''}${c.disc ? rbox('Экономия', money(a.price - c.price)) : ''}</div>
      <div class="small muted mt">Расчёт предварительный. Условия ипотеки уточняются в банке.</div></div>
      <div class="card"><h3>График платежей</h3>${rows.length ? `<div class="tbl-wrap" style="max-height:380px"><table><thead><tr><th>№</th><th>Дата</th><th class="n">Сумма</th></tr></thead><tbody><tr class="grp"><td>—</td><td>${fdate(today())}</td><td class="n">${money(c.first)} (взнос)</td></tr>${rows.map(r => `<tr><td>${r[0]}</td><td>${fdate(r[1])}</td><td class="n">${money(r[2])}</td></tr>`).join('')}</tbody></table></div>` : `<p class="muted">${c.plan === 'full' ? 'Оплата одной суммой: ' + money(c.price) : 'Ипотека: первый взнос ' + money(c.first) + ', остальное — кредит банка ' + money(c.rest)}</p>`}</div></div>`;
  },
  mounted(p, args) {
    const a = genApts(p).find(x => x.id === args.id); if (!a) return; const tab = args.tab || 'house';
    if (tab === 'house' || tab === 'route' && a.rooms) { const pos = aptPos(p, a); S3.mode = 'done'; S3.night = false; S3.route = tab === 'route'; s3Init($('#aptSite'), { section: a.section, floor: a.floor, col: pos.col, cols: pos.cols, row: pos.row, comm: pos.comm }); }
    if (tab === 'interior') { d3Init($('#viewer'), { rooms: Math.min(3, a.rooms), area: a.area, style: a.o.style || 'modern' }); if (args.pano) setTimeout(() => d3Pano(), 300); }
  },
  destroy() { s3Dispose(); d3Dispose(); }
};
function aptPicker(p, a) {
  const A = genApts(p).filter(x => x.rooms), f = { rooms: a.rooms || '', st: a.st ?? 'free', side: a.side || '', corner: a.corner || '', fmin: num(a.fmin) || 0, fmax: num(a.fmax) || 99, pmax: num(a.pmax) || 0 };
  const list = A.filter(x => (!f.rooms || String(x.rooms) === f.rooms) && (!f.st || x.status === f.st) && x.floor >= f.fmin && x.floor <= f.fmax && (!f.pmax || x.price <= f.pmax * 1e6) && (!f.side || aptPos(p, x).row === f.side) && (!f.corner || (f.corner === 'yes') === aptPos(p, x).corner));
  const ld = a.lead ? p.leads.find(l => l.id === a.lead) : null;
  return `${ld ? `<div class="note">👤 Подбор для клиента: <b>${esc(ld.name)}</b> · ${esc(ld.phone)} · ищет: ${esc(leadWant(ld) || 'не указано')}</div>` : ''}<div class="card"><h3>🔎 Подбор квартиры для клиента</h3><div class="form">
    <label class="fld"><span>Комнат</span><select data-arg="rooms"><option value="">любое</option>${opts([['1', '1-комнатная'], ['2', '2-комнатная'], ['3', '3-комнатная']], f.rooms)}</select></label>
    <label class="fld"><span>Статус</span><select data-arg="st"><option value="">все</option>${opts(STATUSES, f.st)}</select></label>
    <label class="fld"><span>Окна</span><select data-arg="side"><option value="">любые</option>${opts([['south', 'во двор (юг)'], ['north', 'на улицу (север)']], f.side)}</select></label>
    <label class="fld"><span>Положение</span><select data-arg="corner"><option value="">любое</option>${opts([['yes', 'угловая'], ['no', 'в середине']], f.corner)}</select></label>
    <label class="fld"><span>Этаж от</span><input class="txt" type="number" data-arg="fmin" value="${a.fmin || ''}"></label>
    <label class="fld"><span>Этаж до</span><input class="txt" type="number" data-arg="fmax" value="${a.fmax || ''}"></label>
    <label class="fld"><span>Бюджет до, млн ${cur()}</span><input class="txt" type="number" data-arg="pmax" value="${a.pmax || ''}"></label></div>
    <div class="small muted mt">Найдено: ${list.length}. Нажмите на квартиру — откроется презентация для клиента.</div></div>
    <div class="pick-grid">${list.map(x => { const ps = aptPos(p, x); return `<button class="pick" data-act="aptOpen" data-id="${x.id}"${a.lead ? ` data-lead="${a.lead}"` : ''}><span class="pill p-${x.status}">${T(ST_NAME[x.status])}</span><b>№${x.num} · ${x.rooms}-комн.</b><span>${x.area} м² · ${x.floor} этаж · подъезд ${x.section}</span><span>${ps.corner ? '📐 угловая' : '▫️ в середине'} · ${ps.row === 'south' ? '🌳 во двор' : '🚗 на улицу'}</span><em>${moneyS(x.price)}</em></button>`; }).join('') || '<p class="muted">Нет квартир по этим условиям</p>'}</div>`;
}
/* ---------- действия ---------- */
ACT.aptOpen = d => go('apt', { id: d.id, lead: d.lead || (curPage === 'apt' ? pageArgs.lead : undefined) });
ACT.aptTab = d => { const id = pageArgs.id; go('apt', { id, tab: d.tab, lead: pageArgs.lead }); };
ACT.aptPrev = d => { const A = genApts(P()), i = A.findIndex(x => x.id === d.id); go('apt', { id: A[(i - 1 + A.length) % A.length].id, tab: pageArgs.tab, lead: pageArgs.lead }); };
ACT.aptNext = d => { const A = genApts(P()), i = A.findIndex(x => x.id === d.id); go('apt', { id: A[(i + 1) % A.length].id, tab: pageArgs.tab, lead: pageArgs.lead }); };
ACT.aptWin = () => s3WindowView();
ACT.aptFocus = () => s3FocusApt();
ACT.aptStyle = d => { aptObj(P(), pageArgs.id).style = d.style; save(); $$('[data-act="aptStyle"]').forEach(b => b.classList.toggle('btn-p', b.dataset.style === d.style)); d3SetStyle(d.style); };
ACT.aptBookForm = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), c = aptCalc(p, a), dep = Math.round(Math.max(5e6, c.price * 0.05) / 1e5) * 1e5, ld = pageArgs.lead ? p.leads.find(l => l.id === pageArgs.lead) : null;
  if (ld && !a.buyer) { a.buyer = ld.name; a.phone = ld.phone; }
  openModal(`<h2>✍️ Бронирование и задаток</h2><div class="muted">Квартира №${a.num} · ${a.rooms}-комн. · ${a.area} м² · ${a.floor} этаж · подъезд ${a.section}</div>
  <div class="form mt">
    <label class="fld"><span>ФИО покупателя *</span><input class="txt" id="bkName" value="${esc(a.buyer)}"></label>
    <label class="fld"><span>Телефон *</span><input class="txt" id="bkPhone" value="${esc(a.phone)}" placeholder="+998"></label>
    <label class="fld"><span>Паспорт (серия, номер)</span><input class="txt" id="bkPass"></label>
    <label class="fld"><span>Цена квартиры, ${cur()}</span><input class="txt" type="number" id="bkPrice" value="${c.price}"></label>
    <label class="fld"><span>Условия оплаты</span><select id="bkPlan">${opts(PAY_PLANS, c.plan)}</select></label>
    <label class="fld"><span>Сумма задатка, ${cur()} *</span><input class="txt" type="number" id="bkDep" value="${dep}"></label>
    <label class="fld"><span>Способ оплаты</span><select id="bkWay">${opts(['Наличные', 'Банковская карта', 'Перевод на счёт'])}</select></label>
    <label class="fld"><span>Бронь действует, дней</span><input class="txt" type="number" id="bkDays" value="14"></label>
    <label class="fld chk" style="grid-column:1/-1"><input type="checkbox" id="bkSold"> <span>Договор купли-продажи уже подписан — сразу отметить «Продана»</span></label></div>
  <div class="note mt">После оформления: квартира станет «${T('Бронь')}» в шахматке, задаток проведётся в бухгалтерии, откроются соглашение о задатке и квитанция для печати.</div>
  <div class="flex"><button class="btn-p big-btn" data-act="aptBook" data-id="${a.id}">✅ Оформить задаток</button></div>`);
};
ACT.aptBook = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), name = $('#bkName').value.trim(), phone = $('#bkPhone').value.trim(), dep = num($('#bkDep').value);
  if (!name || !phone) return toast('Укажите ФИО и телефон покупателя'); if (dep <= 0) return toast('Укажите сумму задатка');
  const o = aptObj(p, a.id), price = num($('#bkPrice').value) || a.price, date = today(), id = uid(), sold = $('#bkSold').checked;
  Object.assign(o, { buyer: name, phone, passport: $('#bkPass').value.trim(), plan: Object.fromEntries(PAY_PLANS)[$('#bkPlan').value], status: sold ? 'sold' : 'booked', bookedUntil: addDays(date, num($('#bkDays').value) || 14), payWay: $('#bkWay').value });
  if (price !== a.basePrice) o.price = price; else delete o.price;
  o.payments = o.payments || [];
  const tx = addTx(p, { date, type: 'in', cat: 'Продажа квартир', amount: dep, who: name, desc: `Задаток за кв. №${a.num}`, link: { kind: 'apt', id: a.id, payId: id } });
  o.payments.push({ id, date, amount: dep, txId: tx.id, kind: 'deposit', way: o.payWay }); o.depositId = id;
  const ld = (pageArgs.lead && p.leads.find(l => l.id === pageArgs.lead)) || p.leads.find(l => l.phone && l.phone.replace(/\D/g, '') === phone.replace(/\D/g, ''));
  if (ld) { ld.status = sold ? 'deal' : 'booked'; ld.aptId = a.id; ld.nextDate = sold ? '' : o.bookedUntil; ld.nextText = sold ? '' : 'Подписать договор до окончания брони'; leadLog(ld, `Задаток ${money(dep)} за кв. №${a.num}`); }
  save(); closeModal(); rerender(); toast(sold ? 'Продажа оформлена, задаток проведён' : 'Квартира забронирована, задаток проведён'); setTimeout(() => ACT.aptDocs({ id: a.id }), 200);
};
ACT.aptSell = async d => { const o = aptObj(P(), d.id); if (!await ui.confirm(T('Отметить квартиру как проданную? (договор купли-продажи подписан)'))) return; o.status = 'sold'; const ld = P().leads.find(l => l.aptId === d.id); if (ld) { ld.status = 'deal'; ld.nextDate = ''; leadLog(ld, 'Сделка: договор подписан'); } save(); rerender(); toast('Квартира продана'); };
ACT.aptUnbook = async d => { const p = P(), o = aptObj(p, d.id); if (!await ui.confirm(T('Снять бронь? Покупатель и его оплаты перейдут в историю квартиры, следующий покупатель начнёт с нуля. Задаток верните покупателю — оформите расход.'), { danger: true, ok: T('Снять бронь') })) return; o.status = 'free'; delete o.bookedUntil; aptArchive(o); save(); rerender(); };
ACT.aptDocs = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), o = a.o, dep = (o.payments || []).find(x => x.id === o.depositId) || (o.payments || [])[0];
  if (!dep) return toast('Нет задатка по этой квартире');
  openModal(`<h2>🖨 Документы по квартире №${a.num}</h2><div class="flex mt"><button class="btn-p" data-act="aptPrint" data-id="${a.id}" data-doc="deposit">📄 Соглашение о задатке</button><button class="btn-p" data-act="aptPrint" data-id="${a.id}" data-doc="receipt">🧾 Квитанция о приёме задатка</button><button data-act="aptPrint" data-id="${a.id}" data-doc="offer">📑 Коммерческое предложение</button></div>
    <p class="small muted mt">Документ откроется в новом окне и отправится на печать (можно сохранить в PDF). Проверьте данные перед подписанием; соглашение — типовой шаблон, при необходимости согласуйте с юристом.</p>`);
};
function aptDocHtml(p, a, kind) {
  const o = a.o, dep = (o.payments || []).find(x => x.id === o.depositId) || (o.payments || [])[0] || { amount: 0, date: today() }, c = DB.settings.company, ln = '______________________';
  const head = `<style>body{font:14px/1.6 'Times New Roman',serif;margin:40px;color:#111}h1{font-size:18px;text-align:center;margin:0 0 6px}h2{font-size:15px;margin:18px 0 6px}.c{text-align:center}.r{text-align:right}table{width:100%;border-collapse:collapse;margin:10px 0}td{border:1px solid #999;padding:5px 8px}.sig{display:flex;justify-content:space-between;margin-top:50px}.sig div{width:45%}@media print{button{display:none}}</style>`;
  const info = `<table><tr><td>Объект</td><td>${esc(p.name)}, ${esc(p.address || '')}</td></tr><tr><td>Квартира</td><td>№${a.num}, ${a.rooms}-комнатная, общая площадь ${a.area} м²</td></tr><tr><td>Подъезд / этаж</td><td>${a.section} / ${a.floor} из ${p.b.floors}</td></tr><tr><td>Цена</td><td>${money(a.price)} (${money(a.price / a.area)} за м²)</td></tr><tr><td>Условия оплаты</td><td>${esc(o.plan || '—')}</td></tr></table>`;
  if (kind === 'receipt') return `${head}<h1>КВИТАНЦИЯ № ${a.num}-${(dep.id || '').slice(0, 4).toUpperCase()}</h1><p class="c">о приёме задатка от ${fdate(dep.date)}</p><table><tr><td>Получатель</td><td>${esc(c)}</td></tr><tr><td>Плательщик</td><td>${esc(o.buyer)}, тел. ${esc(o.phone)}${o.passport ? ', паспорт ' + esc(o.passport) : ''}</td></tr><tr><td>Основание</td><td>Задаток за квартиру №${a.num} в ${esc(p.name)}</td></tr><tr><td>Сумма</td><td><b>${money(dep.amount)}</b></td></tr><tr><td>Способ оплаты</td><td>${esc(dep.way || o.payWay || '—')}</td></tr></table><div class="sig"><div>Принял: ${ln}<br><small>(ФИО, подпись) М.П.</small></div><div>Оплатил: ${ln}<br><small>(подпись)</small></div></div>`;
  if (kind === 'offer') { const cl = aptCalc(p, a); return `${head}<h1>КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ</h1><p class="c">${esc(c)} · ${fdate(today())}</p>${info}<h2>Расположение</h2><ul>${aptFacts(p, a).map(([, k, v]) => `<li>${k}: ${v}</li>`).join('')}</ul><h2>Оплата</h2><p>${Object.fromEntries(PAY_PLANS)[cl.plan]}: цена ${money(cl.price)}, первый взнос ${money(cl.first)}${cl.months ? `, ежемесячно ${money(cl.monthly)} в течение ${cl.months} мес.` : ''}.</p>`; }
  return `${head}<h1>СОГЛАШЕНИЕ О ЗАДАТКЕ</h1><p class="c">(бронирование квартиры)</p><p class="r">${fdate(dep.date)}</p>
  <p><b>${esc(c)}</b>, именуемое «Продавец», с одной стороны, и <b>${esc(o.buyer)}</b>${o.passport ? ', паспорт ' + esc(o.passport) : ''}, тел. ${esc(o.phone)}, именуемый(ая) «Покупатель», с другой стороны, заключили настоящее соглашение о нижеследующем:</p>
  <h2>1. Предмет соглашения</h2><p>1.1. Продавец обязуется не предлагать третьим лицам и забронировать за Покупателем квартиру:</p>${info}
  <p>1.2. Срок бронирования — до ${fdate(o.bookedUntil || addDays(today(), 14))} включительно. В этот срок стороны обязуются заключить договор купли-продажи (или договор долевого участия).</p>
  <h2>2. Задаток</h2><p>2.1. В обеспечение исполнения обязательств Покупатель передаёт, а Продавец принимает задаток в размере <b>${money(dep.amount)}</b> (${esc(dep.way || o.payWay || '')}).</p><p>2.2. При заключении договора сумма задатка засчитывается в счёт цены квартиры.</p><p>2.3. Если договор не заключён по вине Покупателя — задаток остаётся у Продавца. Если по вине Продавца — Продавец возвращает задаток в двойном размере, в соответствии с гражданским законодательством.</p><p>2.4. При невозможности заключения договора по причинам, за которые ни одна сторона не отвечает, задаток возвращается Покупателю в течение 10 рабочих дней.</p>
  <h2>3. Прочие условия</h2><p>3.1. Соглашение составлено в двух экземплярах, по одному для каждой стороны, и вступает в силу с момента подписания и передачи задатка.</p>
  <div class="sig"><div><b>Продавец</b><br>${esc(c)}<br><br>${ln}<br><small>(подпись) М.П.</small></div><div><b>Покупатель</b><br>${esc(o.buyer)}<br><br>${ln}<br><small>(подпись)</small></div></div>`;
}
ACT.aptPrint = d => {
  const p = P(), a = genApts(p).find(x => x.id === d.id), html = aptDocHtml(p, a, d.doc), w = window.open('', '_blank');
  if (!w) { download(`dokument_kv${a.num}.html`, '<!doctype html><meta charset="utf-8">' + html, 'text/html'); return toast('Окно заблокировано — документ скачан файлом'); }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Документ</title></head><body>' + html + '<p><button onclick="print()">🖨 Печать</button></p></body></html>'); w.document.close(); setTimeout(() => { try { w.focus(); w.print(); } catch (e) { } }, 400);
};
