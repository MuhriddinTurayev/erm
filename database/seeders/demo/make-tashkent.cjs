// Объекты «Navruz Qurilish» в Ташкенте по данным OpenStreetMap (© участники OSM, ODbL): настоящие кварталы,
// махалли с плоскими крышами, панельные дома, улицы с деревьями и машинами. Наш дом — на свободном участке рядом.
// Два состояния (сейчас / готово) и варианты материалов «day» / «night» (KHR_materials_variants).
// Запуск: node database/seeders/demo/make-tashkent.cjs → tsh-<site>-current.glb, tsh-<site>-finished.glb
const fs = require('fs'), path = require('path');
const { pack, png, rnd, setSeed, clamp, facade, Scene, area, inPoly, centroid } = require('./city-lib.cjs');
const DIR = __dirname;

const SITES = {
  tower: { lat: 41.3008, lon: 69.2768, name: 'Navruz Tower', floors: 16, sections: 1, L: 42, W: 22, done: 11, cranes: 1 },
  park: { lat: 41.3445, lon: 69.2862, name: 'Navruz Park', floors: 12, sections: 2, L: 56, W: 17, done: 7, cranes: 1 },
  city: { lat: 41.3300, lon: 69.3462, name: 'Navruz City', floors: 9, sections: 3, L: 72, W: 16, done: 6, cranes: 2 },
};

/* ---------------- текстуры (тайл: 8 пролётов × 8 этажей) ---------------- */
const T3 = { cols: 8, rows: 8, win: 3.0, floor: 3.0 };    // панельные и наш дом: пролёт 3 м, этаж 3 м
const TH = { cols: 8, rows: 8, win: 4.0, floor: 4.0 };    // частные дома махалли
function tex(kind) {
  const W = 256, H = 256, cw = W / 8, ch = H / 8;
  const lit = []; for (let i = 0; i < 64; i++) lit.push(rnd());
  const bal = []; for (let i = 0; i < 64; i++) bal.push(rnd());
  return png(W, H, (x, y) => {
    const cx = Math.floor(x / cw), cy = Math.floor(y / ch), fx = (x % cw) / cw, fy = (y % ch) / ch, k = cy * 8 + cx, l = lit[k];
    if (kind === 'panel' || kind === 'panelNight') {   // советская панель: швы, окна, застеклённые лоджии через пролёт
      const loggia = cx % 2 === 0, seam = fx < 0.03 || fy < 0.03;
      const win = loggia ? (fy > 0.12 && fy < 0.62 && fx > 0.08 && fx < 0.92) : (fx > 0.3 && fx < 0.72 && fy > 0.22 && fy < 0.68);
      if (kind === 'panelNight') { if (!win || l < 0.62) return [0, 0, 0]; const q = 0.5 + (l - 0.62) * 1.3; return l > 0.93 ? [clamp(170 * q), clamp(200 * q), clamp(255 * q)] : [clamp(255 * q), clamp(185 * q), clamp(110 * q)]; }
      if (seam) return [150, 146, 138];
      if (win) return loggia && bal[k] < 0.5 ? [150, 160, 168] : [62, 74, 88];
      if (loggia && fy >= 0.62) { const n = (bal[k] - 0.5) * 40; return [clamp(208 + n), clamp(196 + n), clamp(176 + n)]; }   // ограждение лоджий разного цвета
      const n = (l - 0.5) * 10; return [clamp(222 + n), clamp(216 + n), clamp(204 + n)];
    }
    if (kind === 'house' || kind === 'houseNight') {   // одноэтажный дом: штукатурка, редкие окна, цоколь
      const win = cx % 2 === 1 && fx > 0.2 && fx < 0.8 && fy > 0.3 && fy < 0.7;
      if (kind === 'houseNight') return win && l > 0.55 ? [255, 196, 120] : [0, 0, 0];
      if (fy > 0.86) return [120, 108, 92]; if (win) return [70, 78, 84];
      const n = (rnd() - 0.5) * 10; return [clamp(232 + n), clamp(222 + n), clamp(200 + n)];
    }
    if (kind === 'resid' || kind === 'residNight') {   // наш дом: светлый камень, окна, через пролёт — балкон со стеклянным ограждением, тёплые вставки
      const win = fx > 0.22 && fx < 0.86 && fy > 0.14 && fy < 0.7, balc = cx % 2 === 0 && fy > 0.5 && fy < 0.74 && fx > 0.1 && fx < 0.98;
      if (kind === 'residNight') { if (!win || l < 0.6) return [0, 0, 0]; const q = 0.5 + (l - 0.6) * 1.1; return l > 0.92 ? [clamp(200 * q), clamp(220 * q), clamp(255 * q)] : [clamp(255 * q), clamp(196 * q), clamp(130 * q)]; }
      if (balc) return [176, 196, 204];
      if (win) { const g = 0.75 + 0.25 * (1 - fy) + (l - 0.5) * 0.2; return [clamp(92 * g), clamp(116 * g), clamp(136 * g)]; }
      if (fy > 0.9) return [200, 190, 172];
      if (cx % 4 === 3) return [190, 140, 100];   // вертикальные вставки цвета терракоты
      const n = (rnd() - 0.5) * 8; return [clamp(238 + n), clamp(232 + n), clamp(218 + n)];
    }
    if (kind === 'shop' || kind === 'shopNight') {   // витрины первого этажа
      const frame = fx < 0.05 || fy < 0.06 || fy > 0.9;
      if (kind === 'shopNight') return frame ? [0, 0, 0] : [255, 226, 180];
      return frame ? [60, 60, 64] : [120, 150, 170];
    }
    if (kind === 'paving') { const tile = (x % 32 < 2) || (y % 16 < 2); const n = (rnd() - 0.5) * 12; return tile ? [150, 140, 128] : [clamp(196 + n), clamp(186 + n), clamp(170 + n)]; }
    if (kind === 'dirt') { const n = (rnd() - 0.5) * 30; return [clamp(150 + n), clamp(128 + n), clamp(98 + n)]; }
    return [255, 255, 255];
  });
}

