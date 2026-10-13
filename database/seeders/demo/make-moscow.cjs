// Реалистичная демо-модель строящегося ЖК в Москве (GLB) и интерьеры квартир.
// Три башни на стилобате: две готовы, третья строится (открытый каркас, башенный кран, забор).
// Квартал: улицы с разметкой, машины, деревья, фонари, двор с площадкой, соседние дома.
// Запуск: node database/seeders/demo/make-moscow.cjs → database/seeders/demo/msk-*.glb
const fs = require('fs'), path = require('path');

/* ---------------- построитель сцены ---------------- */
class Scene {
  constructor() { this.mats = new Map(); }
  mat(name, c, o = {}) { this.mats.set(name, { c, m: o.m ?? 0, r: o.r ?? 0.85, e: o.e ?? 0, a: o.a ?? 1, pos: [], nor: [], idx: [] }); return name; }
  _tri(M, verts, norms, faces) { const b = M.pos.length / 3; M.pos.push(...verts); M.nor.push(...norms); for (const f of faces) M.idx.push(b + f[0], b + f[1], b + f[2]); }
  // коробка: центр, размер, поворот вокруг Y (рад)
  box(mat, [x, y, z], [sx, sy, sz], ry = 0) {
    const M = this.mats.get(mat), c = Math.cos(ry), s = Math.sin(ry);
    const T = (px, py, pz) => [x + px * c + pz * s, y + py, z - px * s + pz * c], N = (nx, ny, nz) => [nx * c + nz * s, ny, -nx * s + nz * c];
    const F = [[[1, 0, 0], [[1, -1, -1], [1, 1, -1], [1, 1, 1], [1, -1, 1]]], [[-1, 0, 0], [[-1, -1, 1], [-1, 1, 1], [-1, 1, -1], [-1, -1, -1]]],
      [[0, 1, 0], [[-1, 1, -1], [-1, 1, 1], [1, 1, 1], [1, 1, -1]]], [[0, -1, 0], [[-1, -1, 1], [-1, -1, -1], [1, -1, -1], [1, -1, 1]]],
      [[0, 0, 1], [[-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1]]], [[0, 0, -1], [[1, -1, -1], [-1, -1, -1], [-1, 1, -1], [1, 1, -1]]]];
    for (const [n, vs] of F) {
      const v = [], nn = [];
      for (const q of vs) { v.push(...T(q[0] * sx / 2, q[1] * sy / 2, q[2] * sz / 2)); nn.push(...N(...n)); }
      this._tri(M, v, nn, [[0, 1, 2], [0, 2, 3]]);
    }
  }
  cyl(mat, [x, y, z], r, h, seg = 12, r2 = r) {
    const M = this.mats.get(mat), v = [], n = [], f = [];
    for (let i = 0; i <= seg; i++) {
      const a = i / seg * Math.PI * 2, ca = Math.cos(a), sa = Math.sin(a);
      v.push(x + ca * r, y - h / 2, z + sa * r, x + ca * r2, y + h / 2, z + sa * r2); n.push(ca, 0, sa, ca, 0, sa);
    }
    for (let i = 0; i < seg; i++) { const k = i * 2; f.push([k, k + 1, k + 3], [k, k + 3, k + 2]); }
    this._tri(M, v, n, f);
    if (r2 > 0) { const cv = [x, y + h / 2, z], cn = [0, 1, 0], cf = []; for (let i = 0; i <= seg; i++) { const a = i / seg * Math.PI * 2; cv.push(x + Math.cos(a) * r2, y + h / 2, z + Math.sin(a) * r2); cn.push(0, 1, 0); if (i) cf.push([0, i + 1, i]); } this._tri(M, cv, cn, cf); }
  }
  // плоскость, видимая только снизу (потолок: изнутри виден, сверху прозрачен)
  ceiling(mat, [x, y, z], [sx, sz]) { const M = this.mats.get(mat); this._tri(M, [x - sx / 2, y, z - sz / 2, x + sx / 2, y, z - sz / 2, x + sx / 2, y, z + sz / 2, x - sx / 2, y, z + sz / 2], [0, -1, 0, 0, -1, 0, 0, -1, 0, 0, -1, 0], [[0, 1, 2], [0, 2, 3]]); }
  sphere(mat, [x, y, z], r, seg = 10, sy = 1) {
    const M = this.mats.get(mat), v = [], n = [], f = [];
    for (let i = 0; i <= seg; i++) for (let j = 0; j <= seg; j++) {
      const t = i / seg * Math.PI, p = j / seg * Math.PI * 2, nx = Math.sin(t) * Math.cos(p), ny = Math.cos(t), nz = Math.sin(t) * Math.sin(p);
      v.push(x + nx * r, y + ny * r * sy, z + nz * r); n.push(nx, ny, nz);
    }
    for (let i = 0; i < seg; i++) for (let j = 0; j < seg; j++) { const a = i * (seg + 1) + j, b = a + seg + 1; f.push([a, b, a + 1], [b, b + 1, a + 1]); }
    this._tri(M, v, n, f);
  }
  save(file) {
    const bin = [], views = [], acc = [], prims = [], mats = [];
    let off = 0;
    const push = (arr, target) => { const b = Buffer.from(arr.buffer); const pad = (4 - b.length % 4) % 4; bin.push(b, Buffer.alloc(pad)); views.push({ buffer: 0, byteOffset: off, byteLength: b.length, target }); off += b.length + pad; return views.length - 1; };
    for (const [, M] of this.mats) {
      if (!M.idx.length) continue;
      const P = new Float32Array(M.pos), N = new Float32Array(M.nor), I = new Uint32Array(M.idx);
      const min = [1e9, 1e9, 1e9], max = [-1e9, -1e9, -1e9];
      for (let i = 0; i < P.length; i += 3) for (let k = 0; k < 3; k++) { min[k] = Math.min(min[k], P[i + k]); max[k] = Math.max(max[k], P[i + k]); }
      acc.push({ bufferView: push(P, 34962), componentType: 5126, count: P.length / 3, type: 'VEC3', min, max }, { bufferView: push(N, 34962), componentType: 5126, count: N.length / 3, type: 'VEC3' },
        { bufferView: push(I, 34963), componentType: 5125, count: I.length, type: 'SCALAR' });
      mats.push(Object.assign({ pbrMetallicRoughness: { baseColorFactor: [...M.c.map(v => v / 255), M.a], metallicFactor: M.m, roughnessFactor: M.r } },
        M.e ? { emissiveFactor: M.c.map(v => v / 255 * M.e) } : {}, M.a < 1 ? { alphaMode: 'BLEND', doubleSided: true } : {}));
      prims.push({ attributes: { POSITION: acc.length - 3, NORMAL: acc.length - 2 }, indices: acc.length - 1, material: mats.length - 1 });
    }
    const json = { asset: { version: '2.0', generator: 'Oftob_Sohtumon demo (Moscow)' }, scene: 0, scenes: [{ nodes: [0] }], nodes: [{ mesh: 0 }], meshes: [{ primitives: prims }], materials: mats, accessors: acc, bufferViews: views, buffers: [{ byteLength: off }] };
    let js = Buffer.from(JSON.stringify(json)); js = Buffer.concat([js, Buffer.alloc((4 - js.length % 4) % 4, 0x20)]);
    const bb = Buffer.concat(bin), total = 28 + js.length + bb.length, h = Buffer.alloc(12);
    h.writeUInt32LE(0x46546C67, 0); h.writeUInt32LE(2, 4); h.writeUInt32LE(total, 8);
    const c1 = Buffer.alloc(8); c1.writeUInt32LE(js.length, 0); c1.writeUInt32LE(0x4E4F534A, 4);
    const c2 = Buffer.alloc(8); c2.writeUInt32LE(bb.length, 0); c2.writeUInt32LE(0x004E4942, 4);
    fs.writeFileSync(file, Buffer.concat([h, c1, js, c2, bb]));
    console.log(path.basename(file), (total / 1048576).toFixed(1) + ' MB');
  }
}

