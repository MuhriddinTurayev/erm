// Общая часть генераторов городских сцен (Москва-Сити, Ташкент): PNG, фасадные текстуры, сцена glTF с вариантами день/ночь, геометрия.
const fs = require("fs"), path = require("path"), zlib = require("zlib");

/* ---------------- PNG без библиотек ---------------- */
const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
const crc = b => { let c = 0xffffffff; for (const x of b) c = CRC[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
function png(w, h, px) {   // px(x, y) → [r, g, b]
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) { raw[y * (w * 3 + 1)] = 0; for (let x = 0; x < w; x++) { const [r, g, b] = px(x, y); const o = y * (w * 3 + 1) + 1 + x * 3; raw[o] = r; raw[o + 1] = g; raw[o + 2] = b; } }
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}
let seed = 11; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647; const setSeed = v => { seed = v; };
const clamp = v => Math.max(0, Math.min(255, Math.round(v)));
// фасадные текстуры: 8 окон × 8 этажей на тайл; u: 1 тайл = 8 окон по 1.6 м, v: 1 тайл = 8 этажей по 4 м
const T = { cols: 8, rows: 8, win: 1.6, floor: 4.0 };
function facade(kind) {
  const W = 256, H = 256, cw = W / T.cols, ch = H / T.rows;
  const lit = []; for (let i = 0; i < T.cols * T.rows; i++) lit.push(rnd());
  return png(W, H, (x, y) => {
    const cx = Math.floor(x / cw), cy = Math.floor(y / ch), fx = (x % cw) / cw, fy = (y % ch) / ch;
    const mullion = fx < 0.07 || fx > 0.97, slab = fy > 0.86;
    if (kind === 'glass') {   // стекло: лёгкий градиент, светлые импосты, тёмная полоса перекрытия
      if (slab) return [38, 42, 48]; if (mullion) return [190, 196, 204];
      const g = 0.55 + 0.35 * (1 - fy) + (lit[cy * T.cols + cx] - 0.5) * 0.25; return [clamp(120 * g), clamp(150 * g), clamp(180 * g)];
    }
    if (kind === 'night') {   // светящиеся окна: тёплые, холодные, тёмные
      if (slab || mullion) return [0, 0, 0]; const l = lit[cy * T.cols + cx];
      if (l < 0.72) return [0, 0, 0]; const k = 0.55 + (l - 0.72) * 1.6; if (l < 0.9) return [clamp(255 * k), clamp(190 * k), clamp(120 * k)]; if (l < 0.96) return [clamp(190 * k), clamp(215 * k), clamp(255 * k)]; return [255, 236, 200];
    }
    if (kind === 'stone') {   // камень с окнами для невысоких зданий
      const win = fx > 0.18 && fx < 0.82 && fy > 0.2 && fy < 0.72; if (win) return [70, 84, 100];
      const n = (lit[cy * T.cols + cx] - 0.5) * 14; return [clamp(205 + n), clamp(198 + n), clamp(186 + n)];
    }
    if (kind === 'stoneNight') { const win = fx > 0.18 && fx < 0.82 && fy > 0.2 && fy < 0.72; if (!win) return [0, 0, 0]; const l = lit[cy * T.cols + cx]; return l < 0.55 ? [0, 0, 0] : [255, 200, 130]; }
    if (kind === 'concrete') { const n = (lit[(cy * T.cols + cx) % 64] - 0.5) * 18 + (fy > 0.9 ? -25 : 0); return [clamp(170 + n), clamp(168 + n), clamp(162 + n)]; }
    if (kind === 'asphalt') { const n = (rnd() - 0.5) * 16; return [clamp(66 + n), clamp(68 + n), clamp(72 + n)]; }
    return [255, 255, 255];
  });
}

/* ---------------- сцена ---------------- */
class Scene {
  constructor() { this.mats = []; this.matIdx = {}; this.images = []; this.textures = []; this.tex = {}; this.geo = new Map(); }
  image(name, buf) { this.images.push(buf); this.textures.push({ source: this.images.length - 1, sampler: 0 }); this.tex[name] = this.textures.length - 1; }
  // материал: дневной и (по желанию) ночной вариант
  mat(name, day, night = null) { this.matIdx[name] = this.mats.length; this.mats.push({ day, night }); this.geo.set(name, { pos: [], nor: [], uv: [], idx: [] }); return name; }
  // повторяемый объект (дерево, машина, фонарь): геометрия один раз, экземпляры через EXT_mesh_gpu_instancing
  group(gname, fn) { const g = Object.create(this); g.geo = new Map([...this.geo.keys()].map(k => [k, { pos: [], nor: [], uv: [], idx: [] }])); fn(g); (this.groups ||= {})[gname] = { geo: g.geo, inst: [] }; }
  // по умолчанию экземпляр «впекается» в общую геометрию: model-viewer 3.5 не умеет менять варианты у InstancedMesh
  place(gname, [x, y, z], s = 1, ry = 0) {
    const g = this.groups[gname]; if (this.instancing) { g.inst.push([x, y, z, ry, s]); return; }
    const c = Math.cos(ry), sn = Math.sin(ry);
    for (const [name, G] of g.geo) {
      if (!G.idx.length) continue; const D = this.geo.get(name), b = D.pos.length / 3;
      for (let k = 0; k < G.pos.length; k += 3) { const px = G.pos[k] * s, py = G.pos[k + 1] * s, pz = G.pos[k + 2] * s, nx = G.nor[k], nz = G.nor[k + 2];
        D.pos.push(x + px * c + pz * sn, y + py, z - px * sn + pz * c); D.nor.push(nx * c + nz * sn, G.nor[k + 1], -nx * sn + nz * c); }
      for (const v of G.uv) D.uv.push(v); for (const i of G.idx) D.idx.push(b + i);
    }
  }
  _push(name, v, n, uv, faces) { const G = this.geo.get(name); const b = G.pos.length / 3; G.pos.push(...v); G.nor.push(...n); G.uv.push(...uv); for (const f of faces) G.idx.push(b + f[0], b + f[1], b + f[2]); }
  quad(name, a, b, c, d, n, uvs) { this._push(name, [...a, ...b, ...c, ...d], [...n, ...n, ...n, ...n], uvs || [0, 0, 1, 0, 1, 1, 0, 1], [[0, 1, 2], [0, 2, 3]]); }
  box(name, [x, y, z], [sx, sy, sz], ry = 0) {
    const c = Math.cos(ry), s = Math.sin(ry), P = (px, py, pz) => [x + px * c + pz * s, y + py, z - px * s + pz * c], N = (nx, ny, nz) => [nx * c + nz * s, ny, -nx * s + nz * c];
    const hx = sx / 2, hy = sy / 2, hz = sz / 2;
    this.quad(name, P(hx, -hy, hz), P(hx, -hy, -hz), P(hx, hy, -hz), P(hx, hy, hz), N(1, 0, 0), [0, 0, sz, 0, sz, sy, 0, sy]);
    this.quad(name, P(-hx, -hy, -hz), P(-hx, -hy, hz), P(-hx, hy, hz), P(-hx, hy, -hz), N(-1, 0, 0), [0, 0, sz, 0, sz, sy, 0, sy]);
    this.quad(name, P(-hx, hy, hz), P(hx, hy, hz), P(hx, hy, -hz), P(-hx, hy, -hz), N(0, 1, 0), [0, 0, sx, 0, sx, sz, 0, sz]);
    this.quad(name, P(-hx, -hy, -hz), P(hx, -hy, -hz), P(hx, -hy, hz), P(-hx, -hy, hz), N(0, -1, 0), [0, 0, sx, 0, sx, sz, 0, sz]);
    this.quad(name, P(-hx, -hy, hz), P(hx, -hy, hz), P(hx, hy, hz), P(-hx, hy, hz), N(0, 0, 1), [0, 0, sx, 0, sx, sy, 0, sy]);
    this.quad(name, P(hx, -hy, -hz), P(-hx, -hy, -hz), P(-hx, hy, -hz), P(hx, hy, -hz), N(0, 0, -1), [0, 0, sx, 0, sx, sy, 0, sy]);
  }
  cyl(name, [x, y, z], r, h, seg = 10) {
    for (let i = 0; i < seg; i++) {
      const a0 = i / seg * Math.PI * 2, a1 = (i + 1) / seg * Math.PI * 2, n = [Math.cos((a0 + a1) / 2), 0, Math.sin((a0 + a1) / 2)];
      this.quad(name, [x + Math.cos(a0) * r, y, z + Math.sin(a0) * r], [x + Math.cos(a1) * r, y, z + Math.sin(a1) * r], [x + Math.cos(a1) * r, y + h, z + Math.sin(a1) * r], [x + Math.cos(a0) * r, y + h, z + Math.sin(a0) * r], n);
    }
  }
  sphere(name, [x, y, z], r, seg = 8, sy = 1) {
    const v = [], n = [], uv = [], f = [];
    for (let i = 0; i <= seg; i++) for (let j = 0; j <= seg; j++) { const t = i / seg * Math.PI, p = j / seg * Math.PI * 2, nx = Math.sin(t) * Math.cos(p), ny = Math.cos(t), nz = Math.sin(t) * Math.sin(p); v.push(x + nx * r, y + ny * r * sy, z + nz * r); n.push(nx, ny, nz); uv.push(j / seg, i / seg); }
    for (let i = 0; i < seg; i++) for (let j = 0; j < seg; j++) { const a = i * (seg + 1) + j, b = a + seg + 1; f.push([a, b, a + 1], [b, b + 1, a + 1]); }
    this._push(name, v, n, uv, f);
  }
  // выдавливание контура (здание): стены с фасадной развёрткой и крыша
  extrude(wall, roof, ring, y0, y1, t = T) {
    if (ring.length < 3) return;
    if (area(ring) < 0) ring = ring.slice().reverse();   // против часовой
    let u = 0;
    for (let i = 0; i < ring.length; i++) {
      const [ax, az] = ring[i], [bx, bz] = ring[(i + 1) % ring.length], len = Math.hypot(bx - ax, bz - az);
      if (len < 0.05) continue;
      let n = [(bz - az) / len, 0, -(bx - ax) / len]; const [ccx, ccz] = centroid(ring); if (n[0] * ((ax + bx) / 2 - ccx) + n[2] * ((az + bz) / 2 - ccz) < 0) n = [-n[0], 0, -n[2]];
      const uw = t.cols * t.win, vh = t.rows * t.floor;
      this.quad(wall, [ax, y0, az], [bx, y0, bz], [bx, y1, bz], [ax, y1, az], n, [u / uw, y0 / vh, (u + len) / uw, y0 / vh, (u + len) / uw, y1 / vh, u / uw, y1 / vh]);
      u += len;
    }
    const tri = earcut(ring); const v = [], nn = [], uv = [];
    for (const [x, z] of ring) { v.push(x, y1, z); nn.push(0, 1, 0); uv.push(x / 20, z / 20); }
    this._push(roof, v, nn, uv, tri.map(t => [t[0], t[2], t[1]]));
  }
  // плоский многоугольник (вода, площадь, газон)
  flat(name, ring, y, scale = 20) {
    if (ring.length < 3) return; if (area(ring) < 0) ring = ring.slice().reverse();
    const v = [], n = [], uv = []; for (const [x, z] of ring) { v.push(x, y, z); n.push(0, 1, 0); uv.push(x / scale, z / scale); }
    this._push(name, v, n, uv, earcut(ring).map(t => [t[0], t[2], t[1]]));
  }
  // лента вдоль линии (дорога, разметка)
  ribbon(name, pts, w, y, uScale = 10) {
    let u = 0;
    for (let i = 0; i < pts.length - 1; i++) {
      const [ax, az] = pts[i], [bx, bz] = pts[i + 1], len = Math.hypot(bx - ax, bz - az); if (len < 0.01) continue;
      const nx = -(bz - az) / len * w / 2, nz = (bx - ax) / len * w / 2;
      this.quad(name, [ax - nx, y, az - nz], [bx - nx, y, bz - nz], [bx + nx, y, bz + nz], [ax + nx, y, az + nz], [0, 1, 0], [u / uScale, 0, (u + len) / uScale, 0, (u + len) / uScale, 1, u / uScale, 1]);
      u += len;
    }
  }
  save(file, gen = "Moscow City", node = "MoscowCity") {
    const bin = [], views = [], acc = [], prims = [], materials = [];
    let off = 0;
    const push = (buf, target) => { const pad = (4 - buf.length % 4) % 4; bin.push(buf, Buffer.alloc(pad)); const v = { buffer: 0, byteOffset: off, byteLength: buf.length }; if (target) v.target = target; views.push(v); off += buf.length + pad; return views.length - 1; };
    const images = this.images.map(b => ({ bufferView: push(b), mimeType: 'image/png' }));
    const matJson = m => {
      const o = { name: m.name, pbrMetallicRoughness: { baseColorFactor: [...m.c.map(v => v / 255), m.a ?? 1], metallicFactor: m.m ?? 0, roughnessFactor: m.r ?? 0.8 } };
      if (m.tex !== undefined) o.pbrMetallicRoughness.baseColorTexture = { index: this.tex[m.tex] };
      if (m.etex !== undefined) { o.emissiveTexture = { index: this.tex[m.etex] }; o.emissiveFactor = m.e ?? [1, 1, 1]; }
      else if (m.e) o.emissiveFactor = m.e;
      if (m.e && Math.max(...m.e) > 1) { o.extensions = { KHR_materials_emissive_strength: { emissiveStrength: Math.max(...m.e) } }; o.emissiveFactor = m.e.map(v => v / Math.max(...m.e)); }
      o.doubleSided = true;
      if ((m.a ?? 1) < 1) o.alphaMode = 'BLEND';
      return o;
    };
    const matCache = {};
    // у экземпляров (InstancedMesh) three.js теряет связь с KHR_materials_variants — им отдельный дневной материал «inst-…», ночью его притеняет просмотрщик
    const prepInst = (name, M) => { const k = 'inst-' + name; if (!matCache[k]) { materials.push(matJson({ name: k, ...M.day })); matCache[k] = [materials.length - 1]; } return matCache[k][0]; };
    const prepMat = (name, M) => { if (!matCache[name]) { materials.push(matJson({ name: name + '-day', ...M.day })); const d = materials.length - 1; let nn = d; if (M.night) { materials.push(matJson({ name: name + '-night', ...M.night })); nn = materials.length - 1; } matCache[name] = [d, nn]; } return matCache[name]; };
    const primsOf = (geo, prims, inst = false) => this.mats.forEach((M, i) => {
      const name = Object.keys(this.matIdx).find(k => this.matIdx[k] === i), G = geo.get(name);
      if (!G.idx.length) return;
      const [dayIdx, nightIdx] = inst ? [prepInst(name, M)] : prepMat(name, M);
      const P = new Float32Array(G.pos), N = new Float32Array(G.nor), U = new Float32Array(G.uv), I = new Uint32Array(G.idx);
      const min = [1e9, 1e9, 1e9], max = [-1e9, -1e9, -1e9]; for (let k = 0; k < P.length; k += 3) for (let a = 0; a < 3; a++) { min[a] = Math.min(min[a], P[k + a]); max[a] = Math.max(max[a], P[k + a]); }
      acc.push({ bufferView: push(Buffer.from(P.buffer), 34962), componentType: 5126, count: P.length / 3, type: 'VEC3', min, max }, { bufferView: push(Buffer.from(N.buffer), 34962), componentType: 5126, count: N.length / 3, type: 'VEC3' },
        { bufferView: push(Buffer.from(U.buffer), 34962), componentType: 5126, count: U.length / 2, type: 'VEC2' }, { bufferView: push(Buffer.from(I.buffer), 34963), componentType: 5125, count: I.length, type: 'SCALAR' });
      const prim = { attributes: { POSITION: acc.length - 4, NORMAL: acc.length - 3, TEXCOORD_0: acc.length - 2 }, indices: acc.length - 1, material: dayIdx };
      if (!inst) prim.extensions = { KHR_materials_variants: { mappings: [{ material: dayIdx, variants: [0] }, { material: nightIdx, variants: [1] }] } };
      prims.push(prim);
    });
    primsOf(this.geo, prims);
    const meshes = [{ primitives: prims }], nodes = [{ mesh: 0, name: node }];
    for (const [gname, g] of Object.entries(this.groups || {})) {
      if (!g.inst.length) continue;
      const gp = []; primsOf(g.geo, gp, true); if (!gp.length) continue;
      const T = new Float32Array(g.inst.length * 3), R = new Float32Array(g.inst.length * 4), S = new Float32Array(g.inst.length * 3);
      g.inst.forEach(([x, y, z, ry, sc], k) => { T.set([x, y, z], k * 3); R.set([0, Math.sin(ry / 2), 0, Math.cos(ry / 2)], k * 4); S.set([sc, sc, sc], k * 3); });
      acc.push({ bufferView: push(Buffer.from(T.buffer)), componentType: 5126, count: g.inst.length, type: 'VEC3' }, { bufferView: push(Buffer.from(R.buffer)), componentType: 5126, count: g.inst.length, type: 'VEC4' },
        { bufferView: push(Buffer.from(S.buffer)), componentType: 5126, count: g.inst.length, type: 'VEC3' });
      meshes.push({ primitives: gp });
      nodes.push({ mesh: meshes.length - 1, name: gname, extensions: { EXT_mesh_gpu_instancing: { attributes: { TRANSLATION: acc.length - 3, ROTATION: acc.length - 2, SCALE: acc.length - 1 } } } });
    }
    const used = ['KHR_materials_variants', 'KHR_materials_emissive_strength']; if (nodes.length > 1) used.push('EXT_mesh_gpu_instancing');
    const json = {
      asset: { version: '2.0', generator: 'Oftob_Sohtumon · ' + gen + ' (OSM © contributors, ODbL)' }, extensionsUsed: used,
      extensions: { KHR_materials_variants: { variants: [{ name: 'day' }, { name: 'night' }] } },
      scene: 0, scenes: [{ nodes: nodes.map((_, i) => i) }], nodes, meshes, materials, images, textures: this.textures,
      samplers: [{ magFilter: 9729, minFilter: 9987, wrapS: 10497, wrapT: 10497 }], accessors: acc, bufferViews: views, buffers: [{ byteLength: off }],
    };
    let js = Buffer.from(JSON.stringify(json)); js = Buffer.concat([js, Buffer.alloc((4 - js.length % 4) % 4, 0x20)]);
    const bb = Buffer.concat(bin), total = 28 + js.length + bb.length, h = Buffer.alloc(12);
    h.writeUInt32LE(0x46546C67, 0); h.writeUInt32LE(2, 4); h.writeUInt32LE(total, 8);
    const c1 = Buffer.alloc(8); c1.writeUInt32LE(js.length, 0); c1.writeUInt32LE(0x4E4F534A, 4);
    const c2 = Buffer.alloc(8); c2.writeUInt32LE(bb.length, 0); c2.writeUInt32LE(0x004E4942, 4);
    fs.writeFileSync(file, Buffer.concat([h, c1, js, c2, bb]));
    console.log(path.basename(file), (total / 1048576).toFixed(1) + ' MB');
  }
}

/* ---------------- геометрия ---------------- */
function area(r) { let s = 0; for (let i = 0; i < r.length; i++) { const [a, b] = r[i], [c, d] = r[(i + 1) % r.length]; s += a * d - c * b; } return s / 2; }
function earcut(ring) {   // ушная триангуляция (контур против часовой стрелки)
  const n = ring.length, idx = [...Array(n).keys()], out = [];
  const cross = (a, b, c) => (ring[b][0] - ring[a][0]) * (ring[c][1] - ring[a][1]) - (ring[b][1] - ring[a][1]) * (ring[c][0] - ring[a][0]);
  const inside = (p, a, b, c) => { const [px, pz] = ring[p], s = (u, v) => (ring[v][0] - ring[u][0]) * (pz - ring[u][1]) - (ring[v][1] - ring[u][1]) * (px - ring[u][0]); return s(a, b) >= 0 && s(b, c) >= 0 && s(c, a) >= 0; };
  let guard = 0;
  while (idx.length > 3 && guard++ < 5000) {
    let cut = false;
    for (let i = 0; i < idx.length; i++) {
      const a = idx[(i + idx.length - 1) % idx.length], b = idx[i], c = idx[(i + 1) % idx.length];
      if (cross(a, b, c) <= 0) continue;
      if (idx.some(p => p !== a && p !== b && p !== c && inside(p, a, b, c))) continue;
      out.push([a, b, c]); idx.splice(i, 1); cut = true; break;
    }
    if (!cut) break;
  }
  if (idx.length === 3) out.push([idx[0], idx[1], idx[2]]);
  return out;
}
function inPoly([x, z], ring) { let c = false; for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) { const [xi, zi] = ring[i], [xj, zj] = ring[j]; if ((zi > z) !== (zj > z) && x < (xj - xi) * (z - zi) / (zj - zi) + xi) c = !c; } return c; }
const centroid = r => [r.reduce((s, p) => s + p[0], 0) / r.length, r.reduce((s, p) => s + p[1], 0) / r.length];

// сжатие gltfpack: квантование + EXT_meshopt_compression (в 5–7 раз меньше; декодер — public/assets/vendor/meshopt_decoder.js)
function pack(file) {
  const tmp = file.replace(/.glb$/, ".pack.glb");
  require("child_process").execSync(`npx -y gltfpack@0.21.0 -i "${file}" -o "${tmp}" -cc -vp 16 -vt 16 -km`, { stdio: "ignore" });
  fs.renameSync(tmp, file); console.log(path.basename(file), "→", (fs.statSync(file).size / 1048576).toFixed(1) + " MB");
}
module.exports = { pack, png, rnd, setSeed, clamp, T, facade, Scene, area, earcut, inPoly, centroid };