/* ---------------- данные OSM ---------------- */
function load(key) {
  const C = SITES[key], osm = require('./tsh-' + key + '-osm.json');
  const KX = Math.cos(C.lat * Math.PI / 180) * 111320, KZ = 110540;
  const nodes = new Map(), ways = new Map(), rels = [];
  for (const e of osm.elements) { if (e.type === 'node') nodes.set(e.id, [(e.lon - C.lon) * KX, -(e.lat - C.lat) * KZ]); else if (e.type === 'way') ways.set(e.id, e); else if (e.type === 'relation' && e.tags) rels.push(e); }
  const ringOf = w => { const r = (w.nodes || []).map(id => nodes.get(id)).filter(Boolean); if (r.length > 2 && r[0][0] === r[r.length - 1][0] && r[0][1] === r[r.length - 1][1]) r.pop(); return r; };
  const lineOf = w => (w.nodes || []).map(id => nodes.get(id)).filter(Boolean);
  const num = v => { const f = parseFloat(String(v || '').replace(',', '.')); return isFinite(f) ? f : 0; };
  const hash = id => { let h = id % 2147483647; for (let i = 0; i < 3; i++) h = (h * 16807) % 2147483647; return h / 2147483647; };

  // высота: по тегам, иначе по площади и вытянутости контура (махалля — 1–2 этажа, «панельки» — 4/5/9)
  const solids = [];
  const add = (ring, t, id) => {
    if (ring.length < 3) return; const A = Math.abs(area(ring)); if (A < 12) return;
    let P = 0; for (let i = 0; i < ring.length; i++) { const a = ring[i], b = ring[(i + 1) % ring.length]; P += Math.hypot(b[0] - a[0], b[1] - a[1]); }
    const elong = P * P / (16 * A), h = hash(id);
    let floors = num(t['building:levels']), kind = 'house';
    if (!floors && num(t.height)) floors = Math.round(num(t.height) / 3.2);
    if (!floors) {
      if (['house', 'detached', 'roof', 'carport', 'garage', 'garages', 'shed'].includes(t.building)) floors = t.building === 'roof' || t.building === 'carport' ? 1 : 1 + (h > 0.8);
      else if (t.building === 'apartments' || t.building === 'residential') floors = [4, 5, 5, 9, 9][Math.floor(h * 5)];
      else if (A < 160) floors = 1;
      else if (A < 420) floors = 1 + (h > 0.55) + (h > 0.9);
      else if (elong > 1.7) floors = h < 0.15 ? 4 : h < 0.65 ? 5 : 9;
      else floors = 2 + Math.floor(h * 3);
    }
    if (floors >= 3) kind = 'panel';
    const y1 = kind === 'panel' ? floors * 3 + 0.6 : floors * 3.6, y0 = num(t.min_height) || 0;
    solids.push({ ring, y0, y1, kind, h, floors, A });
  };
  for (const w of ways.values()) if (w.tags && (w.tags.building || w.tags['building:part']) && !(w.tags.building === 'roof' && w.tags['building:part'])) add(ringOf(w), w.tags, w.id);
  for (const r of rels) if (r.tags.building) for (const m of r.members || []) if (m.role === 'outer' && ways.get(m.ref)) add(ringOf(ways.get(m.ref)), r.tags, r.id);

  const W = { motorway: 26, trunk: 22, primary: 18, secondary: 14, tertiary: 10, motorway_link: 8, trunk_link: 8, primary_link: 8, secondary_link: 7, tertiary_link: 7, unclassified: 7, residential: 6.5, living_street: 5, service: 4, pedestrian: 5, footway: 2.2, path: 1.6 };
  const roads = []; for (const w of ways.values()) { const t = w.tags && w.tags.highway; if (!t || !W[t] || w.tags.tunnel === 'yes' || +w.tags.layer < 0 || w.tags.area === 'yes') continue; const line = lineOf(w); if (line.length > 1) roads.push({ t, w: W[t], line, bridge: w.tags.bridge === 'yes', lanes: +w.tags.lanes || 0 }); }

  // наш участок: ближе к центру, без дорог, с наименьшим сносом застройки; повёрнут вдоль ближайшей улицы
  const segs = []; for (const r of roads) if (!['footway', 'path'].includes(r.t)) for (let i = 0; i < r.line.length - 1; i++) segs.push([r.line[i], r.line[i + 1], r.w, r.t]);
  const segDist = ([px, pz], [[ax, az], [bx, bz]]) => { const dx = bx - ax, dz = bz - az, L2 = dx * dx + dz * dz || 1; const k = Math.max(0, Math.min(1, ((px - ax) * dx + (pz - az) * dz) / L2)); return Math.hypot(px - ax - dx * k, pz - az - dz * k); };
  const PL = C.L + 44, PW = C.W + 56;   // участок со двором
  let best = null;
  for (let gx = -260; gx <= 260; gx += 10) for (let gz = -260; gz <= 260; gz += 10) {
    const p = [gx, gz];
    let near = null, nd = 1e9; for (const s of segs) { if (!['primary', 'secondary', 'tertiary', 'residential', 'unclassified'].includes(s[3])) continue; const d = segDist(p, s); if (d < nd) { nd = d; near = s; } }
    if (!near) continue;
    const rot = -Math.atan2(near[1][1] - near[0][1], near[1][0] - near[0][0]), c = Math.cos(rot), s = Math.sin(rot);
    const loc = ([x, z]) => [(x - gx) * c - (z - gz) * s, (x - gx) * s + (z - gz) * c];
    const inside = q => { const [lx, lz] = loc(q); return Math.abs(lx) < PL / 2 && Math.abs(lz) < PW / 2; };
    let bad = false;
    for (const sg of segs) { if (sg[3] === 'service') continue; for (let k = 0; k <= 8 && !bad; k++) { const q = [sg[0][0] + (sg[1][0] - sg[0][0]) * k / 8, sg[0][1] + (sg[1][1] - sg[0][1]) * k / 8]; const [lx, lz] = loc(q); if (Math.abs(lx) < PL / 2 + sg[2] / 2 && Math.abs(lz) < PW / 2 + sg[2] / 2) bad = true; } if (bad) break; }
    if (bad) continue;
    let cost = 0; for (const o of solids) { const cc = centroid(o.ring); if (inside(cc)) cost += o.A * (o.kind === 'panel' ? 6 : 1); }
    cost += Math.hypot(gx, gz) * 6 + Math.max(0, nd - PW / 2 - 25) * 60;
    if (!best || cost < best.cost) best = { x: gx, z: gz, rot, cost, loc, near };
  }
  {   // фасадом (+z) к улице
    const m = [(best.near[0][0] + best.near[1][0]) / 2, (best.near[0][1] + best.near[1][1]) / 2];
    if (best.loc(m)[1] < 0) { const r = best.rot + Math.PI, c = Math.cos(r), s = Math.sin(r), { x: gx, z: gz } = best; best.rot = r; best.loc = ([x, z]) => [(x - gx) * c - (z - gz) * s, (x - gx) * s + (z - gz) * c]; }
  }
  const clearOf = o => { const [lx, lz] = best.loc(centroid(o.ring)); return !(Math.abs(lx) < PL / 2 + 4 && Math.abs(lz) < PW / 2 + 4) && !o.ring.some(q => { const [a, b] = best.loc(q); return Math.abs(a) < PL / 2 && Math.abs(b) < PW / 2; }); };
  const parks = [], water = [];
  for (const w of ways.values()) { const t = w.tags || {}; if (['park', 'garden', 'pitch', 'playground'].includes(t.leisure) || ['grass', 'recreation_ground'].includes(t.landuse) || t.natural === 'wood') parks.push({ ring: ringOf(w), pitch: t.leisure === 'pitch' }); if (t.natural === 'water' || t.waterway === 'riverbank' || t.waterway === 'canal' && ringOf(w).length > 3 && !w.nodes.length) water.push(ringOf(w)); }
  const canals = []; for (const w of ways.values()) { const t = w.tags || {}; if (['canal', 'river'].includes(t.waterway)) canals.push({ line: lineOf(w), w: t.waterway === 'river' ? 30 : 14 }); else if (t.waterway === 'ditch' || t.waterway === 'stream') canals.push({ line: lineOf(w), w: 3 }); }
  return { C, solids: solids.filter(clearOf), roads, parks, water, canals, site: best, PL, PW };
}

