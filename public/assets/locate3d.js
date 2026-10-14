/* Квартира на настоящей 3D-модели дома (company/objects/locate.blade.php).
   В модели нет квартир — их находим по рамке корпуса (Services\Locate): подъезды делят рамку по длине, этажи — по высоте,
   квартиры этажа — по фасадам: первая половина на лицевой стороне (+Z рамки), остальные — на задней.
   Режим настройки: рамку двигают, поворачивают и растягивают прямо на модели, номера квартир видны на фасаде. */
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

/** Ячейка помещения в рамке корпуса, координаты рамки от −0.5 до 0.5 */
export function cellOf(u, B, C) {
  const E = Math.max(1, B.entr.length), F = Math.max(1, C.floors || B.floors), n = Math.max(1, u.n);
  const rows = C.rows === 1 || n < 2 ? 1 : 2, front = Math.ceil(n / rows);
  let side = 'all', col = u.i, cols = n;
  if (rows === 2) {
    if (u.i < front) { side = 'front'; col = u.i; cols = front; } else { side = 'back'; cols = n - front; col = u.i - front; if (C.back !== 'fwd') col = cols - 1 - col; }
  }
  let x0 = (u.e + col / cols) / E, x1 = (u.e + (col + 1) / cols) / E;
  if (C.flipX) [x0, x1] = [1 - x1, 1 - x0];
  // первый этаж часто выше (магазины): gf — его высота в метрах, остальные этажи делят высоту поровну
  const H = C.s ? C.s[1] : 0, g = F > 1 && C.gf > 0 && H > C.gf ? C.gf / H : 1 / F;
  const yAt = (k) => (k <= 0 ? 0 : F === 1 ? 1 : g + ((k - 1) * (1 - g)) / (F - 1));
  const f = Math.min(F, Math.max(1, u.f)), y0 = yAt(f - 1), y1 = yAt(f);
  let z0 = 0, z1 = 1;
  if (side !== 'all') {
    const isFront = (side === 'front') !== !!C.flipZ;
    [z0, z1] = isFront ? [0.5, 1] : [0, 0.5];
  }
  const facing = side === 'all' ? (C.flipZ ? -1 : 1) : ((side === 'front') !== !!C.flipZ ? 1 : -1);
  return { min: new THREE.Vector3(x0 - 0.5, y0 - 0.5, z0 - 0.5), max: new THREE.Vector3(x1 - 0.5, y1 - 0.5, z1 - 0.5), facing, floorY: [y0 - 0.5, y1 - 0.5] };
}

