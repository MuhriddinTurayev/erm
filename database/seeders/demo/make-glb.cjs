// Генератор демонстрационных 3D-моделей (GLB) для сидера: жилой дом и интерьер квартиры.
// Запуск: node database/seeders/demo/make-glb.js  → database/seeders/demo/*.glb
const fs = require('fs'), path = require('path');

function glb(boxes, file) {
  // группируем коробки по материалу → один примитив на материал
  const mats = [], byMat = new Map();
  for (const b of boxes) {
    const key = b.c.join(',') + '|' + (b.m ?? 0) + '|' + (b.r ?? 0.8) + '|' + (b.e ? 1 : 0) + '|' + (b.a ?? 1);
    if (!byMat.has(key)) { byMat.set(key, mats.length); mats.push({ c: b.c, m: b.m ?? 0, r: b.r ?? 0.8, e: b.e, a: b.a ?? 1, boxes: [] }); }
    mats[byMat.get(key)].boxes.push(b);
  }
  const bin = [], bufViews = [], accessors = [], prims = [];
  let off = 0;
  const push = (arr, target) => { const b = Buffer.from(arr.buffer); const pad = (4 - (b.length % 4)) % 4; bin.push(b, Buffer.alloc(pad)); bufViews.push({ buffer: 0, byteOffset: off, byteLength: b.length, target }); off += b.length + pad; return bufViews.length - 1; };
  const F = [[[1, 0, 0], [[1, -1, -1], [1, 1, -1], [1, 1, 1], [1, -1, 1]]], [[-1, 0, 0], [[-1, -1, 1], [-1, 1, 1], [-1, 1, -1], [-1, -1, -1]]],
    [[0, 1, 0], [[-1, 1, -1], [-1, 1, 1], [1, 1, 1], [1, 1, -1]]], [[0, -1, 0], [[-1, -1, 1], [-1, -1, -1], [1, -1, -1], [1, -1, 1]]],
    [[0, 0, 1], [[-1, -1, 1], [1, -1, 1], [1, 1, 1], [-1, 1, 1]]], [[0, 0, -1], [[1, -1, -1], [-1, -1, -1], [-1, 1, -1], [1, 1, -1]]]];
  mats.forEach((mt, mi) => {
    const pos = [], nor = [], idx = [];
    for (const b of mt.boxes) {
      const [x, y, z] = b.p, [sx, sy, sz] = b.s;
      for (const [n, vs] of F) {
        const base = pos.length / 3;
        for (const v of vs) { pos.push(x + v[0] * sx / 2, y + v[1] * sy / 2, z + v[2] * sz / 2); nor.push(...n); }
        idx.push(base, base + 1, base + 2, base, base + 2, base + 3);
      }
    }
    const P = new Float32Array(pos), N = new Float32Array(nor), I = new Uint32Array(idx);
    const min = [Infinity, Infinity, Infinity], max = [-Infinity, -Infinity, -Infinity];
    for (let i = 0; i < P.length; i += 3) for (let k = 0; k < 3; k++) { min[k] = Math.min(min[k], P[i + k]); max[k] = Math.max(max[k], P[i + k]); }
    const vp = push(P, 34962), vn = push(N, 34962), vi = push(I, 34963);
    accessors.push({ bufferView: vp, componentType: 5126, count: P.length / 3, type: 'VEC3', min, max }, { bufferView: vn, componentType: 5126, count: N.length / 3, type: 'VEC3' },
      { bufferView: vi, componentType: 5125, count: I.length, type: 'SCALAR' });
    prims.push({ attributes: { POSITION: accessors.length - 3, NORMAL: accessors.length - 2 }, indices: accessors.length - 1, material: mi });
  });
  const json = {
    asset: { version: '2.0', generator: 'Oftob_Sohtumon demo' }, scene: 0, scenes: [{ nodes: [0] }], nodes: [{ mesh: 0 }], meshes: [{ primitives: prims }],
    materials: mats.map(m => Object.assign({ pbrMetallicRoughness: { baseColorFactor: [...m.c.map(v => v / 255), m.a], metallicFactor: m.m, roughnessFactor: m.r } },
      m.e ? { emissiveFactor: m.c.map(v => v / 255 * 0.6) } : {}, m.a < 1 ? { alphaMode: 'BLEND' } : {})),
    accessors, bufferViews: bufViews, buffers: [{ byteLength: off }],
  };
  let js = Buffer.from(JSON.stringify(json)); js = Buffer.concat([js, Buffer.alloc((4 - js.length % 4) % 4, 0x20)]);
  const bb = Buffer.concat(bin), total = 12 + 8 + js.length + 8 + bb.length, h = Buffer.alloc(12);
  h.writeUInt32LE(0x46546C67, 0); h.writeUInt32LE(2, 4); h.writeUInt32LE(total, 8);
  const c1 = Buffer.alloc(8); c1.writeUInt32LE(js.length, 0); c1.writeUInt32LE(0x4E4F534A, 4);
  const c2 = Buffer.alloc(8); c2.writeUInt32LE(bb.length, 0); c2.writeUInt32LE(0x004E4942, 4);
  fs.writeFileSync(file, Buffer.concat([h, c1, js, c2, bb]));
  console.log(path.basename(file), (total / 1024).toFixed(0) + ' KB', boxes.length, 'boxes');
}