/* ---------------- построение ---------------- */
function build(key, D, state, file) {
  setSeed(17);
  const { C, solids, roads, parks, water, canals, site } = D, S = new Scene();
  for (const k of ['panel', 'panelNight', 'house', 'houseNight', 'resid', 'residNight', 'shop', 'shopNight', 'paving', 'dirt']) S.image(k, tex(k));
  S.image('concrete', facade('concrete')); S.image('asphalt', facade('asphalt'));

  const panelTints = [[255, 255, 255], [255, 238, 220], [236, 244, 255], [255, 246, 228]];
  panelTints.forEach((t, i) => S.mat('panel' + i, { c: t, tex: 'panel', r: 0.9 }, { c: t.map(v => v * 0.3), tex: 'panel', r: 0.9, etex: 'panelNight', e: [1.4, 1.4, 1.4] }));
  const houseTints = [[255, 250, 238], [250, 236, 214], [238, 230, 222], [255, 244, 222]];
  houseTints.forEach((t, i) => S.mat('house' + i, { c: t, tex: 'house', r: 0.95 }, { c: t.map(v => v * 0.25), tex: 'house', r: 0.95, etex: 'houseNight', e: [1.2, 1.2, 1.2] }));
  const roofTints = [[150, 156, 160], [88, 130, 110], [150, 84, 66], [92, 112, 150], [176, 172, 160]];   // оцинковка, зелёная, красно-коричневая, синяя крыша
  roofTints.forEach((t, i) => S.mat('roof' + i, { c: t, m: 0.35, r: 0.55 }, { c: t.map(v => v * 0.18), m: 0.35, r: 0.55 }));
  S.mat('flatroof', { c: [92, 90, 88], r: 0.95 }, { c: [30, 30, 32], r: 0.95 });
  S.mat('ground', { c: [66, 64, 56], r: 1 }, { c: [30, 30, 30], r: 1 });
  S.mat('grass', { c: [96, 140, 70], r: 1 }, { c: [16, 26, 14], r: 1 }); S.mat('pitch', { c: [70, 140, 80], r: 0.9 }, { c: [14, 30, 18], r: 0.9 });
  S.mat('road', { c: [255, 255, 255], tex: 'asphalt', r: 0.95 }, { c: [110, 110, 115], tex: 'asphalt', r: 0.9 });
  S.mat('walk', { c: [176, 170, 160], r: 0.9 }, { c: [52, 50, 48], r: 0.9 }); S.mat('curb', { c: [210, 206, 198], r: 0.8 }, { c: [60, 60, 58], r: 0.8 });
  S.mat('mark', { c: [240, 240, 236], r: 0.8 }, { c: [90, 90, 88], r: 0.8 });
  S.mat('water', { c: [60, 104, 110], m: 0.3, r: 0.15 }, { c: [10, 18, 28], m: 0.8, r: 0.06 });
  S.mat('headlights', { c: [62, 64, 66], r: 0.9 }, { c: [0, 0, 0], e: [6, 5.4, 4.2] }); S.mat('taillights', { c: [62, 64, 66], r: 0.9 }, { c: [0, 0, 0], e: [6, 0.6, 0.4] });
  S.mat('lamp', { c: [235, 235, 225], r: 0.5 }, { c: [0, 0, 0], e: [9, 7, 4.5] }); S.mat('pole', { c: [70, 74, 80], m: 0.6, r: 0.4 }, { c: [26, 28, 30], m: 0.6, r: 0.4 });
  S.mat('leaf', { c: [74, 116, 54], r: 1 }, { c: [12, 22, 12], r: 1 }); S.mat('leaf2', { c: [98, 132, 60], r: 1 }, { c: [16, 24, 12], r: 1 }); S.mat('trunk', { c: [100, 82, 64], r: 1 }, { c: [22, 18, 14], r: 1 });
  const carCols = [[240, 240, 240], [30, 32, 36], [180, 184, 190], [150, 30, 30], [40, 70, 140], [235, 235, 235], [200, 200, 205]];   // в Ташкенте много белых и серых машин
  carCols.forEach((c, i) => S.mat('car' + i, { c, m: 0.5, r: 0.3 }, { c: c.map(v => v * 0.15), m: 0.5, r: 0.3 }));
  S.mat('carGlass', { c: [30, 40, 50], m: 0.6, r: 0.1 }, { c: [6, 8, 10], m: 0.6, r: 0.1 });
  S.mat('resid', { c: [255, 255, 255], tex: 'resid', r: 0.6 }, { c: [70, 70, 72], tex: 'resid', r: 0.6, etex: 'residNight', e: [1.1, 1.1, 1.1] });
  S.mat('residEmpty', { c: [255, 255, 255], tex: 'resid', r: 0.6 }, { c: [70, 70, 72], tex: 'resid', r: 0.6 });
  S.mat('shop', { c: [255, 255, 255], tex: 'shop', m: 0.3, r: 0.2 }, { c: [40, 40, 40], tex: 'shop', r: 0.2, etex: 'shopNight', e: [2.2, 2.2, 2.2] });
  S.mat('accent', { c: [176, 120, 80], r: 0.6 }, { c: [40, 28, 20], r: 0.6 });
  S.mat('paving', { c: [255, 255, 255], tex: 'paving', r: 0.9 }, { c: [70, 68, 64], tex: 'paving', r: 0.9 });
  S.mat('dirt', { c: [255, 255, 255], tex: 'dirt', r: 1 }, { c: [60, 52, 44], tex: 'dirt', r: 1 });
  S.mat('concrete', { c: [255, 255, 255], tex: 'concrete', r: 0.95 }, { c: [80, 80, 80], tex: 'concrete', r: 0.95 });
  S.mat('crane', { c: [240, 180, 30], m: 0.3, r: 0.5 }, { c: [60, 46, 10], m: 0.3, r: 0.5 }); S.mat('craneLight', { c: [255, 80, 60] }, { c: [0, 0, 0], e: [8, 0.5, 0.3] });
  S.mat('flood', { c: [240, 240, 230] }, { c: [0, 0, 0], e: [10, 9, 7] });
  S.mat('net', { c: [40, 120, 70], r: 0.9, a: 0.55 }, { c: [10, 30, 18], r: 0.9, a: 0.55 }); S.mat('steel', { c: [120, 124, 130], m: 0.8, r: 0.35 }, { c: [40, 42, 46], m: 0.8, r: 0.35 });
  S.mat('rebar', { c: [130, 70, 40], m: 0.6, r: 0.6 }, { c: [30, 18, 10], m: 0.6, r: 0.6 }); S.mat('block', { c: [220, 214, 200], r: 0.9 }, { c: [50, 48, 46], r: 0.9 });
  S.mat('fence', { c: [30, 110, 70], r: 0.7 }, { c: [10, 30, 20], r: 0.7 }); S.mat('cabin', { c: [40, 90, 150], r: 0.6 }, { c: [10, 22, 40], r: 0.6 });
  S.mat('cabinWin', { c: [60, 70, 80], r: 0.2 }, { c: [0, 0, 0], e: [3, 2.6, 2] });
  S.mat('play', { c: [230, 90, 50], r: 0.6 }, { c: [40, 16, 10], r: 0.6 }); S.mat('play2', { c: [60, 160, 210], r: 0.6 }, { c: [10, 28, 40], r: 0.6 }); S.mat('rubber', { c: [190, 80, 70], r: 1 }, { c: [36, 16, 14], r: 1 });
  S.mat('bench', { c: [130, 92, 60], r: 0.8 }, { c: [26, 18, 12], r: 0.8 }); S.mat('parkLine', { c: [245, 245, 245], r: 0.8 }, { c: [70, 70, 70], r: 0.8 });

  // земля, каналы (арыки и Анхор/Салар), парки
  S.box('ground', [0, -0.6, 0], [2000, 1, 2000]);
  for (const p of parks) if (p.ring.length > 2) S.flat(p.pitch ? 'pitch' : 'grass', p.ring, 0.03);
  for (const r of water) if (r.length > 2) S.flat('water', r, -0.2);
  for (const c of canals) { S.ribbon('water', c.line, c.w, -0.15); if (c.w > 5) { S.ribbon('grass', c.line, c.w + 10, -0.25); } }

  // повторяемые объекты — одна геометрия, много экземпляров
  S.group('tree', g => { g.cyl('trunk', [0, 0, 0], 0.22, 3.4, 4); g.sphere('leaf', [0, 5.2, 0], 3.0, 6, 1.1); g.sphere('leaf', [0.9, 6.4, 0.4], 1.9, 5, 1); });
  S.group('tree2', g => { g.cyl('trunk', [0, 0, 0], 0.2, 3, 4); g.sphere('leaf2', [0, 4.8, 0], 2.6, 6, 1.25); });
  carCols.forEach((c, i) => S.group('car' + i, g => { g.box('car' + i, [0, 0.75, 0], [4.4, 0.9, 1.8]); g.box('carGlass', [-0.3, 1.45, 0], [2.42, 0.4, 1.62]); }));
  S.group('streetlamp', g => { g.cyl('pole', [0, 0, 0], 0.12, 9, 4); g.box('pole', [0, 9, -0.7], [0.12, 0.12, 1.4]); });
  S.group('yardlamp', g => { g.cyl('pole', [0, 0, 0], 0.08, 4, 6); });
  const car = (x, y, z, ang) => S.place('car' + Math.floor(rnd() * carCols.length), [x, y, z], 1, ang);

  // дороги, разметка, тротуары с деревьями, фонари, машины и ночной поток фар
  const off = (line, d) => line.map(([x, z], i, a) => { const n = a[i + 1] || a[i - 1], dd = Math.hypot(n[0] - x, n[1] - z) || 1, s = i + 1 < a.length ? 1 : -1; return [x - (n[1] - z) / dd * d * s, z + (n[0] - x) / dd * d * s]; });
  const trees = [];
  for (const r of roads) {
    const y = r.bridge ? 5 : (['footway', 'path', 'pedestrian'].includes(r.t) ? 0.05 : 0.07);
    if (['footway', 'path', 'pedestrian'].includes(r.t)) { S.ribbon('walk', r.line, r.w, y); continue; }
    S.ribbon('road', r.line, r.w, y);
    if (r.w >= 6.5) { S.ribbon('walk', off(r.line, r.w / 2 + 2.2), 3.2, 0.12); S.ribbon('walk', off(r.line, -(r.w / 2 + 2.2)), 3.2, 0.12); S.ribbon('curb', off(r.line, r.w / 2 + 0.3), 0.3, 0.14); S.ribbon('curb', off(r.line, -(r.w / 2 + 0.3)), 0.3, 0.14); }
    if (r.w >= 10) {
      S.ribbon('mark', r.line, 0.22, y + 0.02);
      if (r.w >= 14) { S.ribbon('mark', off(r.line, r.w / 4), 0.14, y + 0.02); S.ribbon('mark', off(r.line, -r.w / 4), 0.14, y + 0.02); }
      S.ribbon('headlights', off(r.line, r.w * 0.22), 1.0, y + 0.05); S.ribbon('taillights', off(r.line, -r.w * 0.22), 1.0, y + 0.05);
    }
    // по сегментам: деревья вдоль улицы (чинары), фонари, машины
    let acc = rnd() * 10, accL = 0, accC = rnd() * 20;
    for (let i = 0; i < r.line.length - 1; i++) {
      const [ax, az] = r.line[i], [bx, bz] = r.line[i + 1], len = Math.hypot(bx - ax, bz - az); if (len < 0.1) continue;
      const ux = (bx - ax) / len, uz = (bz - az) / len, nx = -uz, nz = ux, ang = -Math.atan2(uz, ux);
      if (r.w >= 6.5) for (acc += len; acc > 11; acc -= 11) { const t = len - acc + 11; if (t < 0 || t > len) continue; for (const sd of [1, -1]) if (rnd() < 0.8) trees.push([ax + ux * t + nx * sd * (r.w / 2 + 4.6), az + uz * t + nz * sd * (r.w / 2 + 4.6), 0.8 + rnd() * 0.6]); }
      if (r.w >= 10) for (accL += len; accL > 32; accL -= 32) { const t = len - accL + 32; if (t < 0 || t > len) continue; const x = ax + ux * t + nx * (r.w / 2 + 1), z = az + uz * t + nz * (r.w / 2 + 1); S.place('streetlamp', [x, y, z], 1, Math.atan2(nx, nz)); S.box('lamp', [x - nx * 1.4, y + 8.9, z - nz * 1.4], [0.7, 0.2, 0.7]); }
      if (r.w >= 6.5) for (accC += len; accC > 9; accC -= 9) {
        const t = len - accC + 9; if (t < 0 || t > len || rnd() > (r.w >= 10 ? 0.32 : 0.08)) continue;
        const lane = r.w >= 10 ? (rnd() < 0.5 ? 1 : -1) * r.w * (0.12 + rnd() * 0.2) : (rnd() < 0.5 ? 1 : -1) * (r.w / 2 - 1.2);
        car(ax + ux * t + nx * lane, y, az + uz * t + nz * lane, ang + (lane < 0 ? Math.PI : 0));
      }
    }
  }

  // городская застройка
  for (const o of solids) {
    if (o.kind === 'panel') S.extrude('panel' + Math.floor(o.h * 97 % panelTints.length), 'flatroof', o.ring, o.y0, o.y1, T3);
    else S.extrude('house' + Math.floor(o.h * 89 % houseTints.length), 'roof' + Math.floor(o.h * 53 % roofTints.length), o.ring, o.y0, o.y1, TH);
  }

  // деревья в махаллях и дворах (Ташкент — очень зелёный город)
  const box = { x0: -580, x1: 580, z0: -560, z1: 560 };
  for (let i = 0; i < 3000; i++) { const x = box.x0 + rnd() * (box.x1 - box.x0), z = box.z0 + rnd() * (box.z1 - box.z0); trees.push([x, z, 0.7 + rnd() * 0.8, true]); }
  const { x: X, z: Z, rot } = site, c = Math.cos(rot), s = Math.sin(rot);
  const W2 = ([lx, lz]) => [X + lx * c + lz * s, Z - lx * s + lz * c];   // локальные координаты участка → мир (как поворот box)
  const onSite = ([x, z]) => { const [lx, lz] = site.loc([x, z]); return Math.abs(lx) < D.PL / 2 + 3 && Math.abs(lz) < D.PW / 2 + 3; };
  let nt = 0;
  for (const [x, z, k, free] of trees) {
    if (nt > 2800 || onSite([x, z])) continue;
    if (solids.some(o => Math.abs(o.ring[0][0] - x) < 90 && Math.abs(o.ring[0][1] - z) < 90 && inPoly([x, z], o.ring))) continue;
    if (free && roads.some(r => r.w >= 4 && r.line.some(([px, pz]) => Math.hypot(px - x, pz - z) < r.w))) continue;
    nt++; S.place(nt % 3 ? 'tree' : 'tree2', [x, 0, z], k, rnd() * 6.28);
  }

  /* ---------- наш дом ---------- */
  const at = (lx, lz) => W2([lx, lz]);
  const B = (m, lx, y, lz, sx, sy, sz) => { const [x, z] = at(lx, lz); S.box(m, [x, y, z], [sx, sy, sz], rot); };
  const rect = (lx, lz, w, d) => [[lx - w / 2, lz - d / 2], [lx + w / 2, lz - d / 2], [lx + w / 2, lz + d / 2], [lx - w / 2, lz + d / 2]].map(([a, b]) => at(a, b));
  const fin = state === 'finished', GF = 4.5, FH = 3, top = GF + (C.floors - 1) * FH, done = fin ? C.floors : C.done;
  const secL = C.L / C.sections;
  const PL = D.PL, PW = D.PW, frontZ = PW / 2;   // фасад к улице: +z локально
  S.flat(fin ? 'paving' : 'dirt', rect(0, 0, PL, PW), 0.1);
  for (let i = 0; i < C.sections; i++) {
    const lx = -C.L / 2 + secL * (i + 0.5), lz = C.sections > 1 ? (i % 2 ? 1.6 : -1.6) : 0, ring = rect(lx, lz, secL, C.W);
    S.extrude('shop', 'flatroof', ring, 0, GF, { cols: 1, rows: 1, win: 6, floor: GF });
    const yF = fin ? top : GF + (done - 4) * FH;   // облицовка отстаёт от каркаса на 3 этажа
    S.extrude(fin ? 'resid' : 'residEmpty', 'flatroof', ring, GF, yF, T3);
    if (fin) {
      S.extrude('accent', 'flatroof', rect(lx, lz, secL + 0.4, C.W + 0.4), top, top + 1.2);   // парапет
      B('flatroof', lx, top + 2.6, lz, 6, 3, 5);   // машинное помещение лифтов
      B('accent', lx, GF / 2 + 1, lz + C.W / 2 + 1.5, 6, 0.4, 3);   // козырёк входа
    } else {
      for (let f = done - 4; f < done; f++) {   // монолитные перекрытия и колонны
        const y = GF + f * FH; S.extrude('concrete', 'concrete', rect(lx, lz, secL, C.W), y, y + 0.3);
        for (let cx = -secL / 2 + 0.6; cx <= secL / 2 - 0.6; cx += 6) for (let cz = -C.W / 2 + 0.6; cz <= C.W / 2 - 0.6; cz += 6) B('concrete', lx + cx, y + 0.3 + (FH - 0.3) / 2, lz + cz, 0.5, FH - 0.3, 0.5);
      }
      B('concrete', lx, GF + done * FH + 3, lz, 5, 6, 6);   // лифтовая шахта выше
      S.extrude('net', 'net', rect(lx, lz, secL + 1, C.W + 1), GF + (done - 4) * FH, GF + (done - 4) * FH + 2 * FH);
      for (const [fx, fz] of [[-secL / 2, C.W / 2], [secL / 2, -C.W / 2]]) B('flood', lx + fx, GF + done * FH + 1, lz + fz, 0.8, 0.5, 0.8);
    }
  }
  if (fin) {
    // двор: газоны, детская площадка, парковка, деревья, скамейки, фонари
    S.flat('grass', rect(-PL / 4, -PW / 2 + 12, PL / 2 - 6, 16), 0.14); S.flat('rubber', rect(PL / 4, -PW / 2 + 12, 22, 14), 0.15);
    for (const [px, pz, m, sx, sy, sz] of [[PL / 4 - 6, -PW / 2 + 10, 'play', 3, 2.6, 3], [PL / 4 + 2, -PW / 2 + 13, 'play2', 5, 1.4, 1.2], [PL / 4 + 7, -PW / 2 + 9, 'play', 1.2, 3.2, 1.2], [PL / 4 + 6, -PW / 2 + 15, 'play2', 4, 0.6, 4]]) B(m, px, sy / 2 + 0.15, pz, sx, sy, sz);
    S.flat('road', rect(0, frontZ - 6, PL - 4, 12), 0.13);
    for (let k = -PL / 2 + 5; k < PL / 2 - 4; k += 2.8) { B('parkLine', k, 0.15, frontZ - 6, 0.12, 0.02, 5.2); if (rnd() < 0.7) { const [x, z] = at(k + 1.4, frontZ - 6.2); car(x, 0.1, z, rot + Math.PI / 2); } }
    for (let i = 0; i < 18; i++) { const lx = -PL / 2 + 4 + rnd() * (PL - 8), lz = -PW / 2 + 3 + rnd() * 6; S.place('tree2', [...at(lx, lz)].flatMap((v, i) => i ? [0, v] : [v]), 0.8 + rnd() * 0.3, rnd() * 6.28); }
    for (let k = -PL / 2 + 6; k < PL / 2; k += 14) { const [x, z] = at(k, -PW / 2 + 20); S.place('yardlamp', [x, 0, z]); S.box('lamp', [x, 4.1, z], [0.5, 0.3, 0.5]); B('bench', k + 3, 0.45, -PW / 2 + 20, 1.8, 0.9, 0.6); }
  } else {
    // стройплощадка: забор, бытовки, кран(ы), арматура, блоки, миксер, прожекторы
    for (const [lx, lz, w, d] of [[0, PW / 2, PL, 0.2], [0, -PW / 2, PL, 0.2], [PL / 2, 0, 0.2, PW], [-PL / 2, 0, 0.2, PW]]) B('fence', lx, 1.1, lz, w, 2.2, d);
    for (let k = 0; k < 4; k++) { B('cabin', -PL / 2 + 5 + k * 2.6, 1.3, -PW / 2 + 4, 2.4, 2.6, 6); B('cabinWin', -PL / 2 + 5 + k * 2.6, 1.6, -PW / 2 + 7.02, 1.2, 0.8, 0.05); }
    for (let k = 0; k < 6; k++) B('rebar', PL / 2 - 8, 0.2 + k * 0.12, -PW / 2 + 5 + k * 0.05, 12, 0.1, 2);
    for (let k = 0; k < 8; k++) B('block', PL / 2 - 16 + (k % 4) * 1.4, 0.6 + Math.floor(k / 4) * 1.1, -PW / 2 + 10, 1.2, 1, 1.2);
    B('car2', -PL / 2 + 18, 1.6, PW / 2 - 6, 8, 2.4, 2.5); B('crane', -PL / 2 + 21, 2.8, PW / 2 - 6, 3.5, 2.2, 2.3);   // автобетоносмеситель
    const H = top + 14;
    for (let n = 0; n < C.cranes; n++) {
      const kx = C.cranes > 1 ? (n ? C.L / 3 : -C.L / 3) : 0, kz = -C.W / 2 - 6, ang = rot - Math.PI / 2 + (C.cranes > 1 ? (n ? 0.55 : -0.55) : 0.3);   // стрела над домом, краны не задевают друг друга
      for (let y = 0; y < H; y += 4) { B('crane', kx, y + 2, kz, 1.8, 4, 1.8); B('steel', kx, y + 2, kz, 1.9, 0.15, 1.9); }
      const [wx, wz] = at(kx, kz), ca = Math.cos(ang), sa = Math.sin(ang);
      S.box('crane', [wx + ca * 18, H + 1, wz - sa * 18], [44, 1.3, 1.3], ang); S.box('concrete', [wx - ca * 9, H, wz + sa * 9], [5, 2.5, 2.5], ang);
      S.box('crane', [wx, H + 2.5, wz], [3, 3, 2.4], ang); S.cyl('steel', [wx, H, wz], 0.4, 7, 6);
      S.box('craneLight', [wx + ca * 40, H + 1.8, wz - sa * 40], [0.6, 0.6, 0.6]); S.box('craneLight', [wx, H + 7.2, wz], [0.6, 0.6, 0.6]);
      S.cyl('steel', [wx + ca * 26, H - 18, wz - sa * 26], 0.05, 18, 4); S.box('rebar', [wx + ca * 26, H - 18.5, wz - sa * 26], [4, 0.6, 1.2], ang);
    }
    for (const [lx, lz] of [[-PL / 2 + 1, PW / 2 - 1], [PL / 2 - 1, -PW / 2 + 1]]) { const [x, z] = at(lx, lz); S.cyl('pole', [x, 0, z], 0.12, 10, 6); S.box('flood', [x, 10, z], [1.2, 0.6, 1.2]); }
  }
  S.save(file, 'Tashkent · ' + C.name, 'Tashkent');
  return { x: Math.round(X), z: Math.round(Z), top: Math.round(top), rot: +rot.toFixed(3) };
}

const out = fs.existsSync(path.join(DIR, 'tsh-sites.json')) ? JSON.parse(fs.readFileSync(path.join(DIR, 'tsh-sites.json'), 'utf8')) : {};
for (const key of (process.argv[2] ? [process.argv[2]] : Object.keys(SITES))) {
  const D = load(key);
  const info = build(key, D, 'current', path.join(DIR, 'tsh-' + key + '-current.glb'));
  build(key, D, 'finished', path.join(DIR, 'tsh-' + key + '-finished.glb'));
  pack(path.join(DIR, 'tsh-' + key + '-current.glb')); pack(path.join(DIR, 'tsh-' + key + '-finished.glb'));
  out[key] = { ...info, floors: SITES[key].floors, done: SITES[key].done };   // для сидера: камера на наш дом
  console.log(key, 'site', JSON.stringify(info), 'buildings', D.solids.length, 'roads', D.roads.length);
}
fs.writeFileSync(path.join(DIR, 'tsh-sites.json'), JSON.stringify(out, null, 1));
