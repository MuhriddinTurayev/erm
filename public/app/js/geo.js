'use strict';
/* ===== Геология грунта и подготовительные работы ===== */
const SOILS = {
  fill: { name: 'Насыпной грунт', color: '#9c8f7a', rho: 1.7, phi: 20, c: 5, E: 7, R0: 100, slope: 1.0, loose: 1.15 },
  sandF: { name: 'Песок мелкий', color: '#e9d48f', rho: 1.75, phi: 30, c: 2, E: 20, R0: 200, slope: 1.0, loose: 1.12 },
  sandM: { name: 'Песок средней крупности', color: '#dfc274', rho: 1.8, phi: 35, c: 1, E: 30, R0: 350, slope: 1.0, loose: 1.12 },
  sandyLoam: { name: 'Супесь', color: '#cfb98c', rho: 1.85, phi: 26, c: 10, E: 15, R0: 220, slope: 0.67, loose: 1.2 },
  loam: { name: 'Суглинок', color: '#b98f63', rho: 1.9, phi: 22, c: 25, E: 18, R0: 250, slope: 0.5, loose: 1.25 },
  loess: { name: 'Лёссовидный суглинок (просадочный)', color: '#d8b57f', rho: 1.6, phi: 22, c: 15, E: 10, R0: 150, slope: 0.5, loose: 1.22, collapse: true },
  clay: { name: 'Глина', color: '#a0715a', rho: 1.95, phi: 18, c: 40, E: 20, R0: 300, slope: 0.25, loose: 1.3 },
  gravel: { name: 'Галечниковый грунт', color: '#9aa4ad', rho: 2.05, phi: 38, c: 0, E: 45, R0: 500, slope: 1.0, loose: 1.15 },
  rock: { name: 'Скальный грунт', color: '#6b7280', rho: 2.5, phi: 45, c: 200, E: 1000, R0: 1500, slope: 0.1, loose: 1.45 },
};
const SOIL_OPTS = Object.entries(SOILS).map(([k, s]) => [k, s.name]);
const GEO_PRICE_DEF = { drill: 450000, sample: 350000, sounding: 2500000, report: 15000000 };
function geoDefaults() {
  return {
    gwl: 9, seismic: 8, addDepth: 15, wellsExtra: 0, sLimit: 15, prices: {},
    layers: [{ type: 'fill', h: 1.2 }, { type: 'loess', h: 5.3 }, { type: 'loam', h: 4.5 }, { type: 'gravel', h: 14 }],
  };
}
const layerProps = l => ({ ...SOILS[l.type] || SOILS.loam, ...(l.E != null ? { E: l.E } : {}), ...(l.R0 != null ? { R0: l.R0 } : {}) });

