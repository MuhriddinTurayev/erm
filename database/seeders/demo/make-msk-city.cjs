// Москва-Сити по данным OpenStreetMap (© участники OSM, ODbL): настоящие башни, дороги, река.
// Наш объект — «Oftob Tower» на свободном участке: два состояния (сейчас / готово),
// у материалов два варианта (KHR_materials_variants): «day» и «night» — ночью горят окна, фонари, поток машин.
// Запуск: node database/seeders/demo/make-msk-city.cjs → msk-city-current.glb, msk-city-finished.glb
const fs = require("fs"), path = require("path");
const { pack, rnd, setSeed, facade, Scene, inPoly, centroid } = require("./city-lib.cjs");
const DIR = __dirname;
const osm = [require('./msk-city-osm.json'), require('./msk-city-parts.json')];

/* ---------------- данные OSM ---------------- */
const LAT0 = 55.7490, LON0 = 37.5385, KX = Math.cos(LAT0 * Math.PI / 180) * 111320, KZ = 110540;
const nodes = new Map(), ways = new Map(), rels = [];
for (const d of osm) for (const e of d.elements) { if (e.type === 'node') nodes.set(e.id, [(e.lon - LON0) * KX, -(e.lat - LAT0) * KZ]); else if (e.type === 'way') ways.set(e.id, Object.assign(ways.get(e.id) || {}, e)); else if (e.type === 'relation' && e.tags) rels.push(e); }
const ringOf = w => { const r = (w.nodes || []).map(id => nodes.get(id)).filter(Boolean); if (r.length > 2 && r[0][0] === r[r.length - 1][0] && r[0][1] === r[r.length - 1][1]) r.pop(); return r; };
const num = v => { const f = parseFloat(String(v || '').replace(',', '.')); return isFinite(f) ? f : 0; };
const heightOf = t => num(t.height) || num(t['building:levels']) * 3.7 || (t.building === 'yes' || t.building ? 18 : 12);
const minOf = t => num(t.min_height) || num(t['building:min_level']) * 3.7 || 0;

// части зданий и здания (у здания с частями рисуем только части)
const solids = [];   // {ring, y0, y1, name, tall}
const partsRings = [];
const pushSolid = (ring, tags) => { if (ring.length < 3) return; const y1 = heightOf(tags), y0 = Math.min(minOf(tags), y1 - 1); solids.push({ ring, y0, y1, name: tags.name || '', tall: y1 > 70 }); };
for (const w of ways.values()) if (w.tags && w.tags['building:part']) { const r = ringOf(w); partsRings.push(r); pushSolid(r, w.tags); }
for (const r of rels) if (r.tags['building:part']) for (const m of r.members || []) if (m.role === 'outer' && ways.get(m.ref)) { const rr = ringOf(ways.get(m.ref)); partsRings.push(rr); pushSolid(rr, r.tags); }
const hasParts = ring => partsRings.some(p => p.length && inPoly(centroid(p), ring));
for (const w of ways.values()) if (w.tags && w.tags.building && !w.tags['building:part']) { const r = ringOf(w); if (!hasParts(r)) pushSolid(r, w.tags); }
for (const r of rels) if (r.tags.building && !r.tags['building:part']) for (const m of r.members || []) if (m.role === 'outer' && ways.get(m.ref)) { const rr = ringOf(ways.get(m.ref)); if (!hasParts(rr)) pushSolid(rr, r.tags); }

// наш участок: свободное место на набережной у кластера башен
const SITE = { x: 250, z: 150, w: 46, d: 40, floors: 82, done: 46 };
const free = s => !solids.some(o => Math.abs(centroid(o.ring)[0] - SITE.x) < 60 && Math.abs(centroid(o.ring)[1] - SITE.z) < 55);
for (let tries = 0; !free() && tries < 60; tries++) { SITE.x += (tries % 2 ? -1 : 1) * 25; SITE.z -= 18; }
const siteClear = o => { const [cx, cz] = centroid(o.ring); return !(Math.abs(cx - SITE.x) < 45 && Math.abs(cz - SITE.z) < 40); };

