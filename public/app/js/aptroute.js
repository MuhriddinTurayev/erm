'use strict';
/* ===== Как пройти к квартире: от калитки до двери — подъезд, лифт, налево/направо, какая дверь ===== */
function aptRoute(p, a) {
  const b = p.b, pos = aptPos(p, a), sw = b.length / b.sections, apf = b.aptsPerFloor, cols = Math.ceil(apf / 2), ncols = apf - cols, coreW = Math.max(3.2, sw * 0.16);
  // двери всех квартир этажа: x от оси лифта (м), ряд
  const doors = []; for (let i = 0; i < apf; i++) { const south = i < cols, n = south ? cols : ncols, k = south ? i : i - cols; doors.push({ idx: i, south, dx: -sw / 2 + (k + 0.5) * sw / n }); }
  const me = doors.find(d => d.idx === a.idx) || doors[0];
  // выходим из лифта лицом к двору (на юг): запад — справа, восток — слева
  const straight = Math.abs(me.dx) < coreW / 2, turn = straight ? 'straight' : me.dx < 0 ? 'right' : 'left';
  const same = doors.filter(d => d.south === me.south && (straight ? Math.abs(d.dx) < coreW / 2 : Math.sign(d.dx) === Math.sign(me.dx) && Math.abs(d.dx) >= coreW / 2)).sort((x, y) => Math.abs(x.dx) - Math.abs(y.dx));   // считаем двери только по своей стороне коридора
  const nth = same.indexOf(me) + 1;
  // идём на запад: юг — слева; идём на восток: юг — справа
  const side = straight ? (me.south ? 'перед вами' : 'за спиной') : (turn === 'right') === me.south ? 'слева' : 'справа';
  const dist = Math.round(Math.abs(me.dx)), liftSec = Math.round(4 + (a.floor - 1) * 3.3 / 1.5);
  const ord = ['', 'первая', 'вторая', 'третья', 'четвёртая', 'пятая', 'шестая'][nth] || nth + '-я';
  const steps = [
    ['🚗', 'Въезд и парковка', 'С улицы, с северной стороны дома. Гостевая парковка — у калитки.'],
    ['🚪', 'Калитка во двор', 'Проходите через калитку, мимо парковки, и по дорожке вдоль торца дома (дом будет слева) — во двор.'],
    ['🏢', `Подъезд №${a.section}`, `Все входы — со двора. Если стоять во дворе лицом к дому, это ${['', 'первый', 'второй', 'третий', 'четвёртый', 'пятый', 'шестой'][a.section] || a.section + '-й'} вход слева. Над дверью — синяя табличка «${a.section}».`],
    a.floor <= 1 ? ['🚶', 'Первый этаж', 'Лифт не нужен — квартира на первом этаже, проходите прямо по холлу.']
      : ['🛗', `Лифт → ${a.floor} этаж`, `Лифт прямо по холлу${b.floors > 9 ? ' (в подъезде 2 лифта)' : ''}, подъём ≈ ${liftSec} сек. Лестница — рядом с лифтом.`],
    straight ? ['⬆️', 'Выйдя из лифта', `Квартира прямо ${me.south ? 'перед вами' : 'за вашей спиной'}, через коридор.`]
      : [turn === 'right' ? '↪️' : '↩️', `Из лифта — ${turn === 'right' ? 'НАПРАВО' : 'НАЛЕВО'}`, `Пройдите по коридору ≈ ${dist} м.`],
    ['🏠', `Квартира №${a.num}`, straight ? `Дверь напротив лифта.` : `${ord[0].toUpperCase() + ord.slice(1)} дверь ${side}.`],
  ];
  return { steps, turn, side, nth, dist, me, doors, sw, coreW, cols, ncols, straight };
}
/* план участка с маршрутом от калитки до подъезда */
function aptSiteRouteSvg(p, a) {
  const Ly = siteLayout(p), W = 620, H = Math.round(W * Math.min(1.1, Ly.SD / Ly.SW)) + 40, k = (W - 40) / Ly.SW, X = x => 20 + (x + Ly.SW / 2) * k, Z = z => 30 + (z + Ly.SD / 2) * k;
  const b = p.b, sw = Ly.L / b.sections, zs = Ly.z0 + Ly.Wd / 2, wx = -Ly.L / 2 - 6, wz0 = -Ly.SD / 2 + 14, wz1 = zs + 3, gx = -Ly.SW / 4, xc = -Ly.L / 2 + sw * (a.section - 0.5);
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect width="${W}" height="${H}" fill="#eef2ea"/>`;
  s += `<rect x="0" y="4" width="${W}" height="22" fill="#4b5563"/><text x="${W / 2}" y="19" text-anchor="middle" font-size="11" fill="#fff">улица</text>`;
  s += `<rect x="${X(-Ly.SW / 2)}" y="${Z(-Ly.SD / 2)}" width="${Ly.SW * k}" height="${Ly.SD * k}" fill="#cfe3b8" stroke="#334155" stroke-dasharray="4 3"/>`;
  s += `<rect x="${X(-(Ly.L + 24) / 2)}" y="${Z(-Ly.SD / 2 + 1)}" width="${(Ly.L + 24) * k}" height="${13 * k}" fill="#9ca3af"/><text x="${X(0)}" y="${Z(-Ly.SD / 2 + 9)}" text-anchor="middle" font-size="11" fill="#fff">🅿 парковка</text>`;
  s += `<rect x="${X(-Ly.L / 2)}" y="${Z(Ly.z0 - Ly.Wd / 2)}" width="${Ly.L * k}" height="${Ly.Wd * k}" fill="#e7dccb" stroke="#475569" stroke-width="2"/>`;
  for (let i = 0; i < b.sections; i++) { const x = -Ly.L / 2 + sw * (i + .5), on = i + 1 === a.section; if (i) s += `<line x1="${X(x - sw / 2)}" y1="${Z(Ly.z0 - Ly.Wd / 2)}" x2="${X(x - sw / 2)}" y2="${Z(zs)}" stroke="#94a3b8"/>`;
    s += `<rect x="${X(x) - 9}" y="${Z(zs) - 4}" width="18" height="10" rx="2" fill="${on ? '#1d4ed8' : '#64748b'}"/><text x="${X(x)}" y="${Z(zs) + 4}" text-anchor="middle" font-size="9" font-weight="700" fill="#fff">${i + 1}</text>`; }
  s += `<text x="${X(0)}" y="${Z(Ly.z0) + 4}" text-anchor="middle" font-size="12" fill="#475569">жилой дом</text><text x="${X(0)}" y="${Z(Ly.SD / 2 - 8)}" text-anchor="middle" font-size="12" fill="#15803d">двор · детская площадка · сквер</text>`;
  const pts = [[gx, -Ly.SD / 2 - 3], [gx, wz0], [wx, wz0], [wx, wz1], [xc, wz1], [xc, zs + .5]].map(([x, z]) => `${X(x).toFixed(1)},${Z(z).toFixed(1)}`).join(' ');
  s += `<polyline points="${pts}" fill="none" stroke="#0ea5e9" stroke-width="5" stroke-linejoin="round" stroke-linecap="round" class="route-anim"/>`;
  s += `<circle cx="${X(gx)}" cy="${Z(-Ly.SD / 2 - 3)}" r="7" fill="#0f766e"/><text x="${X(gx) + 10}" y="${Z(-Ly.SD / 2) + 12}" font-size="11" font-weight="700" fill="#0f766e">калитка</text>`;
  s += `<circle cx="${X(xc)}" cy="${Z(zs + .5)}" r="8" fill="#ea580c"/><text x="${X(xc)}" y="${Z(zs) + 26}" text-anchor="middle" font-size="12" font-weight="700" fill="#c2410c">вход, подъезд ${a.section}</text>`;
  s += `<g transform="translate(${W - 30},${H - 30})"><circle r="14" fill="#fff" stroke="#94a3b8"/><path d="M0 -10 L4 4 L0 0 L-4 4Z" fill="#dc2626"/><text y="-17" text-anchor="middle" font-size="9" fill="#dc2626">С</text></g></svg>`;
  return s;
}
/* план этажа с маршрутом от лифта до двери */
function aptFloorRouteSvg(p, a, Rt) {
  const A = genApts(p).filter(x => x.section === a.section && x.floor === a.floor), W = 620, H = 340, x0 = 60, y0 = 46, w = W - 120, h = H - 104, k = w / Rt.sw;
  const corrH = h * 0.18, rowH = (h - corrH) / 2, cy = y0 + rowH + corrH / 2, cx = x0 + w / 2, coreW = Rt.coreW * k;
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><rect width="${W}" height="${H}" fill="#f8fafc"/>`;
  s += `<text x="${W / 2}" y="22" text-anchor="middle" font-size="12" fill="#475569">▲ улица (север)</text><text x="${W / 2}" y="${H - 10}" text-anchor="middle" font-size="12" fill="#15803d">▼ двор (юг)</text>`;
  s += `<text x="12" y="${cy + 4}" font-size="11" fill="#94a3b8">◀ запад</text><text x="${W - 12}" y="${cy + 4}" text-anchor="end" font-size="11" fill="#94a3b8">восток ▶</text>`;
  s += `<rect x="${x0}" y="${y0}" width="${w}" height="${h}" fill="#fff" stroke="#334155" stroke-width="3"/><rect x="${x0}" y="${y0 + rowH}" width="${w}" height="${corrH}" fill="#eef2f7"/>`;
  const box = (ap, i, n, south) => {
    const cw = w / n, x = x0 + i * cw, y = south ? y0 + rowH + corrH : y0, sel = ap.id === a.id;
    s += `<rect x="${x + 2}" y="${y + 2}" width="${cw - 4}" height="${rowH - 4}" fill="${sel ? '#fed7aa' : '#f1f5f9'}" stroke="${sel ? '#ea580c' : '#94a3b8'}" stroke-width="${sel ? 4 : 1.2}"/>`;
    s += `<text x="${x + cw / 2}" y="${y + rowH / 2 + (south ? 10 : -2)}" text-anchor="middle" font-size="${sel ? 15 : 12}" font-weight="700" fill="${sel ? '#9a3412' : '#64748b'}">№${ap.num}</text>`;
    const dy = south ? y + 2 : y + rowH - 2; s += `<rect x="${x + cw / 2 - 9}" y="${dy - 3}" width="18" height="6" fill="${sel ? '#ea580c' : '#a16207'}"/>`;
  };
  A.filter(x => x.idx < Rt.cols).forEach((ap, i) => box(ap, i, Rt.cols, true)); A.filter(x => x.idx >= Rt.cols).forEach((ap, i) => box(ap, i, Rt.ncols, false));
  s += `<rect x="${cx - coreW / 2}" y="${y0 + rowH - 14}" width="${coreW}" height="${corrH + 2}" fill="#cbd5e1" stroke="#475569" stroke-width="1.5"/><text x="${cx}" y="${y0 + rowH + 2}" text-anchor="middle" font-size="11" font-weight="700" fill="#1e293b">🛗 лифт</text>`;
  const dx = cx + Rt.me.dx * k, dy = Rt.me.south ? y0 + rowH + corrH + 2 : y0 + rowH - 2;
  s += `<polyline points="${cx},${y0 + rowH + 6} ${cx},${cy} ${dx},${cy} ${dx},${dy}" fill="none" stroke="#0ea5e9" stroke-width="5" stroke-linejoin="round" stroke-linecap="round" class="route-anim"/>`;
  s += `<circle cx="${cx}" cy="${cy}" r="7" fill="#7c3aed"/><circle cx="${dx}" cy="${dy}" r="8" fill="#ea580c"/>`;
  if (!Rt.straight) s += `<text x="${(cx + dx) / 2}" y="${cy - 6}" text-anchor="middle" font-size="13" font-weight="800" fill="#0369a1">${Rt.turn === 'right' ? '↪ направо' : '↩ налево'} · ${Rt.dist} м</text>`;
  s += `<text x="${cx}" y="${cy + 22}" text-anchor="middle" font-size="10.5" fill="#475569">выход из лифта — лицом во двор</text></svg>`;
  return s;
}
function aptRouteHtml(p, a) {
  if (!a.rooms) return `<div class="card"><p class="muted">Коммерческое помещение — вход с улицы, отдельный, на первом этаже подъезда ${a.section}.</p></div>`;
  const Rt = aptRoute(p, a);
  return `<div class="grid apt-house"><div class="site-viewer" id="aptSite"><div class="v-tools" id="s3Tools"></div><div class="v-help">Голубая линия — путь от калитки до двери квартиры</div></div>
    <div class="card" style="margin:0"><h3>🚶 Как пройти к квартире №${a.num}</h3><ol class="route-steps">${Rt.steps.map(([ic, t, d], i) => `<li><span class="rs-ic">${ic}</span><div><b>${i + 1}. ${t}</b><small>${d}</small></div></li>`).join('')}</ol>
    <div class="flex mt"><button class="btn-s" data-act="aptRouteCopy">📋 Скопировать для клиента</button></div></div></div>
    <div class="grid g2 mt"><div class="card"><h3>Схема участка: от калитки до подъезда ${a.section}</h3>${aptSiteRouteSvg(p, a)}</div>
    <div class="card"><h3>${a.floor} этаж: от лифта до двери</h3>${aptFloorRouteSvg(p, a, Rt)}</div></div>`;
}
ACT.aptRouteCopy = () => {
  const p = P(), a = genApts(p).find(x => x.id === pageArgs.id), Rt = aptRoute(p, a);
  const txt = `${p.name}\n${T('Квартира')} №${a.num}, ${a.rooms}-${T('комн.')}, ${a.area} м², ${a.floor} ${T('этаж')}, ${T('подъезд')} ${a.section}\n\n` + Rt.steps.map(([ic, t, d], i) => `${i + 1}. ${ic} ${T(t)} — ${T(d)}`).join('\n');
  (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(() => toast('Маршрут скопирован — отправьте клиенту в WhatsApp или Telegram'), () => prompt('', txt));
};