function geoCalc(p) {
  const g = p.geo, b = p.b, B = bld(p), d = p.pit.depth, pr = { ...GEO_PRICE_DEF, ...g.prices };
  let top = 0; const layers = g.layers.map((l, i) => { const L = { ...l, i, top, bottom: top + l.h, P: layerProps(l) }; top += l.h; return L; });
  const total = top;
  const at = z => layers.find(l => z >= l.top && z < l.bottom) || layers[layers.length - 1];
  const base = at(d + 0.01);
  // преобладающий грунт в пределах котлована
  let dom = layers[0], best = -1;
  layers.forEach(l => { const th = Math.max(0, Math.min(l.bottom, d) - l.top); if (th > best) { best = th; dom = l; } });
  // средний удельный вес грунта выше подошвы
  let w = 0; layers.forEach(l => { w += Math.max(0, Math.min(l.bottom, d) - l.top) * l.P.rho * 9.81; });
  const sigmaZg = w;
  const pGross = (b.floors + b.underground) * 13 + p.m.slabThk * 25;            // кПа
  const k2 = /sand|gravel/.test(base?.type) ? 2.5 : 2.0;
  const R = base ? base.P.R0 + k2 * base.P.rho * 9.81 * Math.max(0, d - 2) : 0;
  const util = R ? pGross / R * 100 : 0;
  const p0 = Math.max(0, pGross - sigmaZg);
  const settle = base ? 0.8 * p0 * Math.min(b.width, 10) / (base.P.E * 1000) * 100 : 0;  // см
  const wells = Math.max(3, Math.ceil(B.fp / 350)) + (g.wellsExtra || 0);
  const wellDepth = d + g.addDepth;
  const meters = wells * wellDepth, samples = wells * Math.ceil(wellDepth / 3);
  const cost = meters * pr.drill + samples * pr.sample + wells * pr.sounding + pr.report;
  const dewater = g.gwl <= d + 0.5;
  const pitPerim = 2 * (p.pit.length + p.pit.width);
  const notes = [];
  if (!base) notes.push(['bad', 'Не заданы слои грунта']);
  else {
    notes.push(['ok', `Основание фундамента (отметка −${fmt(d, 1)} м): <b>${base.P.name}</b>, R ≈ ${fmt(R)} кПа, E = ${fmt(base.P.E)} МПа`]);
    const strong = layers.filter(l => l.top >= d).sort((a, b) => b.P.R0 - a.P.R0)[0];
    if (g.piles) notes.push(['ok', `Принят <b>свайный фундамент</b>: буронабивные сваи передают нагрузку на слой «${strong ? strong.P.name : '—'}»`]);
    else if (util > 100) notes.push(['bad', `Давление под плитой ${fmt(pGross)} кПа превышает расчётное сопротивление ${fmt(R)} кПа (${fmt(util)}%) — нужен <b>свайный фундамент</b> или усиление основания`, 'piles']);
    else if (util > 85) notes.push(['warn', `Давление ${fmt(pGross)} кПа = ${fmt(util)}% от R — близко к пределу, рекомендуется проверка расчётом и, возможно, сваи`]);
    else notes.push(['ok', `Несущая способность достаточна: давление ${fmt(pGross)} кПа = ${fmt(util)}% от R`]);
    if (!g.piles && settle > g.sLimit) notes.push(['bad', `Ожидаемая осадка ≈ ${fmt(settle, 1)} см больше допустимой ${g.sLimit} см — нужны сваи или уплотнение грунта`, 'piles']);
    else if (!g.piles) notes.push(['ok', `Ожидаемая осадка ≈ ${fmt(settle, 1)} см (допустимо ${g.sLimit} см)`]);
    if (base.P.collapse || layers.some(l => l.P.collapse && l.top < d + 5 && l.bottom > d)) notes.push(g.collapseFix || g.piles
      ? ['ok', `Просадочность устраняется: ${g.piles ? 'сваи прорезают лёссовый слой' : 'уплотнённая грунтовая подушка под плитой (в подготовительных работах)'}`]
      : ['warn', 'В основании <b>просадочный лёссовый грунт</b>: требуется устранение просадочности — грунтовая подушка, уплотнение тяжёлыми трамбовками, предварительное замачивание или прорезка сваями', 'collapse']);
    if (base.type === 'rock') notes.push(['warn', 'Скальный грунт: разработка с рыхлением (гидромолот), производительность экскаватора ниже в 2–3 раза']);
  }
  const dwDone = (p.prep.dewater || {}).status === 'done';
  notes.push(dewater ? (dwDone ? ['ok', 'Водопонижение выполнено'] : ['warn', `Грунтовые воды на глубине ${fmt(g.gwl, 1)} м — выше дна котлована. Нужно <b>водопонижение</b> (добавлено в подготовительные работы) и гидроизоляция`]) : ['ok', `Грунтовые воды на ${fmt(g.gwl - d, 1)} м ниже дна котлована — водопонижение не требуется`]);
  if (g.seismic >= 7) notes.push(g.seismicFix ? ['ok', `Сейсмичность ${g.seismic} баллов: антисейсмические мероприятия учтены, армирование увеличено на 12%`] : ['warn', `Сейсмичность ${g.seismic} баллов: антисейсмические пояса, усиленные узлы, армирование больше на 10–15%`, 'seismic']);
  notes.push(['ok', `Рекомендуемый откос котлована для грунта «${dom.P.name}»: 1:${dom.P.slope}, коэф. разрыхления ${dom.P.loose}`]);
  return { layers, total, base, dom, R, pGross, p0, util, settle, sigmaZg, wells, wellDepth, meters, samples, cost, pr, dewater, pitPerim, notes };
}