let seed = 7; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;

/* ---------------- ЖК в Москве ---------------- */
function moscow(file) {
  const S = new Scene();
  // материалы: облицовочный кирпич, фиброцемент, стекло с отражением неба, бетон
  S.mat('brick', [168, 92, 64], { r: 0.9 }); S.mat('brick2', [122, 70, 52], { r: 0.9 }); S.mat('fiber', [226, 222, 214], { r: 0.75 }); S.mat('graphite', [58, 62, 68], { r: 0.6 });
  S.mat('glass', [70, 96, 120], { m: 0.85, r: 0.06 }); S.mat('glassLight', [150, 190, 215], { m: 0.6, r: 0.08, e: 0.35 }); S.mat('frame', [40, 42, 46], { m: 0.5, r: 0.4 });
  S.mat('balcony', [175, 205, 220], { m: 0.3, r: 0.05, a: 0.45 }); S.mat('concrete', [170, 170, 165], { r: 0.95 }); S.mat('concreteDark', [128, 128, 124], { r: 0.95 });
  S.mat('asphalt', [62, 64, 68], { r: 0.95 }); S.mat('mark', [235, 235, 230], { r: 0.8 }); S.mat('curb', [190, 188, 182], { r: 0.9 }); S.mat('paving', [176, 168, 156], { r: 0.9 });
  S.mat('grass', [96, 140, 70], { r: 1 }); S.mat('leaf', [70, 118, 58], { r: 1 }); S.mat('leaf2', [92, 138, 66], { r: 1 }); S.mat('trunk', [92, 68, 50], { r: 1 });
  S.mat('rubber', [200, 90, 70], { r: 1 }); S.mat('play', [240, 190, 50], { r: 0.6 }); S.mat('play2', [60, 150, 210], { r: 0.6 });
  S.mat('crane', [236, 176, 30], { m: 0.3, r: 0.5 }); S.mat('steel', [110, 114, 120], { m: 0.8, r: 0.35 }); S.mat('fence', [52, 98, 150], { r: 0.7 }); S.mat('banner', [245, 245, 240], { r: 0.8 });
  S.mat('lamp', [255, 236, 190], { e: 0.9 }); S.mat('dark', [34, 36, 40], { r: 0.6 }); S.mat('city', [196, 194, 188], { r: 0.9 }); S.mat('city2', [160, 168, 176], { r: 0.9 }); S.mat('cityGlass', [90, 110, 130], { m: 0.7, r: 0.1 });
  const carCols = [[200, 30, 36], [235, 235, 235], [30, 32, 36], [40, 70, 140], [150, 152, 156], [210, 180, 90]];
  carCols.forEach((c, i) => S.mat('car' + i, c, { m: 0.6, r: 0.25 }));

  const FH = 3.0;   // высота этажа
  // земля и квартал
  S.box('grass', [0, -0.1, 0], [340, 0.2, 300]);
  // улицы: продольная и поперечная
  for (const [cx, cz, w, d, rot] of [[0, 78, 340, 18, 0], [-118, 0, 18, 300, 0]]) {
    S.box('asphalt', [cx, 0.01, cz], [w, 0.04, d]);
    if (w > d) { for (let x = -165; x < 165; x += 9) S.box('mark', [x, 0.04, cz], [4.5, 0.02, 0.25]); S.box('curb', [cx, 0.12, cz - d / 2 - 0.3], [w, 0.24, 0.6]); S.box('curb', [cx, 0.12, cz + d / 2 + 0.3], [w, 0.24, 0.6]); S.box('paving', [cx, 0.05, cz - d / 2 - 3.6], [w, 0.1, 6]); }
    else { for (let z = -145; z < 145; z += 9) S.box('mark', [cx, 0.04, z], [0.25, 0.02, 4.5]); S.box('paving', [cx + d / 2 + 3.6 - 9, 0.05, 0], [6, 0.1, 300]); }
  }
  // пешеходный переход
  for (let k = -3; k <= 3; k++) S.box('mark', [-60 + k * 1.2, 0.045, 78], [0.6, 0.02, 15]);
  // машины вдоль улицы
  const car = (x, z, ry) => {
    const c = 'car' + Math.floor(rnd() * carCols.length);
    S.box(c, [x, 0.75, z], [4.4, 0.75, 1.85], ry); S.box(c, [x - 0.2 * Math.cos(ry), 1.35, z + 0.2 * Math.sin(ry)], [2.4, 0.6, 1.7], ry);
    S.box('glass', [x - 0.2 * Math.cos(ry), 1.36, z + 0.2 * Math.sin(ry)], [2.42, 0.45, 1.72], ry);
    for (const [dx, dz] of [[1.4, 0.85], [1.4, -0.85], [-1.4, 0.85], [-1.4, -0.85]]) S.cyl('dark', [x + dx * Math.cos(ry) + dz * Math.sin(ry), 0.35, z - dx * Math.sin(ry) + dz * Math.cos(ry)], 0.35, 0.3, 10);
  };
  for (let x = -150; x < 150; x += 7) if (rnd() > 0.35 && Math.abs(x + 60) > 10) car(x, 72.5, 0);
  for (let x = -140; x < 150; x += 23) car(x + rnd() * 8, 81.5, Math.PI);
  for (let z = -130; z < 60; z += 7) if (rnd() > 0.45) car(-113.5, z, Math.PI / 2);
  // фонари
  for (let x = -150; x <= 150; x += 25) { S.cyl('steel', [x, 4, 65.5], 0.1, 8, 8); S.box('steel', [x, 8, 66.5], [0.2, 0.15, 2]); S.box('lamp', [x, 7.9, 67.3], [0.5, 0.12, 0.35]); }
  // деревья: стволы и пышные кроны
  const tree = (x, z, h = 6, y0 = 0) => { S.cyl('trunk', [x, y0 + h * 0.35, z], 0.18, h * 0.7, 7); S.sphere(rnd() > 0.5 ? 'leaf' : 'leaf2', [x, y0 + h * 0.78, z], h * 0.32, 8, 1.15); S.sphere('leaf', [x + 0.6, y0 + h * 0.66, z - 0.4], h * 0.22, 7); };
  for (let x = -150; x <= 150; x += 10) tree(x + rnd() * 2, 64 + rnd(), 6 + rnd() * 2);
  for (let z = -140; z <= 60; z += 11) tree(-104 + rnd(), z, 6 + rnd() * 2);

  // стилобат (2 этажа: магазины, кафе) под тремя башнями, двор на кровле стилобата
  const pod = { x: 0, z: 0, w: 150, d: 92, h: 7.5 };
  S.box('graphite', [pod.x, pod.h / 2, pod.z], [pod.w, pod.h, pod.d]);
  for (let x = -pod.w / 2 + 4; x < pod.w / 2 - 2; x += 6) {   // витрины по улице
    S.box('glassLight', [x + 2.6, 3.0, pod.d / 2 + 0.03], [5.0, 4.6, 0.06]); S.box('frame', [x + 5.4, 3.0, pod.d / 2 + 0.06], [0.2, 4.8, 0.12]);
    S.box('dark', [x + 2.6, 6.1, pod.d / 2 + 0.6], [5.4, 0.9, 1.2]);   // вывески/козырьки
  }
  S.box('paving', [pod.x, pod.h + 0.05, pod.z], [pod.w - 1, 0.1, pod.d - 1]);   // двор на кровле стилобата
  // двор: газоны, дорожки, детская площадка, лавочки, деревья
  S.box('grass', [-20, pod.h + 0.15, 4], [40, 0.12, 30]); S.box('grass', [32, pod.h + 0.15, 8], [26, 0.12, 24]);
  S.box('rubber', [8, pod.h + 0.13, 8], [16, 0.1, 14]);
  S.box('play', [6, pod.h + 1.6, 8], [4, 0.3, 4]); for (const [dx, dz] of [[-2, -2], [2, -2], [-2, 2], [2, 2]]) S.cyl('play2', [6 + dx, pod.h + 0.9, 8 + dz], 0.12, 1.6, 8);
  S.box('play2', [12, pod.h + 1.0, 11], [0.3, 2, 5], 0.4); S.sphere('play', [11, pod.h + 0.6, 4], 0.6, 8);
  for (let k = 0; k < 6; k++) { S.box('trunk', [-30 + k * 6, pod.h + 0.55, -6], [1.8, 0.1, 0.5]); S.box('frame', [-30 + k * 6, pod.h + 0.3, -6], [1.6, 0.4, 0.1]); }
  for (let k = 0; k < 7; k++) tree(-34 + k * 9, 18 + (k % 2) * 3, 5.5, pod.h); for (let k = 0; k < 4; k++) tree(26 + k * 5, -2 + (k % 2) * 6, 5, pod.h);
  for (let k = 0; k < 8; k++) { S.cyl('steel', [-36 + k * 10, pod.h + 2, -14], 0.06, 4, 6); S.sphere('lamp', [-36 + k * 10, pod.h + 4.1, -14], 0.25, 6); }

  // башня: облицовка кирпичом + вертикальные вставки фиброцемента, панорамные окна, стеклянные балконы
  const tower = (cx, cz, w, d, floors, done, palette) => {
    const base = pod.h, [wallA, wallB] = palette;
    const winW = 1.9, step = 3.2;
    for (let f = 0; f < floors; f++) {
      const y = base + f * FH + FH / 2;
      const built = f < done, skeleton = f >= done && f < done + 4;
      if (built) {
        // массив стены с ритмом вставок
        S.box(f % 6 < 3 ? wallA : wallB, [cx, y, cz], [w, FH, d]);
        S.box('fiber', [cx, y - FH / 2 + 0.12, cz], [w + 0.25, 0.24, d + 0.25]);   // междуэтажный пояс
        for (const [nx, nz, len, ax] of [[0, 1, w, 'x'], [0, -1, w, 'x'], [1, 0, d, 'z'], [-1, 0, d, 'z']]) {
          const cnt = Math.floor((len - 2) / step);
          for (let k = 0; k < cnt; k++) {
            const t = -len / 2 + 1.6 + k * step + winW / 2;
            const px = ax === 'x' ? cx + t : cx + nx * (w / 2 + 0.03), pz = ax === 'x' ? cz + nz * (d / 2 + 0.03) : cz + t;
            const sz = ax === 'x' ? [winW, FH * 0.66, 0.06] : [0.06, FH * 0.66, winW];
            S.box(k % 5 === 2 && f % 3 === 0 ? 'glassLight' : 'glass', [px, y + 0.15, pz], sz);
            const fz = ax === 'x' ? [winW + 0.2, 0.12, 0.12] : [0.12, 0.12, winW + 0.2];
            S.box('frame', [px, y - FH * 0.33 + 0.15, pz], fz); S.box('frame', [px, y + FH * 0.33 + 0.15, pz], fz);
            if (k % 4 === 1 && f > 0 && ax === 'x') {   // стеклянные балконы
              const bz = cz + nz * (d / 2 + 0.75);
              S.box('concrete', [px, y - FH / 2 + 0.1, bz], [3.0, 0.2, 1.5]); S.box('balcony', [px, y - FH / 2 + 0.75, cz + nz * (d / 2 + 1.48)], [3.0, 1.1, 0.04]);
            }
          }
        }
      } else if (skeleton) {
        // строящиеся этажи: перекрытие и колонны, опалубка на верхнем
        S.box('concrete', [cx, y - FH / 2 + 0.12, cz], [w, 0.24, d]);
        for (let x = -w / 2 + 1; x <= w / 2 - 1; x += 6) for (let z = -d / 2 + 1; z <= d / 2 - 1; z += 6) S.box('concreteDark', [cx + x, y, cz + z], [0.5, FH, 0.5]);
        if (f === done + 3) { S.box('trunk', [cx, y + FH / 2, cz], [w, 0.2, d]); for (let x = -w / 2; x <= w / 2; x += 2) S.box('steel', [cx + x, y + FH / 2 + 0.6, cz + d / 2], [0.06, 1.2, 0.06]); }
      }
    }
    const top = base + Math.min(floors, done) * FH;
    if (done >= floors) {   // кровля, технический этаж, парапет
      S.box('graphite', [cx, top + 0.6, cz], [w + 0.4, 1.2, d + 0.4]); S.box('concrete', [cx, top + 0.1, cz], [w - 0.6, 0.2, d - 0.6]);
      S.box('fiber', [cx + w * 0.15, top + 2.6, cz], [w * 0.35, 3.6, d * 0.45]); S.box('dark', [cx - w * 0.2, top + 1.8, cz + 1], [3, 2.2, 3]);
    }
    // входная группа во двор
    S.box('glassLight', [cx, base + 1.6, cz + d / 2 + 0.04], [4.5, 3.2, 0.08]); S.box('graphite', [cx, base + 3.4, cz + d / 2 + 1.2], [6, 0.3, 2.6]);
    return top;
  };
  tower(-46, -14, 26, 22, 25, 25, ['brick', 'fiber']);
  tower(4, -22, 24, 20, 21, 21, ['fiber', 'brick2']);
  const topC = tower(52, -12, 22, 20, 17, 11, ['brick2', 'fiber']);

  // башенный кран у строящейся башни
  const kx = 66, kz = -26, kh = topC + 22;
  for (let y = 2; y < kh; y += 4) { S.box('crane', [kx, y, kz], [1.8, 4, 1.8]); for (const [dx, dz] of [[0.9, 0], [-0.9, 0], [0, 0.9], [0, -0.9]]) S.box('steel', [kx + dx, y, kz + dz], dx ? [0.05, 4, 1.8] : [1.8, 4, 0.05]); }
  S.box('crane', [kx - 18, kh + 1, kz], [52, 1.2, 1.2]); S.box('crane', [kx + 13, kh + 1, kz], [10, 1.2, 1.2]);
  S.box('concreteDark', [kx + 16, kh, kz], [4, 2.5, 2.5]); S.box('dark', [kx - 1.8, kh - 1, kz + 1.6], [2.4, 2.4, 2.2]);
  S.cyl('steel', [kx, kh + 6, kz], 0.4, 10, 6, 0.1); S.cyl('steel', [kx - 30, kh - 8, kz], 0.04, 18, 4); S.box('crane', [kx - 30, kh - 18, kz], [1.5, 0.8, 1.5]);
  // строительный забор с баннером и бытовки
  for (const [x, z, w, d] of [[52, 34, 50, 0.2], [27, 12, 0.2, 46], [77, 12, 0.2, 46]]) S.box('fence', [x, 1.25, z], [w, 2.5, d]);
  S.box('banner', [52, 1.6, 34.15], [30, 1.8, 0.05]);
  for (let k = 0; k < 4; k++) S.box(k % 2 ? 'fiber' : 'fence', [40 + k * 3.2, pod.h + 1.3, 22], [3, 2.6, 6]);

  // соседняя застройка Москвы: панельки и бизнес-центр
  const block = (x, z, w, d, h, m = 'city') => { S.box(m, [x, h / 2, z], [w, h, d]); for (let y = 3; y < h - 1; y += 3) S.box('cityGlass', [x, y, z + d / 2 + 0.02], [w - 2, 1.3, 0.05]); };
  block(-150, -120, 60, 14, 27); block(-60, -125, 50, 14, 36, 'city2'); block(30, -122, 70, 14, 30); block(120, -110, 40, 40, 60, 'cityGlass'); block(140, 30, 30, 50, 24, 'city2');
  block(-160, 120, 50, 20, 18); block(-40, 125, 70, 16, 33, 'city2'); block(70, 128, 60, 16, 27); block(150, 130, 30, 30, 45, 'cityGlass');
  // город до горизонта: земля 1,6 км, кольцо кварталов, дальние высотки
  S.mat('far', [150, 160, 140], { r: 1 }); S.mat('far2', [128, 138, 122], { r: 1 }); S.mat('road2', [92, 94, 98], { r: 1 });
  S.box('far', [0, -0.3, 0], [1600, 0.2, 1600]);
  for (const r of [260, 520]) { S.box('road2', [0, -0.15, r], [1600, 0.1, 22]); S.box('road2', [0, -0.15, -r], [1600, 0.1, 22]); S.box('road2', [r, -0.15, 0], [22, 0.1, 1600]); S.box('road2', [-r, -0.15, 0], [22, 0.1, 1600]); }
  for (let i = 0; i < 26; i++) { const a = rnd() * Math.PI * 2, d = 230 + rnd() * 330; S.box('far2', [Math.cos(a) * d, -0.18, Math.sin(a) * d], [60 + rnd() * 80, 0.1, 40 + rnd() * 60]); }
  for (let i = 0; i < 140; i++) {
    const a = rnd() * Math.PI * 2, d = 200 + rnd() * 560, x = Math.cos(a) * d, z = Math.sin(a) * d;
    if (Math.abs(x) < 190 && Math.abs(z) < 170) continue;
    const tall = rnd() > 0.85, h = tall ? 70 + rnd() * 110 : 12 + rnd() * 45, w = tall ? 20 + rnd() * 15 : 25 + rnd() * 45, dd = tall ? 20 + rnd() * 15 : 12 + rnd() * 14;
    block(x, z, w, dd, h, tall ? 'cityGlass' : (rnd() > 0.5 ? 'city' : 'city2'));
  }
  for (let i = 0; i < 160; i++) { const a = rnd() * Math.PI * 2, d = 190 + rnd() * 600; const x = Math.cos(a) * d, z = Math.sin(a) * d; if (Math.abs(x) < 180 && Math.abs(z) < 160) continue; tree(x, z, 7 + rnd() * 4); }
  S.save(file);
}