/* ---------- жилой дом: 2 секции, N этажей, витражи, балконы, кровля, двор ---------- */
function building({ floors = 12, sections = 2, file, wall = [236, 230, 220], accent = [180, 92, 60] }) {
  const B = [], H = 3, W = 24, D = 16, gap = 0.4;
  const glass = [120, 160, 190], frame = [70, 74, 80], dark = [60, 62, 66];
  B.push({ p: [0, -0.15, 0], s: [W * sections + 40, 0.3, D + 50], c: [140, 170, 110], r: 1 });                 // газон
  B.push({ p: [0, -0.05, D / 2 + 9], s: [W * sections + 30, 0.12, 8], c: [96, 98, 104], r: 0.9 });            // дорога
  B.push({ p: [0, 0, D / 2 + 3.2], s: [W * sections + 8, 0.12, 3], c: [190, 186, 178], r: 0.9 });               // тротуар
  for (let s = 0; s < sections; s++) {
    const cx = (s - (sections - 1) / 2) * (W + gap);
    // цоколь с витринами коммерции
    B.push({ p: [cx, H / 2, 0], s: [W, H, D], c: dark, r: 0.6 });
    for (let k = 0; k < 6; k++) B.push({ p: [cx - W / 2 + 2 + k * 4, H / 2 + 0.1, D / 2 + 0.03], s: [3.4, 2.4, 0.06], c: [160, 200, 225], m: 0.3, r: 0.1, e: true });
    for (let f = 1; f < floors; f++) {
      const y = f * H + H / 2;
      B.push({ p: [cx, y, 0], s: [W, H, D], c: (f % 4 === 0 ? accent : wall), r: 0.85 });
      for (let k = 0; k < 6; k++) {
        const wx = cx - W / 2 + 2 + k * 4;
        for (const zs of [1, -1]) {
          B.push({ p: [wx, y + 0.2, zs * (D / 2 + 0.02)], s: [1.8, 1.7, 0.05], c: glass, m: 0.6, r: 0.08, e: (f + k + s) % 5 === 0 });
          B.push({ p: [wx, y - 0.68, zs * (D / 2 + 0.04)], s: [2.0, 0.12, 0.08], c: frame });
        }
        if (k % 2 === 1) { B.push({ p: [wx, y - 1.4, D / 2 + 0.7], s: [3.2, 0.15, 1.4], c: [200, 200, 200] }); B.push({ p: [wx, y - 0.85, D / 2 + 1.38], s: [3.2, 1.0, 0.05], c: [150, 190, 210], a: 0.55, r: 0.1 }); }
      }
      for (let k = 0; k < 3; k++) for (const xs of [1, -1]) B.push({ p: [cx + xs * (W / 2 + 0.02), y + 0.2, -D / 2 + 3 + k * 5], s: [0.05, 1.7, 1.8], c: glass, m: 0.6, r: 0.08 });
    }
    const top = floors * H;
    B.push({ p: [cx, top + 0.3, 0], s: [W + 0.6, 0.6, D + 0.6], c: frame, r: 0.7 });                       // парапет
    B.push({ p: [cx + 6, top + 1.5, -2], s: [4, 2.4, 4], c: wall });                                        // выход на кровлю
    B.push({ p: [cx, H + 0.1, D / 2 + 1.6], s: [6, 0.2, 3.2], c: frame });                                 // козырёк входа
  }
  // двор: деревья и детская площадка
  for (let i = 0; i < 8; i++) { const x = -W * sections / 2 + 4 + i * (W * sections - 8) / 7; B.push({ p: [x, 1.2, D / 2 + 13], s: [0.35, 2.4, 0.35], c: [110, 80, 55] }); B.push({ p: [x, 3.4, D / 2 + 13], s: [2.6, 2.6, 2.6], c: [80, 140, 70], r: 1 }); }
  B.push({ p: [W * sections / 2 + 10, 0.05, 0], s: [12, 0.1, 12], c: [210, 120, 80], r: 1 });
  B.push({ p: [W * sections / 2 + 10, 1.2, 0], s: [3, 2.4, 1.5], c: [240, 190, 60] });
  glb(B, file);
}