function geoSvg(p, G) {
  const W = 660, H = 400, top = 70, maxD = Math.max(G.total, G.wellDepth, p.geo.gwl + 2), sc = (H - top - 20) / maxD, d = p.pit.depth;
  const x0 = 50, x1 = W - 10, bx0 = 135, bx1 = 315;
  let s = `<svg viewBox="0 0 ${W} ${H}" class="bsvg"><defs><pattern id="dots" width="8" height="8" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1" fill="#0002"/></pattern></defs>`;
  s += `<rect x="0" y="0" width="${W}" height="${top}" fill="#eaf2fb"/>`;
  G.layers.forEach(l => { const y = top + l.top * sc, h = Math.min(l.h, maxD - l.top) * sc; s += `<rect x="${x0}" y="${y}" width="${x1 - x0}" height="${h}" fill="${l.P.color}"/><rect x="${x0}" y="${y}" width="${x1 - x0}" height="${h}" fill="url(#dots)"/>`; if (h > 13) s += `<text x="${bx1 + 75}" y="${y + Math.min(h / 2 + 4, h - 3)}" font-size="11.5" fill="#222" font-weight="600">${esc(l.P.name)} · ${fmt(l.h, 1)} м</text>`; s += `<line x1="${x0}" y1="${y}" x2="${x1}" y2="${y}" stroke="#5a4a36" stroke-width=".8"/>`; });
  // котлован и здание
  const m = p.pit.slope, yb = top + d * sc, ext = m * d * sc;
  s += `<polygon points="${bx0 - ext},${top} ${bx1 + ext},${top} ${bx1},${yb} ${bx0},${yb}" fill="#f7f4ee" stroke="#8a6d45" stroke-width="1.5"/>`;
  s += `<rect x="${bx0}" y="${yb - Math.max(4, p.m.slabThk * sc)}" width="${bx1 - bx0}" height="${Math.max(4, p.m.slabThk * sc)}" fill="#7d8692"/>`;
  s += `<rect x="${bx0 + 10}" y="${top - 52}" width="${bx1 - bx0 - 20}" height="${yb - top + 52 - Math.max(4, p.m.slabThk * sc)}" fill="#cfd8e3" opacity=".55" stroke="#64748b"/>`;
  s += `<text x="${(bx0 + bx1) / 2}" y="${top - 30}" text-anchor="middle" font-size="12" fill="#334155">Здание ${p.b.floors} эт.</text>`;
  // скважины
  [x0 + 22, bx1 + 50, x1 - 18].forEach(x => { s += `<line x1="${x}" y1="${top}" x2="${x}" y2="${top + G.wellDepth * sc}" stroke="#1e293b" stroke-width="2.5" stroke-dasharray="6 3"/><circle cx="${x}" cy="${top}" r="4" fill="#1e293b"/>`; });
  s += `<text x="${x0 + 22}" y="${top - 8}" text-anchor="middle" font-size="11" fill="#1e293b">скв.</text>`;
  // вода
  const yw = top + p.geo.gwl * sc;
  if (p.geo.gwl < maxD) s += `<rect x="${x0}" y="${yw}" width="${x1 - x0}" height="${H - 20 - yw}" fill="#3b82f6" opacity=".16"/><line x1="${x0}" y1="${yw}" x2="${x1}" y2="${yw}" stroke="#2563eb" stroke-width="2" stroke-dasharray="8 4"/><text x="${x1 - 6}" y="${yw - 5}" text-anchor="end" font-size="12" fill="#1d4ed8" font-weight="700">▽ УГВ −${fmt(p.geo.gwl, 1)} м</text>`;
  // шкала
  for (let z = 0; z <= maxD; z += maxD > 30 ? 5 : 2) { const y = top + z * sc; s += `<line x1="${x0 - 6}" y1="${y}" x2="${x0}" y2="${y}" stroke="#555"/><text x="${x0 - 9}" y="${y + 4}" text-anchor="end" font-size="10.5" fill="#555">−${z}</text>`; }
  s += `<line x1="${x0}" y1="${top}" x2="${x1}" y2="${top}" stroke="#3f6212" stroke-width="3"/><text x="${x0 - 9}" y="${top - 6}" text-anchor="end" font-size="10.5" fill="#555">м</text>`;
  s += `<text x="${(bx0 + bx1) / 2}" y="${yb + 15}" text-anchor="middle" font-size="11" fill="#7c2d12" font-weight="600">подошва −${fmt(d, 1)} м</text></svg>`;
  return s;
}

