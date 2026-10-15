/* Oftob — старый FBX (версии 5–6: 3ds Max 2006–2010, Maya 2008–2010, старый Blender) → three.js.
   Загрузчик three.js читает только FBX 7+ и на старом файле пишет «FBX version not supported». Здесь — свой разбор:
   двоичный и текстовый FBX 6, сетки (вершины, многоугольники, нормали, UV, материалы по граням), иерархия и положение
   объектов, материалы (цвет, прозрачность) и текстуры (ищутся среди выбранных файлов по имени). Анимация не нужна. */
import * as THREE from 'three';

const DEG = Math.PI / 180;

/** Версия FBX: 6100, 7400…; 0 — не FBX */
export function fbxVersion(buffer) {
  const u8 = new Uint8Array(buffer, 0, Math.min(buffer.byteLength, 8192));
  const head = new TextDecoder('latin1').decode(u8);
  if (head.startsWith('Kaydara FBX Binary')) return new DataView(buffer).getUint32(23, true);
  const m = head.match(/FBXVersion:\s*(\d+)/) || head.match(/FBX\s+(\d)\.(\d)\.?(\d)?\s+project file/);
  if (!m) return 0;
  return m[2] !== undefined ? +m[1] * 1000 + +m[2] * 100 : +m[1];
}

/* ---------- дерево узлов: { name, props: [], children: [] } ---------- */

/** Двоичный FBX (версии до 7500 — смещения 32-битные) */
function parseBinary(buffer, unzlib) {
  const dv = new DataView(buffer), u8 = new Uint8Array(buffer), version = dv.getUint32(23, true), big = version >= 7500;
  const dec = new TextDecoder('utf-8');
  let pos = 27;
  const u32 = () => { const v = dv.getUint32(pos, true); pos += 4; return v; };
  const off = () => { if (big) { const v = Number(dv.getBigUint64(pos, true)); pos += 8; return v; } return u32(); };
  const arr = (type) => {
    const len = u32(), enc = u32(), clen = u32();
    let data = u8.subarray(pos, pos + clen); pos += clen;
    if (enc === 1) data = unzlib(data);
    const ab = data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength);
    switch (type) {
      case 'f': return new Float32Array(ab, 0, len);
      case 'd': return new Float64Array(ab, 0, len);
      case 'i': return new Int32Array(ab, 0, len);
      case 'l': { const b = new BigInt64Array(ab, 0, len); return Array.from(b, Number); }
      case 'b': return new Uint8Array(ab, 0, len);
      default: return [];
    }
  };
  const prop = () => {
    const t = String.fromCharCode(u8[pos++]);
    switch (t) {
      case 'Y': { const v = dv.getInt16(pos, true); pos += 2; return v; }
      case 'C': return !!u8[pos++];
      case 'I': { const v = dv.getInt32(pos, true); pos += 4; return v; }
      case 'F': { const v = dv.getFloat32(pos, true); pos += 4; return v; }
      case 'D': { const v = dv.getFloat64(pos, true); pos += 8; return v; }
      case 'L': { const v = Number(dv.getBigInt64(pos, true)); pos += 8; return v; }
      case 'S': case 'R': { const n = u32(); const s = u8.subarray(pos, pos + n); pos += n; return t === 'S' ? dec.decode(s) : s; }
      case 'f': case 'd': case 'i': case 'l': case 'b': return arr(t);
      default: throw new Error('FBX: unknown property type ' + t);
    }
  };
  const node = () => {
    const end = off(), np = off(); off();
    const nl = u8[pos++];
    if (end === 0) return null;   // пустая запись — конец списка
    const name = dec.decode(u8.subarray(pos, pos + nl)); pos += nl;
    const props = [];
    for (let i = 0; i < np; i++) props.push(prop());
    const children = [];
    while (pos < end) { const c = node(); if (!c) break; children.push(c); }
    pos = end;
    return { name, props, children };
  };
  const root = { name: '', props: [], children: [] };
  while (pos < u8.length - 13) { const c = node(); if (!c) break; root.children.push(c); }
  return root;
}