/* ---------- интерьер 2-комнатной квартиры ---------- */
function interior(file, rooms = 2) {
  const B = [], h = 2.8, floor = [196, 160, 120], wall = [242, 238, 230], white = [250, 250, 248], dark = [52, 56, 62], wood = [150, 104, 68], fabric = [96, 120, 150];
  const L = rooms >= 3 ? 14 : rooms === 1 ? 8 : 11, Wd = 8;
  B.push({ p: [0, -0.05, 0], s: [L, 0.1, Wd], c: floor, r: 0.6 });
  B.push({ p: [0, h / 2, -Wd / 2], s: [L, h, 0.15], c: wall });                         // задняя стена
  B.push({ p: [-L / 2, h / 2, 0], s: [0.15, h, Wd], c: wall });                          // левая
  B.push({ p: [L / 2, h / 2, 0], s: [0.15, h, Wd], c: wall });                           // правая
  // окна в задней стене
  for (let x = -L / 2 + 2; x < L / 2 - 1; x += 3.5) B.push({ p: [x, 1.6, -Wd / 2 + 0.09], s: [1.8, 1.6, 0.04], c: [170, 210, 235], m: 0.4, r: 0.05, e: true });
  // перегородки между комнатами
  const parts = rooms >= 3 ? [-L / 6, L / 6] : rooms === 2 ? [0.5] : [];
  for (const px of parts) { B.push({ p: [px, h / 2, -Wd / 4], s: [0.12, h, Wd / 2], c: wall }); }
  // гостиная/кухня
  const lx = -L / 2 + 2.6;
  B.push({ p: [lx, 0.45, 0.4], s: [2.4, 0.5, 0.95], c: fabric, r: 1 }); B.push({ p: [lx, 0.8, 0.85], s: [2.4, 0.6, 0.2], c: fabric, r: 1 });   // диван
  B.push({ p: [lx, 0.25, -0.9], s: [1.2, 0.08, 0.7], c: wood });                                                                     // столик
  B.push({ p: [lx, 0.02, -0.4], s: [2.8, 0.02, 2.0], c: [210, 200, 185], r: 1 });                                                     // ковёр
  B.push({ p: [-L / 2 + 0.3, 1.3, -1.2], s: [0.08, 0.7, 1.3], c: dark, m: 0.3, r: 0.2 });                                            // ТВ
  B.push({ p: [-L / 2 + 0.6, 0.45, Wd / 2 - 0.6], s: [0.6, 0.9, 3.0], c: white });                                                   // кухня низ
  B.push({ p: [-L / 2 + 0.35, 1.9, Wd / 2 - 0.6], s: [0.35, 0.7, 3.0], c: white });                                                  // кухня верх
  B.push({ p: [-L / 2 + 2.2, 0.75, Wd / 2 - 1.6], s: [1.6, 0.06, 0.9], c: wood }); for (const dx of [-0.6, 0.6]) B.push({ p: [-L / 2 + 2.2 + dx, 0.37, Wd / 2 - 1.6], s: [0.06, 0.74, 0.8], c: dark });
  // спальни
  const beds = rooms >= 3 ? [L / 3, 0] : rooms === 2 ? [L / 4 + 0.5] : [];
  for (const bx of beds) {
    B.push({ p: [bx, 0.3, -Wd / 2 + 1.3], s: [1.8, 0.45, 2.1], c: white, r: 1 }); B.push({ p: [bx, 0.75, -Wd / 2 + 0.25], s: [1.9, 0.9, 0.12], c: wood });
    B.push({ p: [bx, 0.55, -Wd / 2 + 1.5], s: [1.8, 0.08, 1.4], c: [170, 150, 190], r: 1 });
    B.push({ p: [bx + 1.5, 1.0, Wd / 2 - 0.4], s: [1.6, 2.0, 0.6], c: wood });                                                     // шкаф
  }
  // санузел
  B.push({ p: [L / 2 - 1.2, h / 2, Wd / 2 - 1.3], s: [0.1, h, 2.6], c: [210, 225, 235] });
  B.push({ p: [L / 2 - 0.5, 0.3, Wd / 2 - 0.6], s: [0.7, 0.55, 1.6], c: white, m: 0.1, r: 0.2 });
  // светильники
  for (let x = -L / 2 + 2; x < L / 2; x += 3) B.push({ p: [x, h - 0.05, 0], s: [0.5, 0.04, 0.5], c: [255, 240, 210], e: true });
  glb(B, file);
}

const out = __dirname;
building({ floors: 12, sections: 2, file: path.join(out, 'building-a.glb') });
building({ floors: 9, sections: 3, file: path.join(out, 'building-b.glb'), wall: [226, 222, 214], accent: [70, 110, 150] });
building({ floors: 16, sections: 1, file: path.join(out, 'building-c.glb'), wall: [245, 243, 238], accent: [150, 110, 70] });
interior(path.join(out, 'interior-1.glb'), 1);
interior(path.join(out, 'interior-2.glb'), 2);
interior(path.join(out, 'interior-3.glb'), 3);