PAGES.geology = {
  title: 'Инженерная геология грунта',
  fullOnChange: true,
  render(p) {
    const G = geoCalc(p);
    return `
    <div class="note">Внесите слои грунта по данным изысканий (или примерные) — система построит геологический разрез, проверит несущую способность и осадку, учтёт грунтовые воды и сейсмику, посчитает объём и стоимость изысканий и подскажет откос котлована.</div>
    <div class="card"><h3>Условия площадки</h3><div class="form">
      ${field('geo.gwl', 'Уровень грунтовых вод (УГВ)', { unit: 'м от поверхности' })}
      ${field('geo.seismic', 'Сейсмичность района', { unit: 'баллов' })}
      ${field('geo.addDepth', 'Глубина скважин ниже подошвы', { unit: 'м' })}
      ${field('geo.wellsExtra', 'Дополнительные скважины', { unit: 'шт' })}
      ${field('geo.sLimit', 'Допустимая осадка', { unit: 'см' })}
      ${field('pit.depth', 'Глубина заложения (дно котлована)', { unit: 'м' })}
    </div></div>
    <div class="grid g2">
      <div class="card"><h3>Геологический разрез</h3>${geoSvg(p, G)}</div>
      <div class="card"><h3>Слои грунта (сверху вниз) <span class="tools"><button class="btn-s" data-act="layerAdd">+ Слой</button></span></h3>
        <div class="tbl-wrap"><table><thead><tr><th>Грунт</th><th class="n">Мощность, м</th><th class="n">Глубина</th><th class="n">E, МПа</th><th class="n">R₀, кПа</th><th class="n">φ°</th><th class="n">c, кПа</th><th></th></tr></thead><tbody>
        ${G.layers.map(l => `<tr><td style="min-width:200px"><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${l.P.color};vertical-align:-1px"></span> ${cellSel(`geo.layers.${l.i}.type`, l.type, SOIL_OPTS).replace('class="cell"', 'class="cell" style="width:calc(100% - 20px)"')}${l.P.collapse ? '<div class="small bad">просадочный</div>' : ''}</td>
          <td class="n" style="width:90px">${cell(`geo.layers.${l.i}.h`, l.h)}</td><td class="n small muted">${fmt(l.top, 1)}–${fmt(l.bottom, 1)}</td>
          <td class="n" style="width:80px">${cell(`geo.layers.${l.i}.E`, l.P.E)}</td><td class="n" style="width:90px">${cell(`geo.layers.${l.i}.R0`, l.P.R0)}</td>
          <td class="n">${l.P.phi}</td><td class="n">${l.P.c}</td><td><button class="x" data-act="layerDel" data-i="${l.i}">✕</button></td></tr>`).join('')}
        </tbody></table></div>
        <p class="small muted">E — модуль деформации, R₀ — расчётное сопротивление, φ — угол трения, c — сцепление. При смене типа грунта значения подставляются из справочника, их можно уточнить по отчёту изысканий.</p>
      </div>
    </div>
    <div class="card res"><h3>Выводы по основанию <span class="tools"><button class="btn-s btn-p" data-act="geoToPit">Применить откос и разрыхление к котловану</button></span></h3>
      <div class="big-res mb">
        ${rbox('Давление под плитой', fmt(G.pGross) + ' кПа', 1)}${rbox('Расчётное сопротивление R', fmt(G.R) + ' кПа', 1)}
        ${rbox('Использование', fmt(G.util) + ' %')}${rbox('Ожидаемая осадка', fmt(G.settle, 1) + ' см')}
        ${rbox('Бытовое давление грунта', fmt(G.sigmaZg) + ' кПа')}${rbox('Грунт в котловане', esc(G.dom.P.name))}
      </div>
      ${G.notes.map(([k, t]) => `<div class="${k === 'ok' ? 'note' : k === 'warn' ? 'warnbox' : 'warnbox'}" style="margin-bottom:8px${k === 'bad' ? ';background:#fee2e2;border-color:#fca5a5;color:#7f1d1d' : ''}">${k === 'ok' ? '✅' : k === 'warn' ? '⚠️' : '❌'} ${t}</div>`).join('')}
      <p class="small muted">Расчёт оценочный (по упрощённым формулам СП «Основания зданий и сооружений»). Окончательное решение по фундаменту — по отчёту инженерно-геологических изысканий и расчёту конструктора.</p>
    </div>
    <div class="card"><h3>Инженерно-геологические изыскания: объём и стоимость</h3>
      <div class="tbl-wrap"><table><thead><tr><th>Работа</th><th class="n">Кол-во</th><th>Ед.</th><th class="n">Цена, ${cur()}</th><th class="n">Сумма</th></tr></thead><tbody>
        <tr><td>Бурение скважин (${G.wells} скв. × ${fmt(G.wellDepth, 1)} м)</td><td class="n">${fmt(G.meters, 1)}</td><td>м</td><td class="n" style="width:150px">${cell('geo.prices.drill', G.pr.drill)}</td><td class="n">${money(G.meters * G.pr.drill)}</td></tr>
        <tr><td>Отбор проб и лабораторные испытания грунта</td><td class="n">${G.samples}</td><td>проб</td><td class="n">${cell('geo.prices.sample', G.pr.sample)}</td><td class="n">${money(G.samples * G.pr.sample)}</td></tr>
        <tr><td>Статическое зондирование</td><td class="n">${G.wells}</td><td>точек</td><td class="n">${cell('geo.prices.sounding', G.pr.sounding)}</td><td class="n">${money(G.wells * G.pr.sounding)}</td></tr>
        <tr><td>Технический отчёт об изысканиях</td><td class="n">1</td><td>компл</td><td class="n">${cell('geo.prices.report', G.pr.report)}</td><td class="n">${money(G.pr.report)}</td></tr>
        <tr class="tot"><td colspan="4">Итого изыскания (входит в подготовительные работы)</td><td class="n">${money(G.cost)}</td></tr>
      </tbody></table></div>
      <p class="small muted">Скважин: 1 на ~350 м² пятна застройки, но не меньше 3. Глубина: до подошвы фундамента + ${p.geo.addDepth} м.</p>
    </div>`;
  },
  onChange(el) {
    const m = /^geo\.layers\.(\d+)\.type$/.exec(el.dataset.bind || '');
    if (m) { const l = P().geo.layers[+m[1]]; delete l.E; delete l.R0; save(); rerender(); }
  }
};
ACT.layerAdd = () => { P().geo.layers.push({ type: 'loam', h: 3 }); save(); rerender(); };
ACT.layerDel = d => { const g = P().geo; if (g.layers.length < 2) return toast('Нужен хотя бы один слой'); g.layers.splice(+d.i, 1); save(); rerender(); };
ACT.geoToPit = () => { const p = P(), G = geoCalc(p); p.pit.slope = G.dom.P.slope; p.pit.looseK = G.dom.P.loose; save(); toast(`Котлован: откос 1:${G.dom.P.slope}, разрыхление ${G.dom.P.loose}`); rerender(); };

