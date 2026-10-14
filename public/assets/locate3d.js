/* Квартира на настоящей 3D-модели дома (company/objects/locate.blade.php).
   В модели нет квартир — их находим по рамке корпуса (Services\Locate): подъезды делят рамку по длине (границы можно сдвинуть),
   этажи — по высоте, квартиры этажа — по фасадам: первая половина на лицевой стороне (+Z рамки), остальные — на задней.
   Рамку сначала ставит автопривязка (assets/locate-auto.js: форма модели + ИИ смотрит на фасады), человек поправляет:
   тянет точки на рамке (стороны, границы подъездов, высота 1-го этажа), смотрит спереди/сбоку/сверху, отменяет шаги. */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { TransformControls } from 'three/addons/controls/TransformControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { DRACOLoader } from 'three/addons/loaders/DRACOLoader.js';
import { MeshoptDecoder } from 'three/addons/libs/meshopt_decoder.module.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { CSS2DRenderer, CSS2DObject } from 'three/addons/renderers/CSS2DRenderer.js';
import { Line2 } from 'three/addons/lines/Line2.js';
import { LineMaterial } from 'three/addons/lines/LineMaterial.js';
import { LineGeometry } from 'three/addons/lines/LineGeometry.js';

const css = (n) => getComputedStyle(document.documentElement).getPropertyValue('--' + n).trim() || '#6c4cf1';
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

/** Границы подъездов по длине рамки, доли от 0 до 1: [0, …, 1]. ex — сдвинутые вручную или по дверям, иначе поровну */
export function splitsOf(B, C) {
  const E = Math.max(1, B.entr.length), ex = Array.isArray(C.ex) && C.ex.length === E - 1 ? C.ex : null;
  const sp = [0];
  for (let k = 1; k < E; k++) sp.push(ex ? ex[k - 1] : k / E);
  sp.push(1);
  return sp;
}
/** Доля высоты рамки, где кончается 1-й этаж */
function groundOf(C, F) {
  const H = C.s ? C.s[1] : 0;
  return F > 1 && C.gf > 0 && H > C.gf ? C.gf / H : 1 / F;
}

/** Где квартира стоит на фасаде, если её поставили на окна (ИИ или человек): [сторона ±1, x0, x1] в долях длины рамки */
export const slotOf = (u, C) => (C.ux && C.ux[u.id]) || (C.slots && C.slots[`${u.e}|${Math.max(1, u.n)}|${u.i}`]) || null;
/** Этаж модели для этажа объекта (в модели этажей может быть меньше) и доли высоты его низа и верха */
export function floorOf(u, B, C) {
  const F = Math.max(1, C.floors || B.floors), g = groundOf(C, F);
  const yAt = (k) => (k <= 0 ? 0 : F === 1 ? 1 : g + ((k - 1) * (1 - g)) / (F - 1));
  // в модели этажей меньше, чем в объекте (чужая или упрощённая модель): этаж объекта — на этаже модели той же доли высоты
  const N = Math.max(1, B.floors || F), uf = Math.max(1, u.f);
  const f = N > F ? Math.min(F, Math.ceil((uf * F) / N)) : Math.min(F, uf);
  return { f, F, y0: yAt(f - 1), y1: yAt(f), yAt };
}

/** Ячейка помещения в рамке корпуса, координаты рамки от −0.5 до 0.5 */
export function cellOf(u, B, C) {
  const E = Math.max(1, B.entr.length), n = Math.max(1, u.n), e = Math.min(E - 1, Math.max(0, u.e));
  const { y0, y1 } = floorOf(u, B, C);
  // квартиру поставили на её окна — сторона и место как есть (развороты рамки на неё не действуют)
  const m = slotOf(u, C);
  if (m) {
    const facing = m[0] < 0 ? -1 : 1, zc = facing > 0 ? [0.5, 1] : [0, 0.5];
    return { min: new THREE.Vector3(m[1] - 0.5, y0 - 0.5, zc[0] - 0.5), max: new THREE.Vector3(m[2] - 0.5, y1 - 0.5, zc[1] - 0.5), facing, floorY: [y0 - 0.5, y1 - 0.5], placed: true };
  }
  const rows = C.rows === 1 || n < 2 ? 1 : 2, front = Math.ceil(n / rows);
  let side = 'all', col = u.i, cols = n;
  if (rows === 2) {
    if (u.i < front) { side = 'front'; col = u.i; cols = front; } else { side = 'back'; cols = n - front; col = u.i - front; if (C.back !== 'fwd') col = cols - 1 - col; }
  }
  const sp = splitsOf(B, C), w = sp[e + 1] - sp[e];
  let x0 = sp[e] + (w * col) / cols, x1 = sp[e] + (w * (col + 1)) / cols;
  if (C.flipX) [x0, x1] = [1 - x1, 1 - x0];
  let z0 = 0, z1 = 1;
  if (side !== 'all') {
    const isFront = (side === 'front') !== !!C.flipZ;
    [z0, z1] = isFront ? [0.5, 1] : [0, 0.5];
  }
  const facing = side === 'all' ? (C.flipZ ? -1 : 1) : ((side === 'front') !== !!C.flipZ ? 1 : -1);
  return { min: new THREE.Vector3(x0 - 0.5, y0 - 0.5, z0 - 0.5), max: new THREE.Vector3(x1 - 0.5, y1 - 0.5, z1 - 0.5), facing, floorY: [y0 - 0.5, y1 - 0.5] };
}

/** Отрезок [a0, a1] — квартирам по их весам; окна W (середины) есть — граница посередине между окнами соседей */
function split(a0, a1, W, weights) {
  const n = weights.length, total = weights.reduce((s, w) => s + w, 0) || 1, out = [];
  if (W.length >= n) {
    // окон каждой квартире — по весу (наибольшие остатки), но не меньше одного
    const exact = weights.map((w) => (W.length * w) / total), cnt = exact.map((x) => Math.max(1, Math.floor(x)));
    let left = W.length - cnt.reduce((s, c) => s + c, 0);
    const order = exact.map((x, i) => [x - Math.floor(x), i]).sort((p, q) => q[0] - p[0]);
    for (let k = 0; left > 0; k++, left--) cnt[order[k % n][1]]++;
    while (left < 0) { const i = cnt.indexOf(Math.max(...cnt)); cnt[i]--; left++; }
    let k = 0, x = a0;
    for (let j = 0; j < n; j++) {
      const last = k + cnt[j] - 1, nx = j === n - 1 ? a1 : (W[last] + W[last + 1]) / 2;
      out.push([x, nx]); x = nx; k = last + 1;
    }
    return out;
  }
  let x = a0;
  weights.forEach((w, j) => { const nx = j === n - 1 ? a1 : x + ((a1 - a0) * w) / total; out.push([x, nx]); x = nx; });
  return out;
}

/**
 * Квартиры — на окна фасада: двухкомнатной обычно 3 окна (комнаты и кухня), то есть окон — по числу комнат + 1;
 * окон не нашли — по тому же весу. Раскладка одна на все этажи с тем же числом квартир (ключ «подъезд|квартир на этаже|номер»).
 * Группы, где человек сам поставил хоть одну квартиру (m=1), не трогаем.
 */