/* ---------------- построение ---------------- */
function build(state, file) {
  setSeed(11);
  const S = new Scene();
  S.image('glass', facade('glass')); S.image('night', facade('night')); S.image('stone', facade('stone')); S.image('stoneNight', facade('stoneNight'));
  S.image('concrete', facade('concrete')); S.image('asphalt', facade('asphalt'));
  const tints = [[110, 150, 190], [120, 175, 175], [175, 160, 140], [170, 180, 190], [95, 120, 160]];
  tints.forEach((t, i) => S.mat('glass' + i, { c: t.map(v => Math.min(255, v * 1.6)), tex: 'glass', m: 0.55, r: 0.12 }, { c: t.map(v => v * 0.25), tex: 'glass', m: 0.7, r: 0.1, etex: 'night', e: [1.3, 1.3, 1.3] }));
  S.mat('stone', { c: [255, 255, 255], tex: 'stone', r: 0.85 }, { c: [90, 90, 90], tex: 'stone', r: 0.85, etex: 'stoneNight', e: [1.6, 1.6, 1.6] });
  S.mat('roof', { c: [120, 124, 130], r: 0.9 }, { c: [40, 42, 46], r: 0.9 });
  S.mat('ground', { c: [40, 44, 42], r: 1 }, { c: [30, 32, 34], r: 1 }); S.mat('grass', { c: [88, 132, 68], r: 1 }, { c: [20, 30, 18], r: 1 });
  S.mat('road', { c: [255, 255, 255], tex: 'asphalt', r: 0.95 }, { c: [120, 120, 125], tex: 'asphalt', r: 0.9 });
  S.mat('walk', { c: [150, 146, 138], r: 0.9 }, { c: [60, 58, 56], r: 0.9 }); S.mat('mark', { c: [235, 235, 230], r: 0.8 }, { c: [90, 90, 88], r: 0.8 });
  S.mat('water', { c: [38, 66, 84], m: 0.25, r: 0.18 }, { c: [10, 18, 30], m: 0.8, r: 0.06 });
  S.mat('headlights', { c: [60, 62, 66], r: 0.9 }, { c: [0, 0, 0], e: [6, 5.4, 4.2] }); S.mat('taillights', { c: [60, 62, 66], r: 0.9 }, { c: [0, 0, 0], e: [6, 0.6, 0.4] });
  S.mat('lamp', { c: [235, 235, 225], r: 0.5 }, { c: [0, 0, 0], e: [9, 7, 4.5] }); S.mat('pole', { c: [70, 74, 80], m: 0.6, r: 0.4 }, { c: [30, 32, 35], m: 0.6, r: 0.4 });
  S.mat('leaf', { c: [72, 118, 60], r: 1 }, { c: [14, 24, 14], r: 1 }); S.mat('trunk', { c: [90, 70, 52], r: 1 }, { c: [24, 20, 16], r: 1 });
  S.mat('concrete', { c: [255, 255, 255], tex: 'concrete', r: 0.95 }, { c: [80, 80, 80], tex: 'concrete', r: 0.95 });
  S.mat('crane', { c: [240, 180, 30], m: 0.3, r: 0.5 }, { c: [60, 46, 10], m: 0.3, r: 0.5 }); S.mat('craneLight', { c: [255, 80, 60] }, { c: [0, 0, 0], e: [8, 0.5, 0.3] });
  S.mat('net', { c: [40, 120, 70], r: 0.9, a: 0.55 }, { c: [10, 30, 18], r: 0.9, a: 0.55 }); S.mat('steel', { c: [120, 124, 130], m: 0.8, r: 0.35 }, { c: [40, 42, 46], m: 0.8, r: 0.35 });
  S.mat('crown', { c: [200, 220, 240], m: 0.6, r: 0.1 }, { c: [0, 0, 0], e: [3, 5, 9] }); S.mat('fence', { c: [40, 90, 150], r: 0.7 }, { c: [12, 26, 44], r: 0.7 });
  S.mat('podium', { c: [60, 66, 74], m: 0.4, r: 0.3 }, { c: [0, 0, 0], e: [2.5, 2.1, 1.6] });

  // земля, вода, парки
  S.box('ground', [0, -0.6, 0], [3200, 1, 3200]);
  for (const w of ways.values()) if (w.tags && (w.tags.leisure === 'park')) S.flat('grass', ringOf(w), 0.02);
  for (const w of ways.values()) if (w.tags && (w.tags.natural === 'water' || w.tags.waterway === 'riverbank')) S.flat('water', ringOf(w), -0.3);
  for (const r of rels) if (r.tags.natural === 'water') for (const m of r.members || []) if (m.role === 'outer' && ways.get(m.ref)) S.flat('water', ringOf(ways.get(m.ref)), -0.3);

  // дороги: ширина по классу, разметка; ночью — поток фар и стоп-сигналов, фонари
  const W = { motorway: 24, trunk: 20, primary: 15, secondary: 12, tertiary: 10, motorway_link: 8, trunk_link: 8, primary_link: 8, unclassified: 7, residential: 7, service: 4.5, pedestrian: 5, footway: 2.4 };
  let lampAcc = 0;
  for (const w of ways.values()) {
    const t = w.tags && w.tags.highway; if (!t || !W[t] || w.tags.tunnel === 'yes' || w.tags.layer < 0) continue;
    const pts = ringOf(w).concat([]); const line = (w.nodes || []).map(id => nodes.get(id)).filter(Boolean); if (line.length < 2) continue;
    const y = w.tags.bridge === 'yes' ? 6 : 0.04 + (W[t] > 9 ? 0.02 : 0);
    S.ribbon(['footway', 'pedestrian'].includes(t) ? 'walk' : 'road', line, W[t], y);
    if (W[t] >= 10) {
      S.ribbon('mark', line, 0.25, y + 0.02);
      S.ribbon('headlights', line.map(([x, z], i, a) => { const n = a[i + 1] || a[i - 1], d = Math.hypot(n[0] - x, n[1] - z) || 1, s = i + 1 < a.length ? 1 : -1; return [x - (n[1] - z) / d * W[t] * 0.22 * s, z + (n[0] - x) / d * W[t] * 0.22 * s]; }), 1.1, y + 0.05);
      S.ribbon('taillights', line.map(([x, z], i, a) => { const n = a[i + 1] || a[i - 1], d = Math.hypot(n[0] - x, n[1] - z) || 1, s = i + 1 < a.length ? 1 : -1; return [x + (n[1] - z) / d * W[t] * 0.22 * s, z - (n[0] - x) / d * W[t] * 0.22 * s]; }), 1.1, y + 0.05);
      for (let i = 0; i < line.length - 1; i++) {   // фонари каждые ~35 м
        const [ax, az] = line[i], [bx, bz] = line[i + 1], len = Math.hypot(bx - ax, bz - az);
        for (lampAcc += len; lampAcc > 35; lampAcc -= 35) { const k = 1 - lampAcc / len; if (k < 0 || k > 1) continue; const x = ax + (bx - ax) * k + (bz - az) / len * W[t] * 0.55, z = az + (bz - az) * k - (bx - ax) / len * W[t] * 0.55;
          S.cyl('pole', [x, y, z], 0.12, 9, 6); S.box('lamp', [x, y + 9, z], [0.9, 0.25, 0.5]); }
      }
    }
  }

  // башни Москва-Сити и окружение
  let gi = 0;
  for (const o of solids) {
    if (!siteClear(o)) continue;
    const tall = o.y1 > 60;
    S.extrude(tall ? 'glass' + (gi++ % tints.length) : 'stone', 'roof', o.ring, o.y0, o.y1);
  }

  // деревья по набережной и в парках
  for (let i = 0; i < 220; i++) { const x = -700 + rnd() * 1400, z = -600 + rnd() * 1200; if (solids.some(o => inPoly([x, z], o.ring))) continue; if (Math.hypot(x - SITE.x, z - SITE.z) < 70) continue;
    S.cyl('trunk', [x, 0, z], 0.25, 3, 6); S.sphere('leaf', [x, 4.6, z], 2.6, 7, 1.2); }

  /* ---------- «Oftob Tower» ---------- */
  const { x: X, z: Z, w: TW, d: TD } = SITE, FH = 4.0, top = SITE.floors * FH, done = state === 'finished' ? SITE.floors : SITE.done;
  // стилобат с витринами (ночью светится)
  S.box('podium', [X, 4, Z], [TW + 30, 8, TD + 26]); S.box('walk', [X, 0.06, Z + TD / 2 + 22], [TW + 50, 0.12, 14]);
  const ring = [[X - TW / 2, Z - TD / 2], [X + TW / 2, Z - TD / 2], [X + TW / 2, Z + TD / 2], [X - TW / 2, Z + TD / 2]];
  // остекление по фасаду до готовых этажей
  const glazed = state === 'finished' ? top : (done - 6) * FH;
  S.extrude('glass0', 'roof', ring, 8, glazed);
  if (state === 'finished') {
    // венчающая часть: сужение и светящаяся корона
    const r2 = ring.map(([x, z]) => [X + (x - X) * 0.8, Z + (z - Z) * 0.8]);
    S.extrude('glass4', 'roof', r2, top, top + 18); S.extrude('crown', 'roof', r2.map(([x, z]) => [X + (x - X) * 0.6, Z + (z - Z) * 0.6]), top + 18, top + 34);
    S.cyl('steel', [X, top + 34, Z], 0.6, 30, 8); S.box('craneLight', [X, top + 64, Z], [1, 1, 1]);
    for (let i = 0; i < 10; i++) { const a = i / 10 * Math.PI * 2; S.cyl('trunk', [X + Math.cos(a) * 42, 0, Z + 30 + Math.sin(a) * 12], 0.25, 3, 6); S.sphere('leaf', [X + Math.cos(a) * 42, 4.5, Z + 30 + Math.sin(a) * 12], 2.4, 7, 1.2); }
  } else {
    // ядро жёсткости выше перекрытий, монолитные этажи без остекления, защитная сетка, краны
    for (let f = done - 6; f < done; f++) {
      const y = 8 + f * FH; S.box('concrete', [X, y, Z], [TW, 0.35, TD]);
      for (let cx = -TW / 2 + 1; cx <= TW / 2 - 1; cx += 7.5) for (let cz = -TD / 2 + 1; cz <= TD / 2 - 1; cz += 7.5) S.box('concrete', [X + cx, y + FH / 2, Z + cz], [0.8, FH, 0.8]);
    }
    S.box('concrete', [X, 8 + done * FH + 6, Z], [14, 12 + 6 * FH, 12]);   // ядро
    const yN = 8 + (done - 6) * FH; S.extrude('net', 'roof', ring.map(([x, z]) => [X + (x - X) * 1.03, Z + (z - Z) * 1.03]), yN, yN + 7 * FH);
    for (const [kx, kz, rot, h] of [[X + TW / 2 + 8, Z - 6, 0.6, top * 0.62 + 30], [X - TW / 2 - 8, Z + 10, 3.6, top * 0.62 + 18]]) {
      for (let y = 0; y < h; y += 4) { S.box('crane', [kx, y + 2, kz], [2.2, 4, 2.2]); S.box('steel', [kx, y + 2, kz], [2.3, 0.2, 2.3]); }
      const c = Math.cos(rot), s = Math.sin(rot);
      S.box('crane', [kx + c * 28, h + 1, kz - s * 28], [64, 1.6, 1.6], rot); S.box('concrete', [kx - c * 12, h, kz + s * 12], [6, 3, 3], rot);
      S.box('craneLight', [kx + c * 60, h + 2, kz - s * 60], [0.8, 0.8, 0.8]); S.box('craneLight', [kx, h + 8, kz], [0.8, 0.8, 0.8]); S.cyl('steel', [kx, h, kz], 0.5, 9, 6);
    }
    for (const [x, z, w, d] of [[X, Z + TD / 2 + 16, TW + 50, 0.3], [X - TW / 2 - 25, Z, 0.3, TD + 32], [X + TW / 2 + 25, Z, 0.3, TD + 32]]) S.box('fence', [x, 1.4, z], [w, 2.8, d]);
    for (let k = 0; k < 6; k++) S.box(k % 2 ? 'stone' : 'fence', [X - 20 + k * 3.4, 9.5, Z + TD / 2 + 8], [3.2, 2.8, 7]);
  }
  S.save(file);
  return { site: SITE, top };
}

const info = build('current', path.join(DIR, 'msk-city-current.glb'));
build('finished', path.join(DIR, 'msk-city-finished.glb'));
pack(path.join(DIR, 'msk-city-current.glb')); pack(path.join(DIR, 'msk-city-finished.glb'));
console.log('site', JSON.stringify(info), 'solids', solids.length);