/* ---------- Подготовительные работы ---------- */
const PREP_STATUS = [['todo', 'Не начато'], ['work', 'В работе'], ['done', 'Выполнено']];
const PREP_CRIT = ['permit', 'geology', 'axes', 'topsoil', 'fence', 'power', 'cabins'];
function prepDefs(p) {
  const B = bld(p), plot = p.b.plot, G = geoCalc(p), peak = labCalc(p).peak, per = 4 * Math.sqrt(plot);
  return [
    ['permit', 'Документация', 'Разрешение на строительство, ордер на земляные работы', 'компл', 1, 25000000, 30],
    ['expertise', 'Документация', 'Экспертиза проектной документации', 'компл', 1, 60000000, 45],
    ['contracts', 'Документация', 'Договоры с подрядчиками, страхование объекта', 'компл', 1, 12000000, 15],
    ['topo', 'Изыскания и геодезия', 'Топографическая съёмка участка', 'га', r1(plot / 10000) || 0.1, 9000000, 7],
    ['geology', 'Изыскания и геодезия', `Инженерно-геологические изыскания (${G.wells} скважин)`, 'компл', 1, Math.round(G.cost), 20, true],
    ['axes', 'Изыскания и геодезия', 'Разбивочная основа, вынос осей здания', 'компл', 1, 12000000, 5],
    ['demolish', 'Расчистка территории', 'Снос старых строений, вывоз мусора', 'м³', 0, 180000, 10],
    ['trees', 'Расчистка территории', 'Вырубка деревьев, корчёвка пней', 'шт', 0, 250000, 3],
    ['topsoil', 'Расчистка территории', 'Снятие плодородного слоя 0,2 м и вывоз', 'м³', Math.round(plot * 0.2), 35000, 5],
    ['grading', 'Расчистка территории', 'Вертикальная планировка площадки', 'м²', Math.round(plot), 8000, 4],
    ['fence', 'Ограждение и охрана', 'Временное ограждение из профлиста h = 2 м', 'м', Math.round(per), 450000, 7],
    ['gates', 'Ограждение и охрана', 'Въездные ворота и КПП', 'компл', 2, 15000000, 3],
    ['cctv', 'Ограждение и охрана', 'Освещение площадки и видеонаблюдение', 'компл', 1, 35000000, 5],
    ['boards', 'Ограждение и охрана', 'Паспорт объекта, стенды ТБ, пожарные щиты', 'компл', 1, 8000000, 2],
    ['roads', 'Временные дороги и площадки', 'Временные дороги (щебень / дорожные плиты)', 'м²', Math.round(plot * 0.12), 180000, 10],
    ['storage', 'Временные дороги и площадки', 'Площадки складирования материалов', 'м²', 400, 120000, 5],
    ['wash', 'Временные дороги и площадки', 'Пункт мойки колёс', 'шт', 1, 45000000, 3],
    ['power', 'Временные сети', 'Временное электроснабжение (ТП, кабель, щиты)', 'компл', 1, 120000000, 15],
    ['water', 'Временные сети', 'Временный водопровод', 'м', 150, 90000, 5],
    ['sewer', 'Временные сети', 'Временная канализация / септик', 'компл', 1, 25000000, 5],
    ['cabins', 'Бытовой городок', `Бытовки для рабочих (1 на 8 чел., пик ≈ ${peak} чел.)`, 'шт', Math.ceil(peak / 8), 28000000, 5],
    ['office', 'Бытовой городок', 'Прорабская и офис продаж', 'шт', 2, 45000000, 5],
    ['wc', 'Бытовой городок', 'Биотуалеты (1 на 15 чел.)', 'шт', Math.ceil(peak / 15), 6000000, 1],
    ['canteen', 'Бытовой городок', 'Пункт приёма пищи / столовая', 'шт', 1, 40000000, 7],
    ['medic', 'Бытовой городок', 'Медпункт и аптечки', 'компл', 1, 10000000, 2],
    ['craneBase', 'Подготовка под кран', 'Фундамент под башенный кран', 'шт', p.b.sections, 60000000, 10],
    ['craneMount', 'Подготовка под кран', 'Монтаж и регистрация башенного крана', 'шт', p.b.sections, 90000000, 5],
    ['dewater', 'Водопонижение и сети', 'Водопонижение (иглофильтры по периметру котлована)', 'м', G.dewater ? Math.round(G.pitPerim) : 0, 600000, 30],
    ['cushion', 'Подготовка основания', 'Грунтовая подушка 1,5 м с уплотнением (устранение просадочности)', 'м³', p.geo.collapseFix && !p.geo.piles ? Math.round(p.pit.length * p.pit.width * 1.5) : 0, 180000, 20],
    ['piles', 'Подготовка основания', 'Буронабивные сваи Ø620 мм, L = 12 м', 'шт', p.geo.piles ? Math.ceil(B.fp / 9) : 0, 4500000, 40],
    ['relocate', 'Водопонижение и сети', 'Вынос / перекладка существующих сетей', 'м', 0, 1500000, 15],
  ];
}
function prepCalc(p) {
  const rows = prepDefs(p).map(([key, group, name, unit, qty, price, days, auto]) => {
    const o = p.prep[key] || {}, Q = o.qty ?? qty, Pr = auto ? price : (o.price ?? price);
    return { key, group, name, unit, qty: Q, price: Pr, days: o.days ?? days, status: o.status || 'todo', resp: o.resp || '', sum: Q * Pr, auto, crit: PREP_CRIT.includes(key) };
  });
  p.prepExtra.forEach((e, i) => rows.push({ key: 'x' + i, i, group: 'Дополнительные работы', name: e.name, unit: e.unit, qty: e.qty, price: e.price, days: e.days, status: e.status || 'todo', resp: e.resp || '', sum: e.qty * e.price, extra: true }));
  const act = rows.filter(r => r.qty > 0), total = sum(act, r => r.sum);
  const done = act.filter(r => r.status === 'done'), work = act.filter(r => r.status === 'work');
  const doneCost = sum(done, r => r.sum) + sum(work, r => r.sum) * 0.5;
  const crit = rows.filter(r => r.crit), critDone = crit.filter(r => r.status === 'done');
  return { rows, total, done: done.length, work: work.length, count: act.length, pct: total ? doneCost / total * 100 : 0, crit, critDone, ready: crit.length && critDone.length === crit.length };
}
PAGES.prep = {
  title: 'Подготовка площадки к строительству',
  fullOnChange: true,
  render(p) {
    const C = prepCalc(p); let g = '';
    const groups = {}; C.rows.forEach(r => { if (r.qty > 0) groups[r.group] = (groups[r.group] || 0) + r.sum; });
    const rows = C.rows.map(r => {
      let h = ''; if (r.group !== g) { g = r.group; h += `<tr class="grp"><td colspan="8">${esc(g)}</td><td class="n">${moneyS(groups[g] || 0)}</td></tr>`; }
      const base = r.extra ? `prepExtra.${r.i}` : `prep.${r.key}`;
      return h + `<tr${r.qty > 0 ? '' : ' style="opacity:.55"'}><td style="min-width:260px">${r.extra ? cell(base + '.name', r.name, { type: 'text' }) : esc(r.name) + (r.crit ? ' <span class="pill p-booked" title="Обязательно до начала котлована">важно</span>' : '')}</td>
        <td class="n" style="width:95px">${cell(base + '.qty', r.qty)}</td><td>${r.extra ? cell(base + '.unit', r.unit, { type: 'text' }) : r.unit}</td>
        <td class="n" style="width:125px">${r.auto ? `<span title="Считается в разделе «Геология»">${fmt(r.price)}</span>` : cell(base + '.price', r.price)}</td>
        <td class="n" style="width:70px">${cell(base + '.days', r.days)}</td>
        <td style="width:130px">${cellSel(base + '.status', r.status, PREP_STATUS)}</td>
        <td style="min-width:130px">${cell(base + '.resp', r.resp, { type: 'text', attr: 'list="prepResp"' })}</td>
        <td>${r.status === 'done' ? '✅' : r.status === 'work' ? '🔄' : '⏳'}</td>
        <td class="n">${money(r.sum)}${r.extra ? ` <button class="x" data-act="prepDel" data-i="${r.i}">✕</button>` : ''}</td></tr>`;
    }).join('');
    return `
    <div class="kpis">
      ${kpi('Стоимость подготовки', moneyS(C.total), C.count + ' видов работ', 'ac')}
      ${kpi('Выполнено', `${C.done} из ${C.count}`, 'в работе ' + C.work, 'ok')}
      ${kpi('Освоено', fmt(C.pct, 1) + '%', progBar(C.pct), 'bl')}
      ${kpi('Готовность к началу стройки', C.ready ? '✅ Готово' : `${C.critDone.length} из ${C.crit.length}`, C.ready ? 'можно начинать котлован' : 'обязательных работ выполнено', C.ready ? 'ok' : 'bad')}
    </div>
    ${C.ready ? '' : `<div class="warnbox">⚠️ До начала котлована нужно выполнить: ${C.crit.filter(r => r.status !== 'done').map(r => '<b>' + esc(r.name) + '</b>').join(', ')}.</div>`}
    <div class="card"><h3>Все работы до начала строительства <span class="tools"><button class="btn-s" data-act="prepAdd">+ Своя работа</button><button class="btn-s" data-act="prepAllDone">Отметить всё выполненным</button><button class="btn-s" data-act="prepCsv">⬇ Excel (CSV)</button></span></h3>
      <div class="tbl-wrap"><table><thead><tr><th>Работа</th><th class="n">Объём</th><th>Ед.</th><th class="n">Цена, ${cur()}</th><th class="n">Дней</th><th>Статус</th><th>Ответственный</th><th></th><th class="n">Сумма</th></tr></thead>
      <tbody>${rows}<tr class="tot"><td colspan="8">ИТОГО подготовительные работы</td><td class="n">${money(C.total)}</td></tr></tbody></table></div>
      <datalist id="prepResp">${p.staff.filter(s => ITR_ROLES.has(s.role)).map(s => `<option value="${esc(s.name)}">`).join('')}</datalist>
      <p class="small muted">Объёмы считаются автоматически от площади участка, численности рабочих и данных геологии — их можно поправить. Сумма входит в смету отдельной статьёй «Подготовительные работы».</p>
    </div>`;
  }
};
ACT.prepAdd = () => { P().prepExtra.push({ name: 'Новая работа', unit: 'компл', qty: 1, price: 0, days: 5, status: 'todo' }); save(); rerender(); };
ACT.prepDel = d => { P().prepExtra.splice(+d.i, 1); save(); rerender(); };
ACT.prepAllDone = async () => { if (!await ui.confirm('Отметить все подготовительные работы как выполненные?')) return; const p = P(); prepDefs(p).forEach(([k]) => { (p.prep[k] = p.prep[k] || {}).status = 'done'; }); p.prepExtra.forEach(e => e.status = 'done'); save(); rerender(); };
ACT.prepCsv = () => { const C = prepCalc(P()); download('podgotovka.csv', csv([['Раздел', 'Работа', 'Объём', 'Ед.', 'Цена', 'Сумма', 'Дней', 'Статус', 'Ответственный'], ...C.rows.map(r => [r.group, r.name, r.qty, r.unit, r.price, Math.round(r.sum), r.days, Object.fromEntries(PREP_STATUS)[r.status], r.resp])]), 'text/csv'); };