export function allocate(B, C, list, keepManual = true) {
  const old = keepManual ? C.slots || {} : {}, slots = {}, groups = {};
  for (const u of list) { const k = u.e + '|' + Math.max(1, u.n); ((groups[k] ??= {})[u.f] ??= []).push(u); }
  const sp = splitsOf(B, C), win = { f: (C.win && C.win.f) || [], b: (C.win && C.win.b) || [] };
  for (const [k, floors] of Object.entries(groups)) {
    const mine = Object.keys(old).filter((s) => s.startsWith(k + '|'));
    if (mine.some((s) => old[s][3])) { mine.forEach((s) => { slots[s] = old[s]; }); continue; }
    // этаж-образец: самый частый набор квартир (комнаты по порядку)
    const seqs = {};
    for (const us of Object.values(floors)) { us.sort((a, b) => a.i - b.i); (seqs[us.map((u) => (u.r || 0) + (u.k || '')).join(',')] ??= []).push(us); }
    const rep = Object.values(seqs).sort((a, b) => b.length - a.length)[0][0], n = rep.length, e = Math.min(sp.length - 2, Math.max(0, rep[0].e));
    if (n === 1 && !rep[0].r) continue;   // коммерция на весь этаж — как была, без окон
    const rows = C.rows === 1 || n < 2 ? 1 : 2, front = Math.ceil(n / rows);
    // слева направо по рамке (до разворота): лицевая — по порядку, задняя — «по кругу» в обратном порядке
    const sides = rows === 1 ? [[rep, 'f']] : [[rep.slice(0, front), 'f'], [C.back !== 'fwd' ? rep.slice(front).reverse() : rep.slice(front), 'b']];
    for (const [us, side] of sides) {
      if (!us.length) continue;
      const facing = (side === 'f') !== !!C.flipZ ? 1 : -1, raw = facing > 0 ? win.f : win.b;
      const W = raw.map((x) => (C.flipX ? 1 - x : x)).filter((x) => x > sp[e] && x < sp[e + 1]).sort((p, q) => p - q);
      const ranges = split(sp[e], sp[e + 1], W, us.map((u) => (u.r > 0 ? u.r + 1 : Math.max(2, Math.round((u.a || 40) / 25)))));
      us.forEach((u, j) => {
        let [x0, x1] = ranges[j];
        if (C.flipX) [x0, x1] = [1 - x1, 1 - x0];
        slots[`${u.e}|${n}|${u.i}`] = [facing, +x0.toFixed(4), +x1.toFixed(4)];
      });
    }
  }
  return slots;
}
/** Границы окон для разметки: середины соседних окон; края — полшага от крайних окон */
export function winEdges(cs) {
  if (!cs || cs.length < 1) return [0, 1];
  if (cs.length === 1) return [0, 1];
  const e = [Math.max(0, cs[0] - (cs[1] - cs[0]) / 2)];
  for (let i = 0; i < cs.length - 1; i++) e.push((cs[i] + cs[i + 1]) / 2);
  e.push(Math.min(1, cs[cs.length - 1] + (cs[cs.length - 1] - cs[cs.length - 2]) / 2));
  return e;
}