/** Текстовый FBX: «Имя: знач, знач, … { … }»; длинные массивы продолжаются на следующих строках с запятой */
function parseAscii(text) {
  let i = 0;
  const n = text.length;
  const ws = () => { for (;;) { while (i < n && /\s/.test(text[i])) i++; if (text[i] === ';') { while (i < n && text[i] !== '\n') i++; } else return; } };
  const spaces = () => { while (i < n && (text[i] === ' ' || text[i] === '\t' || text[i] === '\r')) i++; };
  const token = () => {
    if (text[i] === '"') { const j = text.indexOf('"', i + 1); const s = text.slice(i + 1, j < 0 ? n : j); i = j < 0 ? n : j + 1; return s; }
    const st = i;
    while (i < n && !/[\s,{}"]/.test(text[i])) i++;
    const w = text.slice(st, i);
    if (w === '' ) return undefined;
    if (w[0] === '*') return undefined;   // FBX 7: «*N» — длина массива
    const v = Number(w);
    return Number.isNaN(v) ? w : v;
  };
  const block = () => {
    const list = [];
    for (;;) {
      ws();
      if (i >= n) return list;
      if (text[i] === '}') { i++; return list; }
      const c = text.indexOf(':', i), nl = text.indexOf('\n', i);
      if (c < 0 || (nl >= 0 && nl < c && !/^\s*$/.test(text.slice(i, nl)))) { i = nl < 0 ? n : nl + 1; continue; }   // строка не узел — пропускаем
      const node = { name: text.slice(i, c).trim(), props: [], children: [] };
      i = c + 1;
      for (;;) {
        spaces();
        if (text[i] === '{') { i++; node.children = block(); break; }
        if (text[i] === '\n' || i >= n || text[i] === '}') {
          // продолжение массива: следующая непустая строка начинается с запятой
          let j = i; while (j < n && /\s/.test(text[j])) j++;
          if (text[j] === ',') { i = j; continue; }
          break;
        }
        if (text[i] === ',') { i++; continue; }
        const t = token();
        if (t !== undefined) node.props.push(t); else if (i < n && !/[\s,{}]/.test(text[i])) i++;
      }
      list.push(node);
    }
  };
  return { name: '', props: [], children: block() };
}

/* ---------- сцена из дерева FBX 6 ---------- */
const kid = (node, name) => node && node.children.find((c) => c.name === name);
const kids = (node, name) => (node ? node.children.filter((c) => c.name === name) : []);
/** Числа узла: массив (двоичный) или все числовые значения (текстовый) */
const nums = (node) => {
  if (!node) return [];
  if (node.props.length === 1 && node.props[0] && typeof node.props[0] === 'object' && 'length' in node.props[0]) return Array.from(node.props[0]);
  const out = [];
  for (const p of node.props) { if (typeof p === 'number') out.push(p); else if (p && typeof p === 'object' && 'length' in p) for (const v of p) out.push(Number(v)); }
  return out;
};
/** Имена объектов: «Model::Box01»; в двоичном FBX — «Box01\x00\x01Model» */
const idOf = (s) => { s = String(s ?? ''); const k = s.indexOf('\x00\x01'); return k >= 0 ? s.slice(k + 2) + '::' + s.slice(0, k) : s; };
const shortName = (s) => { const k = s.indexOf('::'); return k >= 0 ? s.slice(k + 2) : s; };
/** Свойства объекта (Properties60: «Property: имя, тип, флаги, значения…»; Properties70: «P: …») */
function propsOf(obj) {
  const out = {};
  for (const block of [kid(obj, 'Properties60'), kid(obj, 'Properties70')]) {
    if (!block) continue;
    for (const p of block.children) {
      if (p.name !== 'Property' && p.name !== 'P') continue;
      const skip = p.name === 'P' ? 4 : 3;
      out[p.props[0]] = p.props.slice(skip);
    }
  }
  return out;
}
const vec3 = (v, d) => (v && v.length >= 3 ? new THREE.Vector3(+v[0], +v[1], +v[2]) : d.clone());

/** Слой (нормали, UV, материал): { data, at(угол, вершина, многоугольник) → номер значения или -1 } */
function layer(el, dataName, indexName) {
  if (!el) return null;
  const map = String((kid(el, 'MappingInformationType') || { props: [''] }).props[0]);
  const ref = String((kid(el, 'ReferenceInformationType') || { props: [''] }).props[0]);
  const data = nums(kid(el, dataName)), idx = indexName ? nums(kid(el, indexName)) : [];
  const useIdx = /IndexToDirect|Index/.test(ref) && idx.length;
  return { data, at: (pvi, vi, pi) => {
    let k = map === 'ByPolygonVertex' ? pvi : map === 'ByPolygon' ? pi : map === 'AllSame' ? 0 : vi;   // ByVertice / ByVertex — по вершине
    if (useIdx) k = idx[k];
    return k === undefined || k < 0 ? -1 : k;
  } };
}

function geometryOf(obj, nMat) {
  const V = nums(kid(obj, 'Vertices')), P = nums(kid(obj, 'PolygonVertexIndex'));
  if (!V.length || !P.length) return null;
  const nrm = layer(kid(obj, 'LayerElementNormal'), 'Normals', 'NormalsIndex');
  const uv = layer(kid(obj, 'LayerElementUV'), 'UV', 'UVIndex');
  const mat = layer(kid(obj, 'LayerElementMaterial'), 'Materials', null);
  const buckets = new Map();   // материал → {p, n, u}
  const bucket = (m) => { if (!buckets.has(m)) buckets.set(m, { p: [], n: [], u: [] }); return buckets.get(m); };
  let poly = [], pi = 0;
  for (let k = 0; k < P.length; k++) {
    let v = P[k], last = false;
    if (v < 0) { v = -v - 1; last = true; }
    poly.push({ v, k });
    if (!last) continue;
    const mk = mat ? mat.at(0, 0, pi) : -1, m = Math.max(0, Math.min(nMat - 1, mk >= 0 ? mat.data[mk] | 0 : 0)), b = bucket(m);
    for (let t = 1; t + 1 < poly.length; t++) {
      for (const c of [poly[0], poly[t], poly[t + 1]]) {
        b.p.push(V[c.v * 3], V[c.v * 3 + 1], V[c.v * 3 + 2]);
        const nk = nrm ? nrm.at(c.k, c.v, pi) : -1; if (nk >= 0) b.n.push(nrm.data[nk * 3], nrm.data[nk * 3 + 1], nrm.data[nk * 3 + 2]); else b.n.push(0, 0, 1);
        const uk = uv ? uv.at(c.k, c.v, pi) : -1; if (uk >= 0) b.u.push(uv.data[uk * 2], uv.data[uk * 2 + 1]); else b.u.push(0, 0);
      }
    }
    poly = []; pi++;
  }
  const list = [...buckets.entries()].sort((a, c) => a[0] - c[0]), total = list.reduce((s, [, b]) => s + b.p.length / 3, 0);
  if (!total) return null;
  const geo = new THREE.BufferGeometry(), pos = new Float32Array(total * 3), nor = new Float32Array(total * 3), uvs = new Float32Array(total * 2);
  let start = 0;
  for (const [m, b] of list) {
    const cnt = b.p.length / 3;
    pos.set(b.p, start * 3); nor.set(b.n, start * 3); uvs.set(b.u, start * 2);
    geo.addGroup(start, cnt, m); start += cnt;
  }
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  if (nrm) geo.setAttribute('normal', new THREE.BufferAttribute(nor, 3)); else geo.computeVertexNormals();
  if (uv) geo.setAttribute('uv', new THREE.BufferAttribute(uvs, 2));
  return geo;
}

/** Старый FBX → THREE.Group; manager — тот же, что у остальных форматов (текстуры среди выбранных файлов) */
/** Дерево узлов файла (двоичного или текстового) */
export async function fbxTree(buffer) {
  if (new TextDecoder('latin1').decode(new Uint8Array(buffer, 0, 18)) === 'Kaydara FBX Binary') {
    const { unzlibSync } = await import('three/addons/libs/fflate.module.js');
    return parseBinary(buffer, unzlibSync);
  }
  return parseAscii(new TextDecoder('utf-8').decode(buffer));
}

export async function parseFbx6(buffer, manager) {
  const tree = await fbxTree(buffer);
  // FBX 6: объекты в «Objects»; FBX 5 — прямо в корне файла
  const objects = kid(tree, 'Objects') || tree;
  const byId = new Map();
  for (const o of objects.children) if (o.props.length) byId.set(idOf(o.props[0]), o);
  // связи: «OO, ребёнок, родитель» / «OP, ребёнок, родитель, свойство»
  const links = [];
  for (const c of kids(kid(tree, 'Connections'), 'Connect').concat(kids(kid(tree, 'Connections'), 'C'))) {
    if (c.props.length >= 3) links.push({ type: String(c.props[0]), child: idOf(c.props[1]), parent: idOf(c.props[2]), prop: c.props[3] });
  }
  const childrenOf = (id) => links.filter((l) => l.parent === id).map((l) => l.child);
  const parentOf = (id) => { const l = links.find((x) => x.child === id && (byId.get(x.parent) || {}).name === 'Model'); return l ? l.parent : null; };

  // текстуры: файл по имени среди выбранных; TGA — своим загрузчиком
  const texCache = new Map();
  let TGA = null;
  const texture = async (tex) => {
    const file = String((kid(tex, 'RelativeFilename') || kid(tex, 'FileName') || { props: [''] }).props[0] || '').replace(/\\/g, '/').split('/').pop();
    if (!file) return null;
    if (texCache.has(file)) return texCache.get(file);
    let t = null;
    if (/\.tga$/i.test(file)) { if (!TGA) TGA = (await import('three/addons/loaders/TGALoader.js')).TGALoader; t = new TGA(manager).load(file); }
    else if (!/\.(dds|psd)$/i.test(file)) t = new THREE.TextureLoader(manager).load(file);
    if (t) { t.wrapS = t.wrapT = THREE.RepeatWrapping; t.colorSpace = THREE.SRGBColorSpace; }
    texCache.set(file, t);
    return t;
  };
  const matCache = new Map();
  const material = async (id) => {
    if (matCache.has(id)) return matCache.get(id);
    const node = byId.get(id), P = propsOf(node);
    const col = P.DiffuseColor || P.Diffuse || [0.8, 0.8, 0.8], factor = +((P.DiffuseFactor || [1])[0]);
    const opacity = P.Opacity ? +P.Opacity[0] : P.TransparencyFactor ? 1 - +P.TransparencyFactor[0] : 1;
    const m = new THREE.MeshPhongMaterial({ name: shortName(id), color: new THREE.Color(+col[0] * factor, +col[1] * factor, +col[2] * factor), side: THREE.DoubleSide });
    if (opacity < 0.999) { m.opacity = Math.max(0.05, opacity); m.transparent = true; }
    if (P.Shininess || P.ShininessExponent) m.shininess = +(P.Shininess || P.ShininessExponent)[0];
    const texId = childrenOf(id).find((c) => (byId.get(c) || {}).name === 'Texture');
    if (texId) { const t = await texture(byId.get(texId)); if (t) { m.map = t; m.color.setRGB(1, 1, 1); } }
    matCache.set(id, m);
    return m;
  };

  // положение объектов — по правилам FBX: перенос, смещения и опорные точки, пред- и пост-поворот, порядок поворота,
  // наследование масштаба от родителя (InheritType); сетки «запекаем» в мировые координаты — так верно и при перекосе
  const ORDERS = ['ZYX', 'YZX', 'XZY', 'ZXY', 'YXZ', 'XYZ'];   // FBX XYZ, XZY, YZX, YXZ, ZXY, ZYX → порядок Euler в three.js
  const zero = new THREE.Vector3(), one = new THREE.Vector3(1, 1, 1);
  const at = (v) => new THREE.Matrix4().setPosition(vec3(v, zero));
  const rotM = (v, order) => { const r = vec3(v, zero); return new THREE.Matrix4().makeRotationFromEuler(new THREE.Euler(r.x * DEG, r.y * DEG, r.z * DEG, order)); };
  const models = new Map();
  for (const [id, o] of byId) if (o.name === 'Model' && !/Light|Camera/i.test(String(o.props[1] || ''))) models.set(id, { o, P: propsOf(o) });
  const globals = new Map();
  function globalOf(id, depth = 0) {
    if (globals.has(id)) return globals.get(id);
    const { P } = models.get(id), order = ORDERS[+((P.RotationOrder || [0])[0]) || 0] || 'ZYX', inherit = +((P.InheritType || [0])[0]) || 0;
    const T = at(P['Lcl Translation']), Roff = at(P.RotationOffset), Rp = at(P.RotationPivot), Soff = at(P.ScalingOffset), Sp = at(P.ScalingPivot);
    const Rpre = rotM(P.PreRotation, 'ZYX'), R = rotM(P['Lcl Rotation'], order), Rpost = rotM(P.PostRotation, 'ZYX').invert();
    const S = new THREE.Matrix4().scale(vec3(P['Lcl Scaling'], one));
    const local = T.clone().multiply(Roff).multiply(Rp).multiply(Rpre).multiply(R).multiply(Rpost).multiply(Rp.clone().invert())
      .multiply(Soff).multiply(Sp).multiply(S).multiply(Sp.clone().invert());
    const pid = parentOf(id);
    let g;
    if (!pid || !models.has(pid) || depth > 64) g = local;
    else {
      // как FBXLoader three.js: поворот и масштаб родителя применяются по-разному в зависимости от InheritType
      const PG = globalOf(pid, depth + 1).world, PL = globalOf(pid, depth + 1).local;
      const pR = new THREE.Matrix4().extractRotation(PG), pT = new THREE.Matrix4().copyPosition(PG);
      const pRS = pT.clone().invert().multiply(PG), pS = pR.clone().invert().multiply(pRS);
      const lR = Rpre.clone().multiply(R).multiply(Rpost);
      const RS = inherit === 1 ? pR.clone().multiply(pS).multiply(lR).multiply(S)
        : inherit === 2 ? pR.clone().multiply(lR).multiply(pS.clone().multiply(new THREE.Matrix4().scale(new THREE.Vector3().setFromMatrixScale(PL)).invert())).multiply(S)
          : pR.clone().multiply(lR).multiply(pS).multiply(S);
      const gT = new THREE.Matrix4().copyPosition(PG.clone().multiply(new THREE.Matrix4().copyPosition(local)));
      g = gT.multiply(RS);
    }
    const res = { world: g, local };
    globals.set(id, res);
    return res;
  }

  const group = new THREE.Group(); group.name = 'fbx6';
  for (const [id, { o, P }] of models) {
    if (!kid(o, 'Vertices')) continue;
    const mats = [];
    for (const c of childrenOf(id)) if ((byId.get(c) || {}).name === 'Material') mats.push(await material(c));
    if (!mats.length) mats.push(new THREE.MeshPhongMaterial({ color: 0xd8dce3, side: THREE.DoubleSide }));
    // текстура, привязанная прямо к объекту (FBX 6.0) — тем материалам, у которых своей нет
    const own = childrenOf(id).filter((c) => (byId.get(c) || {}).name === 'Texture');
    if (own.length) { const t = await texture(byId.get(own[0])); if (t) mats.forEach((m) => { if (!m.map) { m.map = t; m.color.setRGB(1, 1, 1); } }); }
    const geo = geometryOf(o, mats.length);
    if (!geo) continue;
    // «геометрический» сдвиг 3ds Max (опорная точка не в центре) — только для самой сетки, дети его не наследуют
    const geom = at(P.GeometricTranslation).multiply(rotM(P.GeometricRotation, 'ZYX')).multiply(new THREE.Matrix4().scale(vec3(P.GeometricScaling, one)));
    geo.applyMatrix4(globalOf(id).world.clone().multiply(geom));
    const mesh = new THREE.Mesh(geo, mats.length > 1 ? mats : mats[0]);
    mesh.name = shortName(id);
    group.add(mesh);
  }
  return group;
}