/* ---------------- интерьер квартиры (евро-двушка 58 м²) ---------------- */
function apartment(file, rooms) {
  const S = new Scene();
  S.mat('parquet', [176, 132, 92], { r: 0.55 }); S.mat('wall', [238, 234, 226], { r: 0.9 }); S.mat('accent', [72, 96, 110], { r: 0.85 }); S.mat('ceil', [250, 250, 248], { r: 1 });
  S.mat('tile', [214, 218, 220], { r: 0.3 }); S.mat('white', [250, 250, 250], { r: 0.35 }); S.mat('oak', [150, 106, 70], { r: 0.6 }); S.mat('black', [36, 38, 42], { m: 0.4, r: 0.35 });
  S.mat('fabric', [98, 118, 140], { r: 1 }); S.mat('fabric2', [196, 170, 140], { r: 1 }); S.mat('pillow', [230, 120, 80], { r: 1 }); S.mat('rug', [214, 206, 192], { r: 1 }); S.mat('bed', [244, 242, 238], { r: 1 });
  S.mat('glass', [170, 205, 225], { m: 0.3, r: 0.05, a: 0.35 }); S.mat('frame', [60, 62, 66], { m: 0.5, r: 0.4 }); S.mat('steel', [200, 202, 206], { m: 0.9, r: 0.2 });
  S.mat('lamp', [255, 238, 200], { e: 1 }); S.mat('green', [70, 130, 70], { r: 1 }); S.mat('pot', [220, 210, 196], { r: 0.8 }); S.mat('screen', [20, 22, 26], { m: 0.6, r: 0.1 });
  const L = rooms >= 3 ? 14 : rooms === 1 ? 8 : 11, W = 8, H = 2.8;
  S.box('parquet', [0, -0.05, 0], [L, 0.1, W]);
  S.box('wall', [-L / 2, H / 2, 0], [0.16, H, W]); S.box('wall', [L / 2, H / 2, 0], [0.16, H, W]);
  // наружная стена с панорамными окнами
  for (let x = -L / 2 + 0.5; x < L / 2 - 0.4; x += 3.4) {
    S.box('wall', [x + 0.05, H / 2, -W / 2], [0.5, H, 0.2]);
    S.box('glass', [x + 1.7, 1.45, -W / 2], [2.7, 2.3, 0.04]); S.box('frame', [x + 1.7, 0.28, -W / 2], [2.8, 0.1, 0.18]); S.box('frame', [x + 1.7, 2.62, -W / 2], [2.8, 0.1, 0.18]);
    S.box('frame', [x + 1.7, 1.45, -W / 2], [0.06, 2.3, 0.08]);
  }
  S.box('accent', [-L / 2 + 0.1, H / 2, 0.6], [0.04, H, 3.2]);   // акцентная стена под ТВ
  // перегородки
  const walls = rooms >= 3 ? [-L / 6, L / 6] : rooms === 2 ? [0.6] : [];
  for (const px of walls) { S.box('wall', [px, H / 2, -W / 4 - 0.5], [0.12, H, W / 2 - 1]); S.box('wall', [px, H / 2, W / 2 - 0.7], [0.12, H, 1.4]); }
  // кухня-гостиная
  const lx = -L / 2;
  S.box('screen', [lx + 0.16, 1.45, 0.6], [0.05, 0.85, 1.5]); S.box('oak', [lx + 0.35, 0.35, 0.6], [0.45, 0.4, 2.4]);
  S.box('fabric', [lx + 3.2, 0.32, 0.6], [1.0, 0.45, 2.5]); S.box('fabric', [lx + 3.62, 0.72, 0.6], [0.22, 0.6, 2.5]); for (const dz of [-1.3, 1.3]) S.box('fabric', [lx + 3.2, 0.55, 0.6 + dz], [1.0, 0.45, 0.2]);
  S.box('pillow', [lx + 3.35, 0.7, 0.1], [0.22, 0.4, 0.45]); S.box('fabric2', [lx + 3.35, 0.7, 1.15], [0.22, 0.4, 0.45]);
  S.box('rug', [lx + 2.1, 0.02, 0.6], [2.6, 0.02, 3.2]); S.box('oak', [lx + 2.0, 0.38, 0.6], [0.8, 0.06, 1.2]); for (const [dx, dz] of [[-0.3, -0.5], [0.3, -0.5], [-0.3, 0.5], [0.3, 0.5]]) S.cyl('black', [lx + 2.0 + dx, 0.18, 0.6 + dz], 0.02, 0.36, 6);
  // кухня с островом
  S.box('white', [lx + 0.35, 0.45, W / 2 - 1.8], [0.62, 0.9, 3.4]); S.box('black', [lx + 0.35, 0.92, W / 2 - 1.8], [0.64, 0.04, 3.4]); S.box('white', [lx + 0.18, 1.95, W / 2 - 1.8], [0.34, 0.7, 3.4]);
  S.box('steel', [lx + 0.35, 1.0, W / 2 - 2.6], [0.4, 0.06, 0.6]); S.box('white', [lx + 0.45, 1.0, W / 2 - 0.2], [0.7, 2.0, 0.7]);
  S.box('oak', [lx + 2.2, 0.45, W / 2 - 1.8], [0.9, 0.9, 2.0]); S.box('white', [lx + 2.2, 0.92, W / 2 - 1.8], [1.0, 0.05, 2.1]);
  for (const dz of [-0.6, 0, 0.6]) { S.cyl('black', [lx + 2.9, 0.33, W / 2 - 1.8 + dz], 0.02, 0.66, 6); S.cyl('oak', [lx + 2.9, 0.68, W / 2 - 1.8 + dz], 0.2, 0.05, 12); }
  for (const dz of [-0.6, 0, 0.6]) { S.cyl('black', [lx + 2.2, 2.2, W / 2 - 1.8 + dz], 0.006, 1.2, 4); S.sphere('lamp', [lx + 2.2, 1.55, W / 2 - 1.8 + dz], 0.12, 8); }
  S.cyl('pot', [lx + 0.6, 0.25, -W / 2 + 0.6], 0.22, 0.5, 10, 0.18); S.sphere('green', [lx + 0.6, 0.9, -W / 2 + 0.6], 0.42, 8, 1.3);
  // спальни
  const beds = rooms >= 3 ? [L / 3 - 0.2, 0.2] : rooms === 2 ? [L / 4 + 0.9] : [lx + 6];
  for (const bx of beds) {
    S.box('oak', [bx, 0.22, -W / 2 + 1.35], [1.8, 0.3, 2.1]); S.box('bed', [bx, 0.47, -W / 2 + 1.4], [1.75, 0.2, 2.0]); S.box('fabric2', [bx, 0.6, -W / 2 + 1.8], [1.8, 0.06, 1.2]);
    S.box('pillow', [bx - 0.45, 0.66, -W / 2 + 0.55], [0.6, 0.15, 0.35]); S.box('bed', [bx + 0.45, 0.66, -W / 2 + 0.55], [0.6, 0.15, 0.35]);
    S.box('oak', [bx, 0.9, -W / 2 + 0.2], [2.0, 1.4, 0.08]);
    for (const dx of [-1.25, 1.25]) { S.box('oak', [bx + dx, 0.25, -W / 2 + 0.45], [0.5, 0.5, 0.4]); S.cyl('steel', [bx + dx, 0.75, -W / 2 + 0.45], 0.1, 0.5, 10, 0.03); }
    S.box('white', [bx, 1.15, W / 2 - 0.45], [2.4, 2.3, 0.6]);
  }
  // санузел
  const bx = L / 2 - 1.5;
  S.box('tile', [bx - 1.4, H / 2, W / 2 - 1.4], [0.1, H, 2.8]); S.box('tile', [bx, 0.01, W / 2 - 1.4], [2.9, 0.02, 2.7]);
  S.box('white', [bx + 0.5, 0.3, W / 2 - 0.5], [1.7, 0.6, 0.8]); S.box('white', [bx - 0.6, 0.4, W / 2 - 2.3], [0.6, 0.1, 0.45]); S.box('steel', [bx - 0.6, 1.4, W / 2 - 2.6], [0.6, 0.8, 0.03]);
  S.box('white', [bx + 0.9, 0.25, W / 2 - 2.2], [0.5, 0.5, 0.65]);
  S.ceiling('ceil', [0, H, 0], [L, W]);
  // потолок со светильниками
  for (let x = -L / 2 + 1.6; x < L / 2; x += 2.6) for (const z of [-1.6, 1.6]) S.cyl('lamp', [x, H - 0.03, z], 0.09, 0.04, 12);
  S.save(file);
}

const out = __dirname;
moscow(path.join(out, 'msk-complex.glb'));
apartment(path.join(out, 'msk-flat-1.glb'), 1);
apartment(path.join(out, 'msk-flat-2.glb'), 2);
apartment(path.join(out, 'msk-flat-3.glb'), 3);