export async function start(el, cfg) {
  const T = cfg.T || {};
  const accent = css('accent'), accent2 = css('accent-2'), amber = '#f59e0b';
  const units = cfg.data.units, blocks = cfg.data.blocks;
  const byId = Object.fromEntries(units.map((u) => [u.id, u]));
  const cal = JSON.parse(JSON.stringify(cfg.cal || {}));
  const saved = new Set(Object.keys(cfg.cal || {}));

  // ---------- сцена ----------
  // логарифмический буфер глубины: и квартал на километры, и окно квартиры вблизи без мерцания
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, logarithmicDepthBuffer: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;
  el.appendChild(renderer.domElement);
  const canvas = renderer.domElement;
  const labels = new CSS2DRenderer();
  labels.domElement.className = 'lc-labels';
  el.appendChild(labels.domElement);
  const scene = new THREE.Scene();
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8a93a6, 0.55));
  const sun = new THREE.DirectionalLight(0xffffff, 1.4); sun.position.set(1, 2, 1.2); scene.add(sun);
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 10000);
  const controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true; controls.dampingFactor = 0.08; controls.maxPolarAngle = Math.PI * 0.495;

  const lineMats = [];   // толстые линии считают толщину в пикселях — им нужен размер окна
  function resize() {
    const w = el.clientWidth || 1, h = el.clientHeight || 1;
    lineMats.forEach((m) => m.resolution.set(w, h));
    renderer.setSize(w, h); labels.setSize(w, h); camera.aspect = w / h; camera.updateProjectionMatrix();
  }
  new ResizeObserver(resize).observe(el); resize();

  // ---------- модель ----------
  const loader = new GLTFLoader();
  const draco = new DRACOLoader(); draco.setDecoderPath('https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/libs/draco/gltf/');
  loader.setDRACOLoader(draco); loader.setMeshoptDecoder(MeshoptDecoder);
  const gltf = await new Promise((ok, bad) => loader.load(cfg.src, ok, (e) => cfg.onProgress && e.total && cfg.onProgress(e.loaded / e.total), bad));
  const model = gltf.scene;
  scene.add(model);
  const mBox = new THREE.Box3().setFromObject(model), mSize = mBox.getSize(new THREE.Vector3()), mCenter = mBox.getCenter(new THREE.Vector3());
  const D = Math.max(mSize.x, mSize.y, mSize.z) || 10;
  camera.near = Math.max(0.01, D / 100000); camera.far = D * 30; camera.updateProjectionMatrix();
  controls.maxDistance = D * 4; controls.minDistance = D / 400;

  // ---------- рамка корпуса ----------
  const frame = new THREE.Group(); scene.add(frame);
  const unitBox = new THREE.BoxGeometry(1, 1, 1), unitEdges = new THREE.EdgesGeometry(unitBox);
  const frameLines = new THREE.LineSegments(unitEdges, new THREE.LineBasicMaterial({ color: accent, transparent: true, opacity: 0.9, depthTest: false }));
  frameLines.renderOrder = 20; frameLines.visible = !!cfg.calibrate; frame.add(frameLines);
  const layer = new THREE.Group(); frame.add(layer);   // ячейки, подсветка, подписи
  const overlay = new THREE.Group(); scene.add(overlay);   // контуры на стене — в мировых координатах (рамка растянута неравномерно)

  const blockIds = Object.keys(blocks);
  let curBlock = null, selected = null;
  // пока рамки нет: где дом в файле. Если в модели целый квартал (дороги, соседние дома, кран), ищем самое высокое и «толстое» здание —
  // не землю (огромная площадь) и не кран (высокий, но тонкий); к нему добавляем детали в его же пятне
  const auto = (() => {
    const boxes = [];
    model.updateMatrixWorld(true);
    model.traverse((o) => { if (o.isMesh && o.geometry) { const b = new THREE.Box3().setFromObject(o); if (!b.isEmpty()) boxes.push(b); } });
    const mArea = Math.max(1e-6, mSize.x * mSize.z);
    let best = null, bestScore = 0;
    boxes.forEach((b) => {
      const s = b.getSize(new THREE.Vector3()), area = s.x * s.z;
      if (area > mArea * 0.35 || s.y < mSize.y * 0.08 || Math.min(s.x, s.z) < s.y * 0.12) return;
      const score = s.y * Math.sqrt(area);
      if (score > bestScore) { bestScore = score; best = b; }
    });
    if (!best || boxes.length < 2) return mBox.clone();
    const seed = best.clone(), pad = seed.getSize(new THREE.Vector3()).multiplyScalar(0.08), zone = seed.clone().expandByVector(new THREE.Vector3(pad.x, 0, pad.z));
    const out = seed.clone(), seedArea = (seed.max.x - seed.min.x) * (seed.max.z - seed.min.z);
    boxes.forEach((b) => {
      const c = b.getCenter(new THREE.Vector3()), s = b.getSize(new THREE.Vector3());
      if (s.x * s.z <= seedArea * 1.5 && c.x >= zone.min.x && c.x <= zone.max.x && c.z >= zone.min.z && c.z <= zone.max.z) out.union(b);
    });
    return out;
  })();
  function defaults(bid) {
    const c = auto.getCenter(new THREE.Vector3()), s = auto.getSize(new THREE.Vector3());
    return { c: [c.x, c.y, c.z], s: [s.x || 1, s.y || 1, s.z || 1], r: 0, floors: blocks[bid].floors, gf: 0, rows: 2, back: 'rev', flipX: false, flipZ: false };
  }
  const C = (bid) => (cal[bid] = cal[bid] || defaults(bid));
  const isCalibrated = (bid) => saved.has(bid);
  const floorsOf = (bid) => Math.max(1, C(bid).floors || blocks[bid].floors);
  function applyFrame(bid) {
    const c = C(bid);
    frame.position.set(c.c[0], c.c[1], c.c[2]); frame.rotation.set(0, c.r, 0); frame.scale.set(c.s[0], c.s[1], c.s[2]);
    frame.updateMatrixWorld(true);
  }
  function readFrame(bid) {
    const c = C(bid);
    c.c = frame.position.toArray().map((v) => +v.toFixed(4)); c.s = frame.scale.toArray().map((v) => Math.max(0.01, Math.abs(+v.toFixed(4)))); c.r = +frame.rotation.y.toFixed(5);
  }
  // насколько подсветка выступает за фасад: от размера корпуса, а не всей сцены (в квартале на километры она была бы с этаж толщиной)
  const growOf = () => Math.min(0.6, Math.max(0.1, Math.min(frame.scale.x, frame.scale.y, frame.scale.z) * 0.02));
  const clear = () => {
    for (const g of [layer, overlay]) while (g.children.length) { const o = g.children.pop(); if (o.element) o.element.remove(); }
    lineMats.length = 0;
  };
  /** Толстая линия по точкам (мир), поверх модели. dashed — пунктир */
  function thick(points, color, width, dashed = false) {
    const g = new LineGeometry(); g.setPositions(points.flatMap((p) => [p.x, p.y, p.z]));
    const m = new LineMaterial({ color, linewidth: width, transparent: true, depthTest: false, dashed, dashSize: 1.2, gapSize: 0.8 });
    m.resolution.set(el.clientWidth || 1, el.clientHeight || 1); lineMats.push(m);
    const l = new Line2(g, m); if (dashed) l.computeLineDistances(); l.renderOrder = 30; overlay.add(l);
    return l;
  }
  /** Точки на стене рамки (координаты рамки) → мир, чуть впереди стены */
  function onWall(pts, facing) {
    const z = facing > 0 ? 0.5 : -0.5, off = (growOf() * 1.6 / frame.scale.z) * facing;
    return pts.map(([x, y]) => frame.localToWorld(new THREE.Vector3(x, y, z + off)));
  }
  const wallRect = (x0, y0, x1, y1, facing) => onWall([[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]], facing);
  function boxMesh(min, max, mat, grow = 0) {
    const m = new THREE.Mesh(unitBox, mat);
    const size = max.clone().sub(min), g = new THREE.Vector3(grow / frame.scale.x, grow / frame.scale.y, grow / frame.scale.z);
    m.scale.copy(size.add(g.clone().multiplyScalar(2))); m.position.copy(min.clone().add(max).multiplyScalar(0.5));
    return m;
  }
  function edges(min, max, color, opacity, grow = 0) {
    const l = new THREE.LineSegments(unitEdges, new THREE.LineBasicMaterial({ color, transparent: true, opacity, depthTest: false }));
    const size = max.clone().sub(min), g = new THREE.Vector3(grow / frame.scale.x, grow / frame.scale.y, grow / frame.scale.z);
    l.scale.copy(size.add(g.multiplyScalar(2))); l.position.copy(min.clone().add(max).multiplyScalar(0.5)); l.renderOrder = 21;
    return l;
  }
  const entrName = (B, e) => (B.name && blockIds.length > 1 ? B.name + ' · ' : '') + (B.entr[e] || e + 1) + '-' + (T.en || '');
  /** Подпись подъезда у земли, посередине его участка фасада */
  function entrLabel(B, c, e, facing, parent) {
    const sp = splitsOf(B, c);
    let x0 = sp[e], x1 = sp[e + 1];
    if (c.flipX) [x0, x1] = [1 - x1, 1 - x0];
    const div = document.createElement('div'); div.className = 'lc-entr'; div.textContent = entrName(B, e);
    const o = new CSS2DObject(div);
    o.position.copy(onWall([[(x0 + x1) / 2 - 0.5, -0.5]], facing)[0]);
    o.center.set(0.5, -0.2);
    parent.add(o);
    return [x0, x1];
  }

  // ---------- показ квартиры ----------
  const pulse = [];
  function showUnit(id, fly = true) {
    const u = byId[id]; if (!u) return null;
    clearStatus();
    if (curBlock !== u.b || cfg.calibrate) { curBlock = u.b; applyFrame(u.b); }
    clear(); pulse.length = 0; selected = u;
    const B = blocks[u.b], c = C(u.b), cell = cellOf(u, B, c);
    const grow = growOf();
    // весь этаж — тонкий контур, чтобы видеть уровень
    layer.add(edges(new THREE.Vector3(-0.5, cell.floorY[0], -0.5), new THREE.Vector3(0.5, cell.floorY[1], 0.5), accent2, 0.45, grow * 0.5));
    // квартира — светящийся объём поверх модели (виден с любой стороны)
    const fill = boxMesh(cell.min, cell.max, new THREE.MeshBasicMaterial({ color: accent, transparent: true, opacity: 0.42, depthTest: false, depthWrite: false }), grow);
    fill.renderOrder = 19; layer.add(fill); pulse.push(fill.material);
    // контур квартиры на стене — толстая линия; подъезд — пунктир во всю высоту и подпись у земли: видно, в каком подъезде квартира
    thick(wallRect(cell.min.x, cell.min.y, cell.max.x, cell.max.y, cell.facing), accent, 5);
    if (B.entr.length > 1 || blockIds.length > 1) {
      const [ex0, ex1] = entrLabel(B, c, Math.min(B.entr.length - 1, u.e), cell.facing, overlay);
      thick(wallRect(ex0 - 0.5, -0.5, ex1 - 0.5, 0.5, cell.facing), accent2, 2.5, true);
    }
    // подпись над квартирой
    const div = document.createElement('div');
    div.className = 'lc-pin';
    div.innerHTML = `<b>${esc(u.label || u.num)}</b><span>${esc(u.sub || '')}</span>`;
    const pin = new CSS2DObject(div);
    pin.position.set((cell.min.x + cell.max.x) / 2, cell.max.y, (cell.min.z + cell.max.z) / 2);
    pin.center.set(0.5, 1.9);
    layer.add(pin);
    if (fly) focus(cell);
    return u;
  }
  function worldOf(v) { return frame.localToWorld(v.clone()); }
  function focus(cell) {
    const ctr = worldOf(cell.min.clone().add(cell.max).multiplyScalar(0.5));
    const normal = new THREE.Vector3(0, 0, cell.facing).applyQuaternion(frame.quaternion).normalize();
    const L = Math.max(frame.scale.x, frame.scale.y);
    const dist = Math.max(L * 1.4, 14);   // весь фасад корпуса в кадре, квартира в центре
    const to = ctr.clone().add(normal.multiplyScalar(dist)).add(new THREE.Vector3(0, dist * 0.28, 0));
    tween(to, ctr);
  }
  function overview() {
    const ctr = frame.getWorldPosition(new THREE.Vector3());
    const L = Math.max(frame.scale.x, frame.scale.y, frame.scale.z), dir = new THREE.Vector3(0.9, 0.55, 1.1).normalize();
    tween(ctr.clone().add(dir.multiplyScalar(L * 2.1)), ctr);
  }
  function overviewModel() {
    const dir = new THREE.Vector3(0.9, 0.6, 1.1).normalize();
    camera.position.copy(mCenter.clone().add(dir.multiplyScalar(D * 1.1))); controls.target.copy(mCenter); controls.update();
  }
  /** Ровно спереди, сбоку или сверху на рамку — так удобнее ставить её по окнам и углам */
  function view(name) {
    if (curBlock == null) return;
    if (name === '3d') { overview(); return; }
    const c = C(curBlock), ctr = frame.getWorldPosition(new THREE.Vector3());
    const ax = new THREE.Vector3(1, 0, 0).applyQuaternion(frame.quaternion), az = new THREE.Vector3(0, 0, c.flipZ ? -1 : 1).applyQuaternion(frame.quaternion);
    // справа панель настройки: рамку ставим в середину свободной части экрана
    const W = el.clientWidth || 1, Hh = el.clientHeight || 1, side = cfg.calibrate && W > 760 ? 360 : 0, aspect = (W - side) / Hh;
    const t = Math.tan((camera.fov * Math.PI) / 360), fit = (w, h) => Math.max(h / 2 / t, w / 2 / (t * aspect)) * 1.3;
    const [L, H, Dp] = c.s;
    let dir, dist;
    if (name === 'front') { dir = az; dist = fit(L, H) + Dp / 2; }
    else if (name === 'side') { dir = ax; dist = fit(Dp, H) + L / 2; }
    else { dir = new THREE.Vector3(0, 1, 0).addScaledVector(az, 0.002).normalize(); dist = fit(L, Dp) + H / 2; }
    const right = new THREE.Vector3().crossVectors(new THREE.Vector3(0, 1, 0), dir).normalize();
    if (name === 'top') right.crossVectors(new THREE.Vector3(0, 1, 0), az).normalize();
    const shift = right.multiplyScalar(((side / 2) * (2 * dist * t)) / Hh), target = ctr.clone().add(shift);
    tween(target.clone().addScaledVector(dir, dist), target, 700);
  }
  let anim = null;
  function tween(pos, target, ms = 1100) {
    anim = { p0: camera.position.clone(), t0: controls.target.clone(), p1: pos, t1: target, start: performance.now(), ms };
  }

  // ---------- настройка рамки ----------
  let tc = null;
  const colors = [accent, accent2];
  function drawGrid(bid) {
    if (mark.on) return drawMark(bid);
    clear(); pulse.length = 0;
    const B = blocks[bid], c = C(bid), F = floorsOf(bid);
    const grow = growOf() * 0.3;
    units.filter((u) => u.b === bid).forEach((u) => {
      const cell = cellOf(u, B, c);
      // плитка на наружной грани (лицевой или задней) с номером
      const min = cell.min.clone(), max = cell.max.clone();
      if (cell.facing > 0) min.z = max.z - 0.002; else max.z = min.z + 0.002;
      const tile = boxMesh(min, max, new THREE.MeshBasicMaterial({ color: colors[u.e % 2], transparent: true, opacity: 0.22, depthWrite: false, side: THREE.DoubleSide }), grow);
      layer.add(tile);
      layer.add(edges(cell.min, cell.max, colors[u.e % 2], 0.35));
      const lab = document.createElement('div'); lab.className = 'lc-num'; lab.textContent = u.num;
      const o = new CSS2DObject(lab); o.position.set((min.x + max.x) / 2, (min.y + max.y) / 2, cell.facing > 0 ? max.z : min.z); o.userData.facing = cell.facing; layer.add(o);
    });
    // лицевая сторона: подписи подъездов у земли, их границы — синие линии, верх 1-го этажа — оранжевая
    const facing = c.flipZ ? -1 : 1, sp = splitsOf(B, c);
    for (let e = 0; e < B.entr.length; e++) entrLabel(B, c, e, facing, overlay);
    for (let k = 1; k < sp.length - 1; k++) { const x = (c.flipX ? 1 - sp[k] : sp[k]) - 0.5; thick(onWall([[x, -0.5], [x, 0.5]], facing), accent2, 3); }
    if (F > 1) { const y = groundOf(c, F) - 0.5; thick(onWall([[-0.5, y], [0.5, y]], facing), amber, 2.5); }
    buildHandles();
  }

  // ---------- разметка квартир вручную: щелчок по фасаду — выбрать квартиру, протянуть по окнам — поставить её на эти окна ----------
  const mark = { on: false, sel: null, all: true, paint: null };
  const MARKC = ['#6c4cf1', '#0ea5e9', '#f59e0b', '#10b981', '#ef4444', '#ec4899', '#14b8a6', '#8b5cf6'];
  const unitsOf = (bid) => units.filter((u) => u.b === bid);
  const uniform = (n) => Array.from({ length: Math.max(1, n) }, (_, k) => +((k + 0.5) / Math.max(1, n)).toFixed(4));
  /** Окна стороны рамки (±1): найденные на фасаде, иначе — поровну, столько, сколько обычно у квартир этой стороны */
  function winOf(c, facing) {
    const cs = c.win && c.win[facing > 0 ? 'f' : 'b'];
    return cs && cs.length > 1 ? cs : uniform(guessWins(c, facing));
  }
  // по самому частому этажу каждого подъезда: у квартиры окон — комнат + 1 (двухкомнатная — 3 окна)
  function guessWins(c, facing) {
    const byE = {};
    unitsOf(curBlock).forEach((u) => { ((byE[u.e] ??= {})[u.f] ??= []).push(u); });
    let total = 0;
    for (const floors of Object.values(byE)) {
      const fl = Object.values(floors).sort((a, b) => b.length - a.length)[0].sort((a, b) => a.i - b.i);
      const n = fl.length, rows = c.rows === 1 || n < 2 ? 1 : 2, front = Math.ceil(n / rows);
      const side = rows === 1 ? fl : (facing > 0) !== !!c.flipZ ? fl.slice(0, front) : fl.slice(front);
      total += side.reduce((s, u) => s + (u.r > 0 ? u.r + 1 : 2), 0);
    }
    return Math.max(2, total);
  }
  const gridMat = new THREE.LineBasicMaterial({ color: 0x1f1d36, transparent: true, opacity: 0.45 });
  const prevMat = new THREE.MeshBasicMaterial({ color: accent, transparent: true, opacity: 0.55, depthWrite: false, side: THREE.DoubleSide });
  const preview = new THREE.Mesh(unitBox, prevMat); preview.visible = false; preview.renderOrder = 25; frame.add(preview);
  function drawMark(bid) {
    clear(); pulse.length = 0;
    const B = blocks[bid], c = C(bid), list = unitsOf(bid), { F, yAt } = floorOf(list[0] || { f: 1 }, B, c);
    const off = 0.004;
    // сетка окон и этажей на обеих сторонах — чуть впереди стены, дом её закрывает с обратной стороны
    for (const facing of [1, -1]) {
      const z = (0.5 + off) * facing, pts = [], ex = winEdges(winOf(c, facing));
      ex.forEach((x) => pts.push(x - 0.5, -0.5, z, x - 0.5, 0.5, z));
      for (let k = 0; k <= F; k++) { const y = yAt(k) - 0.5; pts.push(-0.5, y, z, 0.5, y, z); }
      const g = new THREE.BufferGeometry(); g.setAttribute('position', new THREE.Float32BufferAttribute(pts, 3));
      const ls = new THREE.LineSegments(g, gridMat); ls.renderOrder = 22; layer.add(ls);
    }
    const sel = mark.sel && byId[mark.sel];
    const seen = new Set();
    list.forEach((u) => {
      const cell = cellOf(u, B, c), min = cell.min.clone(), max = cell.max.clone(), on = sel && u.id === sel.id;
      if (cell.facing > 0) { min.z = 0.5 + off; max.z = 0.5 + off * 2; } else { max.z = -0.5 - off; min.z = -0.5 - off * 2; }
      const pad = (max.x - min.x) * 0.04;
      min.x += pad; max.x -= pad;
      const col = on ? accent : MARKC[u.i % MARKC.length];
      const m = new THREE.Mesh(unitBox, new THREE.MeshBasicMaterial({ color: col, transparent: true, opacity: on ? 0.62 : cell.placed ? 0.3 : 0.16, depthWrite: false, side: THREE.DoubleSide }));
      m.scale.copy(max.clone().sub(min)); m.position.copy(min.clone().add(max).multiplyScalar(0.5)); m.renderOrder = on ? 24 : 23;
      layer.add(m);
      if (on) { pulse.push(m.material); thick(wallRect(cell.min.x, cell.min.y, cell.max.x, cell.max.y, cell.facing), accent, 5); }
      // в модели этажей меньше: на одном месте несколько квартир — подписываем одну, выбранную или нижнюю
      const key = cell.facing + '|' + cell.min.x.toFixed(3) + '|' + cell.min.y.toFixed(3);
      if (seen.has(key) && !on) return;
      seen.add(key);
      const lab = document.createElement('div'); lab.className = 'lc-num' + (on ? ' on' : ''); lab.textContent = u.num;
      const o = new CSS2DObject(lab); o.position.set((min.x + max.x) / 2, (min.y + max.y) / 2, cell.facing > 0 ? max.z : min.z); o.userData.facing = cell.facing; layer.add(o);
    });
    const facing = c.flipZ ? -1 : 1;
    for (let e = 0; e < B.entr.length; e++) entrLabel(B, c, e, facing, overlay);
  }
  /** Точка на лицевой или задней стене рамки под курсором (та, что смотрит на камеру): сторона, доли длины и высоты */
  function facadeHit(e) {
    setRay(e);
    let best = null;
    for (const facing of [1, -1]) {
      const n = new THREE.Vector3(0, 0, facing).applyQuaternion(frame.quaternion), p = frame.localToWorld(new THREE.Vector3(0, 0, 0.5 * facing));
      if (n.dot(camera.position.clone().sub(p)) <= 0) continue;
      const hit = ray.ray.intersectPlane(new THREE.Plane().setFromNormalAndCoplanarPoint(n, p), new THREE.Vector3());
      if (!hit) continue;
      const l = frame.worldToLocal(hit.clone());
      if (Math.abs(l.x) > 0.5 || Math.abs(l.y) > 0.5) continue;
      const d = hit.distanceTo(camera.position);
      if (!best || d < best.d) best = { facing, x: l.x + 0.5, y: l.y + 0.5, d };
    }
    return best;
  }
  /** Квартира в этой точке фасада (нижняя из совпавших; выбранная — первой) */
  function unitAt(h) {
    const B = blocks[curBlock], c = C(curBlock);
    const hits = unitsOf(curBlock).filter((u) => {
      const cell = cellOf(u, B, c);
      return cell.facing === h.facing && h.x >= cell.min.x + 0.5 && h.x <= cell.max.x + 0.5 && h.y >= cell.floorY[0] + 0.5 && h.y <= cell.floorY[1] + 0.5;
    });
    return hits.find((u) => u.id === mark.sel) || hits.sort((a, b) => a.f - b.f)[0] || null;
  }
  /** Отрезок окон, которые протянули мышью: от левого края первого окна до правого края последнего */
  function cellsRange(facing, xa, xb) {
    const ex = winEdges(winOf(C(curBlock), facing));
    const at = (x) => { for (let i = 0; i < ex.length - 2; i++) if (x <= ex[i + 1]) return i; return ex.length - 2; };
    const a = at(Math.min(xa, xb)), b = at(Math.max(xa, xb));
    return [ex[a], ex[b + 1]];
  }
  function showPreview(facing, x0, x1) {
    const u = byId[mark.sel];
    const fy = u ? floorOf(u, blocks[curBlock], C(curBlock)) : { y0: 0, y1: 1 };
    const z = facing > 0 ? 0.5 + 0.008 : -0.5 - 0.008;
    preview.scale.set(x1 - x0, fy.y1 - fy.y0, 0.004); preview.position.set((x0 + x1) / 2 - 0.5, (fy.y0 + fy.y1) / 2 - 0.5, z);
    preview.visible = true;
  }
  function relayout(bid) { const c = C(bid); if (c.slots || c.win) c.slots = allocate(blocks[bid], c, unitsOf(bid), true); }
  function assign(id, facing, x0, x1) {
    const u = byId[id]; if (!u) return;
    const before = snap(), c = C(curBlock);
    if (mark.all) {
      if (!c.slots) c.slots = allocate(blocks[curBlock], c, unitsOf(curBlock), true);
      c.slots[`${u.e}|${Math.max(1, u.n)}|${u.i}`] = [facing, +x0.toFixed(4), +x1.toFixed(4), 1];
      if (c.ux) delete c.ux[u.id];
    } else (c.ux ??= {})[u.id] = [facing, +x0.toFixed(4), +x1.toFixed(4)];
    changed(); remember(before);
    cfg.onAssign && cfg.onAssign(u);
  }
  canvas.addEventListener('pointerdown', (e) => {
    if (!mark.on || e.button !== 0) return;
    const h = facadeHit(e);
    if (!h) return;   // мимо дома — крутим камеру как обычно
    e.stopImmediatePropagation(); e.preventDefault();
    mark.paint = { h, cur: h, at: [e.clientX, e.clientY], moved: false, id: e.pointerId };
    controls.enabled = false;
    canvas.setPointerCapture(e.pointerId);
  }, { capture: true });
  canvas.addEventListener('pointermove', (e) => {
    if (!mark.on) return;
    const P = mark.paint;
    if (!P) { canvas.style.cursor = facadeHit(e) ? 'crosshair' : ''; return; }
    const h = facadeHit(e);
    if (Math.hypot(e.clientX - P.at[0], e.clientY - P.at[1]) > 6) P.moved = true;
    if (!h || h.facing !== P.h.facing || !P.moved || !mark.sel) return;
    P.cur = h;
    const [x0, x1] = cellsRange(h.facing, P.h.x, h.x);
    showPreview(h.facing, x0, x1);
  });
  const endPaint = () => {
    const P = mark.paint;
    if (!P) return;
    mark.paint = null; preview.visible = false; controls.enabled = true;
    try { canvas.releasePointerCapture(P.id); } catch (e) { /* уже отпущен */ }
    if (!P.moved || !mark.sel) {
      // щелчок — выбрать квартиру в этом месте
      const u = unitAt(P.h);
      if (u) { mark.sel = u.id; drawMark(curBlock); cfg.onSelect && cfg.onSelect(u); }
      return;
    }
    const [x0, x1] = cellsRange(P.h.facing, P.h.x, P.cur.x);
    assign(mark.sel, P.h.facing, x0, x1);
  };
  canvas.addEventListener('pointerup', endPaint);
  canvas.addEventListener('pointercancel', endPaint);

  // ---------- точки на рамке: тянуть сторону, границу подъезда, верх 1-го этажа ----------
  const handles = new THREE.Group(); handles.visible = false; scene.add(handles);
  const hGeo = new THREE.SphereGeometry(1, 20, 14), hMats = {};
  const hMat = (col) => (hMats[col] ??= new THREE.MeshBasicMaterial({ color: col, depthTest: false, transparent: true, opacity: 0.96 }));
  const hRing = new THREE.MeshBasicMaterial({ color: 0xffffff, depthTest: false });
  function buildHandles() {
    while (handles.children.length) handles.remove(handles.children[0]);
    if (!cfg.calibrate || curBlock == null) return;
    const c = C(curBlock), B = blocks[curBlock], F = floorsOf(curBlock), fz = c.flipZ ? -0.5 : 0.5;
    const add = (kind, local, data, col) => {
      const m = new THREE.Mesh(hGeo, hMat(col)); m.renderOrder = 51;
      const ring = new THREE.Mesh(hGeo, hRing); ring.scale.setScalar(1.35); ring.renderOrder = 50; m.add(ring);   // белая обводка — точку видно на любом фоне
      m.userData = { kind, local: new THREE.Vector3(...local), ...data };
      handles.add(m);
    };
    [[0, 1], [0, -1], [1, 1], [1, -1], [2, 1], [2, -1]].forEach(([axis, sign]) => { const l = [0, 0, 0]; l[axis] = 0.5 * sign; add('face', l, { axis, sign }, accent); });
    const sp = splitsOf(B, c);
    for (let k = 1; k < sp.length - 1; k++) add('entr', [(c.flipX ? 1 - sp[k] : sp[k]) - 0.5, 0.5, fz], { k }, accent2);
    if (F > 1) add('gf', [-0.5, groundOf(c, F) - 0.5, fz], {}, amber);
    placeHandles();
  }
  const _w = new THREE.Vector3();
  function placeHandles() {
    if (!handles.children.length) return;
    const k = (2 * Math.tan((camera.fov * Math.PI) / 360)) / Math.max(1, el.clientHeight);
    for (const h of handles.children) {
      h.position.copy(frame.localToWorld(_w.copy(h.userData.local)));
      h.scale.setScalar(camera.position.distanceTo(h.position) * k * (h === hoverH ? 9 : 7));
    }
  }
  let hoverH = null, drag = null, dirty = false;
  const ray = new THREE.Raycaster(), ptr = new THREE.Vector2();
  const setRay = (e) => {
    const r = canvas.getBoundingClientRect();
    ptr.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    ray.setFromCamera(ptr, camera);
  };
  const hitHandle = () => (handles.visible && handles.children.length ? ray.intersectObjects(handles.children, false)[0]?.object || null : null);

  // ---------- отмена шагов ----------
  const undoStack = [];
  const snap = () => JSON.stringify(C(curBlock));
  function remember(before, bid = curBlock) {
    if (before && before !== JSON.stringify(C(bid))) { undoStack.push({ bid, s: before }); if (undoStack.length > 80) undoStack.shift(); }
    cfg.onUndo && cfg.onUndo(undoStack.length);
  }
  function changed() { applyFrame(curBlock); drawGrid(curBlock); cfg.onChange && cfg.onChange(C(curBlock)); }

  canvas.addEventListener('pointerdown', (e) => {
    if (!cfg.calibrate || e.button !== 0) return;
    setRay(e);
    const h = hitHandle();
    if (!h) return;
    // точку держим мы: вращение камеры и стрелки рамки в это время молчат
    e.stopImmediatePropagation(); e.preventDefault();
    const u = h.userData, plane = new THREE.Plane();
    let axisW = null;
    if (u.kind === 'face') {
      axisW = new THREE.Vector3().setComponent(u.axis, 1).applyQuaternion(frame.quaternion).normalize();
      const cd = camera.getWorldDirection(new THREE.Vector3()), n = cd.clone().addScaledVector(axisW, -cd.dot(axisW));
      if (n.lengthSq() < 1e-8) n.set(0, 1, 0).addScaledVector(axisW, -axisW.y);
      plane.setFromNormalAndCoplanarPoint(n.normalize(), h.position);
    } else plane.setFromNormalAndCoplanarPoint(new THREE.Vector3(0, 0, 1).applyQuaternion(frame.quaternion), h.position);
    const p0 = ray.ray.intersectPlane(plane, new THREE.Vector3());
    if (!p0) return;
    const c = C(curBlock);
    drag = { u, plane, axisW, p0, c0: c.c.slice(), s0: c.s.slice(), before: snap(), id: e.pointerId };
    controls.enabled = false; if (tc) tc.enabled = false;
    canvas.setPointerCapture(e.pointerId);
    canvas.style.cursor = 'grabbing';
  }, { capture: true });
  canvas.addEventListener('pointermove', (e) => {
    if (!cfg.calibrate) return;
    setRay(e);
    if (!drag) {
      if (e.buttons) return;
      const h = hitHandle();
      if (h !== hoverH) { hoverH = h; canvas.style.cursor = h ? 'grab' : ''; }
      return;
    }
    const p = ray.ray.intersectPlane(drag.plane, new THREE.Vector3());
    if (!p) return;
    const c = C(curBlock), u = drag.u;
    if (u.kind === 'face') {
      // сторона едет за мышью, противоположная стоит на месте
      const t = p.clone().sub(drag.p0).dot(drag.axisW), s0 = drag.s0[u.axis];
      const sN = Math.max(s0 * 0.03 + 0.05, s0 + t * u.sign), d = ((sN - s0) / 2) * u.sign;
      c.s = drag.s0.slice(); c.s[u.axis] = +sN.toFixed(4);
      c.c = drag.c0.map((v, i) => +(v + drag.axisW.getComponent(i) * d).toFixed(4));
    } else {
      const l = frame.worldToLocal(p.clone());
      if (u.kind === 'entr') {
        const B = blocks[curBlock], sp = splitsOf(B, c);
        let x = clamp(l.x + 0.5, 0, 1); if (c.flipX) x = 1 - x;
        sp[u.k] = clamp(x, sp[u.k - 1] + 0.03, sp[u.k + 1] - 0.03);
        c.ex = sp.slice(1, -1).map((v) => +v.toFixed(4));
      } else if (u.kind === 'gf') {
        const F = floorsOf(curBlock), g = clamp(l.y + 0.5, 0.35 / F, 0.6);
        c.gf = +(g * c.s[1]).toFixed(2);
      }
    }
    dirty = true;
  });
  const endDrag = () => {
    if (!drag) return;
    try { canvas.releasePointerCapture(drag.id); } catch (e) { /* уже отпущен */ }
    const before = drag.before, kind = drag.u.kind; drag = null; dirty = false;
    controls.enabled = true; if (tc) tc.enabled = true;
    canvas.style.cursor = hoverH ? 'grab' : '';
    if (kind === 'entr') relayout(curBlock);   // подъезд стал шире — его квартиры заново по окнам
    changed(); remember(before);
  };
  canvas.addEventListener('pointerup', endDrag);
  canvas.addEventListener('pointercancel', endDrag);

  function setCalibrate(on) {
    if (on && !tc) {
      tc = new TransformControls(camera, canvas);
      let before = null;
      tc.addEventListener('dragging-changed', (e) => {
        controls.enabled = !e.value;
        if (e.value) { before = snap(); return; }
        readFrame(curBlock); changed(); remember(before);
      });
      tc.addEventListener('objectChange', () => { if (tc.getMode() === 'rotate') { frame.rotation.x = 0; frame.rotation.z = 0; } placeHandles(); });
      scene.add(tc.getHelper ? tc.getHelper() : tc);
      tc.attach(frame);
      mode('translate');
      handles.visible = true;
    }
  }
  function mode(m) {
    if (!tc) return;
    tc.setMode(m); tc.setSpace('local');
    tc.showX = m !== 'rotate'; tc.showZ = m !== 'rotate'; tc.showY = true;
  }

  // ---------- весь дом: квартиры цветом статуса прямо на фасаде (3D-шахматка) ----------
  const ST_COL = { free: '#22c55e', booked: '#f59e0b', sold: '#ef4444', reserve: '#94a3b8' };
  const statusGroup = new THREE.Group(); scene.add(statusGroup);
  const tileMats = {};
  const tileMat = (st, hover) => (tileMats[st + hover] ??= new THREE.MeshBasicMaterial({ color: ST_COL[st] || ST_COL.reserve, transparent: true, opacity: hover ? 0.85 : 0.5, depthWrite: false, side: THREE.DoubleSide }));
  let hovered = null;
  function clearStatus() { while (statusGroup.children.length) statusGroup.remove(statusGroup.children[0]); hovered = null; }
  /** Плитки всех помещений на наружных стенах корпусов; клик по плитке — квартира */
  function statusView() {
    clear(); clearStatus();
    const box = new THREE.Box3();
    blockIds.forEach((bid) => {
      const c = C(bid), g = new THREE.Group();
      g.position.set(c.c[0], c.c[1], c.c[2]); g.rotation.set(0, c.r, 0); g.scale.set(c.s[0], c.s[1], c.s[2]);
      statusGroup.add(g);
      const B = blocks[bid];
      units.filter((u) => u.b === bid).forEach((u) => {
        const cell = cellOf(u, B, c), min = cell.min.clone(), max = cell.max.clone();
        const pad = 0.12;   // зазор между плитками — видно, где кончается одна квартира и начинается другая
        const dx = (max.x - min.x) * pad / 2, dy = (max.y - min.y) * pad / 2;
        min.x += dx; max.x -= dx; min.y += dy; max.y -= dy;
        const out = 0.004 + (growOf() / c.s[2]);
        if (cell.facing > 0) { min.z = 0.5 + out * 0.5; max.z = 0.5 + out; } else { max.z = -0.5 - out * 0.5; min.z = -0.5 - out; }
        const m = new THREE.Mesh(unitBox, tileMat(u.s || 'free', false));
        m.scale.copy(max.clone().sub(min)); m.position.copy(min.clone().add(max).multiplyScalar(0.5));
        m.userData.unit = u.id; m.renderOrder = 12;
        g.add(m);
      });
      g.updateMatrixWorld(true);
      box.union(new THREE.Box3().setFromObject(g));
    });
    if (!box.isEmpty()) {
      const ctr = box.getCenter(new THREE.Vector3()), size = box.getSize(new THREE.Vector3()), L = Math.max(size.x, size.y, size.z);
      tween(ctr.clone().add(new THREE.Vector3(0.9, 0.55, 1.1).normalize().multiplyScalar(L * 1.6)), ctr);
    }
    selected = null;
  }
  const pickTile = (e) => { setRay(e); return ray.intersectObject(statusGroup, true)[0]?.object || null; };
  canvas.addEventListener('pointermove', (e) => {
    if (!statusGroup.children.length || e.buttons) return;
    const o = pickTile(e);
    if (o === hovered) return;
    if (hovered) hovered.material = tileMat(byId[hovered.userData.unit]?.s || 'free', false);
    hovered = o;
    canvas.style.cursor = o ? 'pointer' : '';
    if (o) { o.material = tileMat(byId[o.userData.unit]?.s || 'free', true); cfg.onHover && cfg.onHover(byId[o.userData.unit]); }
  });
  let downAt = null;
  canvas.addEventListener('pointerdown', (e) => { downAt = [e.clientX, e.clientY]; });
  canvas.addEventListener('pointerup', (e) => {
    if (!statusGroup.children.length || !downAt || Math.hypot(e.clientX - downAt[0], e.clientY - downAt[1]) > 6) return;   // это было вращение, а не клик
    const o = pickTile(e);
    if (o && cfg.onPick) cfg.onPick(byId[o.userData.unit]);
  });

  // ---------- двойной щелчок в настройке: поставить рамку в точку ----------
  canvas.addEventListener('dblclick', (e) => {
    if (!cfg.calibrate) return;
    setRay(e);
    const hit = ray.intersectObject(model, true)[0];
    if (!hit) return;
    const before = snap();
    frame.position.x = hit.point.x; frame.position.z = hit.point.z;
    frame.position.y = hit.point.y + frame.scale.y / 2 - 0.01;
    frame.updateMatrixWorld(true); readFrame(curBlock); changed(); remember(before);
  });

  // номера на задней стене не показываем поверх лицевых: подпись видна, только если её фасад смотрит на камеру
  const _n = new THREE.Vector3(), _p = new THREE.Vector3();
  function hideBackLabels() {
    for (const o of layer.children) {
      if (!o.isCSS2DObject || !o.userData.facing) continue;
      _n.set(0, 0, o.userData.facing).applyQuaternion(frame.quaternion);
      o.getWorldPosition(_p);
      o.visible = _n.dot(camera.position.clone().sub(_p)) > 0;
    }
  }

  // ---------- автопривязка ----------
  /** Спрятать рамку, подсветку и точки на время снимков фасада */
  function hideAll() {
    const list = [frame, overlay, statusGroup, handles, tc && (tc.getHelper ? tc.getHelper() : tc)].filter(Boolean), was = list.map((o) => o.visible);
    list.forEach((o) => { o.visible = false; });
    return () => list.forEach((o, i) => { o.visible = was[i]; });
  }
  let autoMod = null;
  const loadAuto = async () => (autoMod ??= await import(cfg.autoSrc));

  // ---------- цикл ----------
  const clock = new THREE.Clock();
  const loop = () => {
    const t = clock.getElapsedTime();
    if (anim) {
      const k = Math.min(1, (performance.now() - anim.start) / anim.ms), e = k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2;
      camera.position.lerpVectors(anim.p0, anim.p1, e); controls.target.lerpVectors(anim.t0, anim.t1, e);
      if (k >= 1) anim = null;
    }
    if (dirty) { dirty = false; applyFrame(curBlock); drawGrid(curBlock); cfg.onChange && cfg.onChange(C(curBlock)); }
    if (cfg.calibrate) { hideBackLabels(); placeHandles(); }
    pulse.forEach((m) => { m.opacity = 0.45 + 0.22 * (0.5 + 0.5 * Math.sin(t * 3)); });
    controls.update();
    renderer.render(scene, camera); labels.render(scene, camera);
  };
  renderer.setAnimationLoop(loop);

  overviewModel();
  const api = {
    blocks: blockIds, isCalibrated, units: byId,
    isAuto: (bid) => !!(cal[bid] && cal[bid].auto),
    cal: (bid) => C(bid),
    markSaved(bid) { saved.add(bid); },
    show(id, fly = true) { const u = showUnit(id, fly); if (u && cfg.onShow) cfg.onShow(u, isCalibrated(u.b), api.isAuto(u.b)); return u; },
    focusSelected() { if (selected) focus(cellOf(selected, blocks[selected.b], C(selected.b))); },
    overview, overviewModel, statusView, view,
    // настройка
    calibrate(bid) { cfg.calibrate = true; frameLines.visible = true; curBlock = bid; applyFrame(bid); setCalibrate(true); drawGrid(bid); overview(); return C(bid); },
    mode,
    get: () => C(curBlock),
    set(patch) {
      const before = snap();
      Object.assign(C(curBlock), patch);
      // развороты, стороны и подъезды меняют раскладку — квартиры, поставленные ИИ, встают заново; поставленные человеком — на месте
      if (['flipX', 'flipZ', 'rows', 'back', 'ex'].some((k) => k in patch)) relayout(curBlock);
      changed(); remember(before); return C(curBlock);
    },
    /** Подъезды снова поровну */
    even() { const before = snap(); delete C(curBlock).ex; relayout(curBlock); changed(); remember(before); return C(curBlock); },
    // ---------- разметка квартир ----------
    /** Режим «Квартиры»: рамку не двигаем, щёлкаем и тянем по окнам */
    mark(on) {
      mark.on = !!on; mark.paint = null; preview.visible = false;
      const helper = tc && (tc.getHelper ? tc.getHelper() : tc);
      if (helper) helper.visible = !mark.on;
      if (tc) tc.enabled = !mark.on;
      handles.visible = !mark.on && !!cfg.calibrate;
      if (curBlock != null) drawGrid(curBlock);
    },
    /** Выбрать квартиру (в разметке) */
    select(id) {
      const u = byId[id]; if (!u) return null;
      if (u.b !== curBlock) { curBlock = u.b; applyFrame(u.b); }
      mark.sel = id; drawGrid(curBlock);
      return u;
    },
    selected: () => (mark.sel ? byId[mark.sel] : null),
    /** На все этажи с таким же набором квартир (true) или только эта квартира */
    markAll(on) { mark.all = !!on; },
    /** Где сейчас квартира: сторона, x0, x1 и кем поставлена */
    placeOf(id) { const u = byId[id]; if (!u) return null; const c = C(u.b); return { slot: slotOf(u, c), own: !!(c.ux && c.ux[u.id]) }; },
    /** Окон на стороне ('f' лицевая, 'b' задняя): найденные ИИ или заданные числом */
    winCount: (side) => { const cs = C(curBlock).win && C(curBlock).win[side]; return cs && cs.length > 1 ? cs.length : winOf(C(curBlock), side === 'f' ? 1 : -1).length; },
    setWin(side, n) {
      const before = snap(), c = C(curBlock);
      c.win = Object.assign({}, c.win || {}, { [side]: uniform(Math.max(1, Math.min(120, n))) });
      relayout(curBlock); changed(); remember(before); return c;
    },
    /** Найти окна на фасадах там, где стоит рамка (привязка сделана раньше, без окон) */
    async findWindows() {
      const mod = await loadAuto(), c = C(curBlock), cs = Math.cos(c.r), sn = Math.sin(c.r);
      const keep = (x, z) => { const dx = x - c.c[0], dz = z - c.c[2]; return Math.abs(dx * cs - dz * sn) <= c.s[0] * 0.6 && Math.abs(dx * sn + dz * cs) <= c.s[2] * 0.6; };
      const restore = hideAll();
      let r = null;
      try { r = await mod.fitBlock({ THREE, renderer, scene, meshes: mod.meshesOf(model), keep, nearR: c.r, block: { floors: floorsOf(curBlock), entr: blocks[curBlock].entr }, ai: null }); } finally { restore(); }
      if (!r || !r.cal.win) return null;
      // доли длины найденного дома → доли рамки, которая стоит сейчас
      const F = r.F, off = (F.cx - c.c[0]) * cs - (F.cz - c.c[2]) * sn, map = (x) => +((off + (x - 0.5) * F.L) / c.s[0] + 0.5).toFixed(4);
      const win = {};
      for (const k of ['f', 'b']) { const v = (r.cal.win[k] || []).map(map).filter((x) => x > 0 && x < 1); if (v.length > 1) win[k] = v; }
      if (!win.f && !win.b) return null;
      const before = snap();
      c.win = win; relayout(curBlock); changed(); remember(before);
      return win;
    },
    /** Заново по окнам — все квартиры, и поставленные вручную тоже */
    realloc() {
      const before = snap(), c = C(curBlock);
      c.slots = allocate(blocks[curBlock], c, unitsOf(curBlock), false); delete c.ux;
      changed(); remember(before); return c;
    },
    /** Квартиру изменили (комнат, площадь) — подписи и раскладка по окнам */
    updateUnit(id, patch) {
      const u = byId[id]; if (!u) return null;
      Object.assign(u, patch);
      relayout(u.b);
      if (u.b === curBlock) changed();
      return u;
    },
    undo() {
      const u = undoStack.pop();
      if (u) { cal[u.bid] = JSON.parse(u.s); if (u.bid === curBlock) changed(); }
      cfg.onUndo && cfg.onUndo(undoStack.length);
      return C(curBlock);
    },
    /** Человек подтвердил привязку — отметка «расставил ИИ» снимается */
    confirm(bid = curBlock) { delete C(bid).auto; },
    /**
     * Автопривязка всех корпусов (или only): форма модели + ИИ (opts.ai). Рамки ставятся, отчёт — в cal[bid].auto.
     * @returns {Promise<Object<string, {by, mf, doors, warn}>>}
     */
    async autoFit(opts = {}) {
      const mod = await loadAuto(), meshes = mod.meshesOf(model), zones = mod.candidates(THREE, meshes, mBox);
      const ids = opts.only || blockIds, out = {};
      const restore = hideAll();
      try {
        for (const bid of ids) {
          const i = blockIds.indexOf(bid), zone = zones[i] || zones[0];
          const keep = (x, z) => x >= zone.min.x && x <= zone.max.x && z >= zone.min.z && z <= zone.max.z;
          const r = await mod.fitBlock({ THREE, renderer, scene, meshes, keep, nearR: null, block: blocks[bid], ai: opts.ai || null, debug: opts.debug });
          if (!r) continue;
          if (opts.debug) api.lastDebug = r.dbg;
          if (i >= zones.length) r.report.warn.push('blocks');
          const before = cal[bid] ? JSON.stringify(cal[bid]) : null;
          cal[bid] = Object.assign(r.cal, { auto: r.report });
          // квартиры — на свои окна: по числу комнат (двухкомнатной ~3 окна)
          cal[bid].slots = allocate(blocks[bid], cal[bid], unitsOf(bid), false);
          if (before) remember(before, bid);
          out[bid] = r.report;
        }
      } finally { restore(); }
      if (curBlock != null) { applyFrame(curBlock); if (cfg.calibrate) { drawGrid(curBlock); overview(); } }
      return out;
    },
    /** «Прижать к дому»: рамку — по стенам здания там, где она стоит сейчас (крыша и земля не в счёт); этажи и подъезды не трогаем */
    async snap(opts = {}) {
      const mod = await loadAuto(), c = C(curBlock), cs = Math.cos(c.r), sn = Math.sin(c.r);
      const keep = (x, z) => { const dx = x - c.c[0], dz = z - c.c[2]; return Math.abs(dx * cs - dz * sn) <= c.s[0] * 0.6 && Math.abs(dx * sn + dz * cs) <= c.s[2] * 0.6; };
      const restore = hideAll();
      let r = null;
      try { r = await mod.fitBlock({ THREE, renderer, scene, meshes: mod.meshesOf(model), keep, nearR: c.r, block: { floors: floorsOf(curBlock), entr: blocks[curBlock].entr }, ai: null, debug: opts.debug }); if (opts.debug && r) api.lastDebug = r.dbg; } finally { restore(); }
      if (!r) return null;
      const before = snap();
      Object.assign(c, { c: r.cal.c, s: r.cal.s, r: r.cal.r });
      if (r.cal.win) { c.win = r.cal.win; relayout(curBlock); }
      changed(); remember(before);
      return c;
    },
    block: () => curBlock,
    // карточка закрыта — не рисуем (в iframe внутри закрытого окна)
    pause(on) { renderer.setAnimationLoop(on ? null : loop); },
    // проверка из консоли / тестов: рамка и подсветка в мировых координатах
    debug() {
      const hl = layer.children.find((o) => o.isMesh), b = hl ? new THREE.Box3().setFromObject(hl) : null;
      return { frame: { p: frame.position.toArray(), s: frame.scale.toArray(), r: frame.rotation.y }, cell: b && { min: b.min.toArray(), max: b.max.toArray() }, model: { min: mBox.min.toArray(), max: mBox.max.toArray() }, D, grow: growOf(), handles: handles.children.length };
    },
  };
  return api;
}