export async function start(el, cfg) {
  const T = cfg.T || {};
  const accent = css('accent'), accent2 = css('accent-2');
  const units = cfg.data.units, blocks = cfg.data.blocks;
  const byId = Object.fromEntries(units.map((u) => [u.id, u]));
  const cal = JSON.parse(JSON.stringify(cfg.cal || {}));

  // ---------- сцена ----------
  // логарифмический буфер глубины: и квартал на километры, и окно квартиры вблизи без мерцания
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, logarithmicDepthBuffer: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;
  el.appendChild(renderer.domElement);
  const labels = new CSS2DRenderer();
  labels.domElement.className = 'lc-labels';
  el.appendChild(labels.domElement);
  const scene = new THREE.Scene();
  const pmrem = new THREE.PMREMGenerator(renderer);
  scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  scene.add(new THREE.HemisphereLight(0xffffff, 0x8a93a6, 0.55));
  const sun = new THREE.DirectionalLight(0xffffff, 1.4); sun.position.set(1, 2, 1.2); scene.add(sun);
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 10000);
  const controls = new OrbitControls(camera, renderer.domElement);
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
  // где дом в файле: если в модели целый квартал (дороги, соседние дома, кран), ищем самое высокое и «толстое» здание —
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
    // без настройки — рамка по найденному дому (или по всей модели, если в файле только сам дом)
    const c = auto.getCenter(new THREE.Vector3()), s = auto.getSize(new THREE.Vector3());
    return { c: [c.x, c.y, c.z], s: [s.x || 1, s.y || 1, s.z || 1], r: 0, floors: blocks[bid].floors, gf: 0, rows: 2, back: 'rev', flipX: false, flipZ: false };
  }
  const C = (bid) => (cal[bid] = cal[bid] || defaults(bid));
  const isCalibrated = (bid) => !!(cfg.cal && cfg.cal[bid]);
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
  /** Прямоугольник на стене рамки (координаты рамки) → точки в мире, чуть впереди стены */
  function wallRect(x0, y0, x1, y1, facing) {
    const z = facing > 0 ? 0.5 : -0.5, off = (growOf() * 1.6 / frame.scale.z) * facing;
    return [[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]].map(([x, y]) => frame.localToWorld(new THREE.Vector3(x, y, z + off)));
  }
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
    const E = Math.max(1, B.entr.length);
    let ex0 = u.e / E, ex1 = (u.e + 1) / E;
    if (c.flipX) [ex0, ex1] = [1 - ex1, 1 - ex0];
    if (E > 1 || Object.keys(blocks).length > 1) {
      thick(wallRect(ex0 - 0.5, -0.5, ex1 - 0.5, 0.5, cell.facing), accent2, 2.5, true);
      const en = document.createElement('div');
      en.className = 'lc-entr';
      en.textContent = (B.name && Object.keys(blocks).length > 1 ? B.name + ' · ' : '') + (B.entr[u.e] || u.e + 1) + '-' + (T.en || '');
      const lo = new CSS2DObject(en);
      lo.position.copy(wallRect(ex0 - 0.5, -0.5, ex1 - 0.5, -0.5, cell.facing)[0].clone().lerp(wallRect(ex0 - 0.5, -0.5, ex1 - 0.5, -0.5, cell.facing)[1], 0.5));
      lo.center.set(0.5, -0.2);
      overlay.add(lo);
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
  let anim = null;
  function tween(pos, target, ms = 1100) {
    anim = { p0: camera.position.clone(), t0: controls.target.clone(), p1: pos, t1: target, start: performance.now(), ms };
  }

  // ---------- настройка рамки ----------
  let tc = null;
  const colors = [accent, accent2];
  function drawGrid(bid) {
    clear(); pulse.length = 0;
    const B = blocks[bid], c = C(bid);
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
  }
  function setCalibrate(on) {
    if (on && !tc) {
      tc = new TransformControls(camera, renderer.domElement);
      tc.addEventListener('dragging-changed', (e) => { controls.enabled = !e.value; if (!e.value) { readFrame(curBlock); cfg.onChange && cfg.onChange(C(curBlock)); drawGrid(curBlock); } });
      tc.addEventListener('objectChange', () => { if (tc.getMode() === 'rotate') { frame.rotation.x = 0; frame.rotation.z = 0; } });
      scene.add(tc.getHelper ? tc.getHelper() : tc);
      tc.attach(frame);
      mode('translate');
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
  const pick = (e) => {
    const r = renderer.domElement.getBoundingClientRect();
    ptr.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    ray.setFromCamera(ptr, camera);
    return ray.intersectObject(statusGroup, true)[0]?.object || null;
  };
  renderer.domElement.addEventListener('pointermove', (e) => {
    if (!statusGroup.children.length || e.buttons) return;
    const o = pick(e);
    if (o === hovered) return;
    if (hovered) hovered.material = tileMat(byId[hovered.userData.unit]?.s || 'free', false);
    hovered = o;
    renderer.domElement.style.cursor = o ? 'pointer' : '';
    if (o) { o.material = tileMat(byId[o.userData.unit]?.s || 'free', true); cfg.onHover && cfg.onHover(byId[o.userData.unit]); }
  });
  let downAt = null;
  renderer.domElement.addEventListener('pointerdown', (e) => { downAt = [e.clientX, e.clientY]; });
  renderer.domElement.addEventListener('pointerup', (e) => {
    if (!statusGroup.children.length || !downAt || Math.hypot(e.clientX - downAt[0], e.clientY - downAt[1]) > 6) return;   // это было вращение, а не клик
    const o = pick(e);
    if (o && cfg.onPick) cfg.onPick(byId[o.userData.unit]);
  });

  // ---------- выбор кликом (двойной клик в настройке: поставить рамку в точку) ----------
  const ray = new THREE.Raycaster(), ptr = new THREE.Vector2();
  renderer.domElement.addEventListener('dblclick', (e) => {
    if (!cfg.calibrate) return;
    const r = renderer.domElement.getBoundingClientRect();
    ptr.set(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
    ray.setFromCamera(ptr, camera);
    const hit = ray.intersectObject(model, true)[0];
    if (!hit) return;
    frame.position.x = hit.point.x; frame.position.z = hit.point.z;
    frame.position.y = hit.point.y + frame.scale.y / 2 - 0.01;
    frame.updateMatrixWorld(true); readFrame(curBlock); cfg.onChange && cfg.onChange(C(curBlock)); drawGrid(curBlock);
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

  // ---------- цикл ----------
  const clock = new THREE.Clock();
  const loop = () => {
    const t = clock.getElapsedTime();
    if (anim) {
      const k = Math.min(1, (performance.now() - anim.start) / anim.ms), e = k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2;
      camera.position.lerpVectors(anim.p0, anim.p1, e); controls.target.lerpVectors(anim.t0, anim.t1, e);
      if (k >= 1) anim = null;
    }
    if (cfg.calibrate) hideBackLabels();
    pulse.forEach((m) => { m.opacity = 0.45 + 0.22 * (0.5 + 0.5 * Math.sin(t * 3)); });
    controls.update();
    renderer.render(scene, camera); labels.render(scene, camera);
  };
  renderer.setAnimationLoop(loop);

  overviewModel();
  const api = {
    blocks: blockIds, isCalibrated, units: byId,
    show(id, fly = true) { const u = showUnit(id, fly); if (u && cfg.onShow) cfg.onShow(u, isCalibrated(u.b)); return u; },
    focusSelected() { if (selected) focus(cellOf(selected, blocks[selected.b], C(selected.b))); },
    overview, overviewModel, statusView,
    // настройка
    calibrate(bid) { cfg.calibrate = true; frameLines.visible = true; curBlock = bid; applyFrame(bid); setCalibrate(true); drawGrid(bid); overview(); return C(bid); },
    mode,
    get: () => C(curBlock),
    set(patch) { Object.assign(C(curBlock), patch); applyFrame(curBlock); drawGrid(curBlock); return C(curBlock); },
    fitModel() { const d = defaults(curBlock); Object.assign(C(curBlock), { c: d.c, s: d.s, r: 0 }); applyFrame(curBlock); drawGrid(curBlock); overview(); return C(curBlock); },
    block: () => curBlock,
    // карточка закрыта — не рисуем (в iframe внутри закрытого окна)
    pause(on) { renderer.setAnimationLoop(on ? null : loop); },
    // проверка из консоли / тестов: рамка и подсветка в мировых координатах
    debug() {
      const hl = layer.children.find((o) => o.isMesh), b = hl ? new THREE.Box3().setFromObject(hl) : null;
      return { frame: { p: frame.position.toArray(), s: frame.scale.toArray(), r: frame.rotation.y }, cell: b && { min: b.min.toArray(), max: b.max.toArray() }, model: { min: mBox.min.toArray(), max: mBox.max.toArray() }, D, grow: growOf() };
    },
  };
  return api;
}
