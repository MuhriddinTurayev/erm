/* Oftob — 3D-модель любого формата → GLB прямо в браузере (three.js, без установки программ).
   Читает: GLB/GLTF (+bin, текстуры), FBX, OBJ (+MTL), DAE (SketchUp, ArchiCAD), 3DS, STL, PLY, 3MF, WRL/VRML, KMZ, USDZ,
   IFC (Revit, ArchiCAD, Tekla — через web-ifc), 3DM (Rhino), а также ZIP-архив с моделью и текстурами.
   Приводит к виду для сайта: метры, «верх» по оси Y, модель стоит на земле по центру, детали объединены по материалам,
   текстуры уменьшены (2048 px) — модель быстро открывается даже на телефоне.
   Нужен import map: "three" и "three/addons/" (см. company/objects/show.blade.php). */
import * as THREE from 'three';

const ADDONS = 'three/addons/';
const IFC_LIB = 'https://cdn.jsdelivr.net/npm/web-ifc@0.0.66/';
const RHINO_LIB = 'https://cdn.jsdelivr.net/npm/rhino3dm@8.4.0/';

/** Форматы, которые читаем, и где у них «верх» по умолчанию */
export const FORMATS = {
  glb: 'y', gltf: 'y', fbx: 'y', obj: 'y', dae: 'y', kmz: 'y', wrl: 'y', vrml: 'y', usdz: 'y', ifc: 'y',
  '3ds': 'z', stl: 'z', ply: 'z', '3mf': 'z', '3dm': 'z',
};
/** Родные форматы программ, которые браузер прочитать не может: подсказываем, как экспортировать */
export const NATIVE = {
  skp: 'SketchUp', rvt: 'Revit', rfa: 'Revit', max: '3ds Max', blend: 'Blender', pln: 'ArchiCAD', pla: 'ArchiCAD', tpl: 'ArchiCAD',
  c4d: 'Cinema 4D', ma: 'Maya', mb: 'Maya', dwg: 'AutoCAD', dxf: 'AutoCAD', ls: 'Lumion', tm: 'Twinmotion', lxo: 'Modo', sldprt: 'SolidWorks', step: 'CAD', stp: 'CAD', igs: 'CAD', iges: 'CAD',
};
export const ACCEPT = '.glb,.gltf,.bin,.fbx,.obj,.mtl,.dae,.kmz,.wrl,.vrml,.usdz,.ifc,.3ds,.stl,.ply,.3mf,.3dm,.zip,.jpg,.jpeg,.png,.webp,.tga,.bmp,.gif,.tif,.tiff,.dds,.ktx2,.hdr,.exr';

const ext = n => (String(n).toLowerCase().match(/\.([a-z0-9]+)$/) || [])[1] || '';
const base = n => String(n).split(/[\\/]/).pop();

/** Главный файл модели среди выбранных (остальные — текстуры, .bin, .mtl) */
export function mainFile(files) {
  const order = ['ifc', 'glb', 'gltf', 'fbx', 'dae', 'obj', 'kmz', '3ds', 'usdz', '3dm', '3mf', 'wrl', 'vrml', 'ply', 'stl'];
  for (const e of order) { const f = files.find(x => ext(x.name) === e); if (f) return f; }
  return null;
}

/** Нужно ли переводить: один GLB загружаем как есть, всё остальное — через перевод */
export function needsConvert(files) {
  return !(files.length === 1 && ext(files[0].name) === 'glb');
}

/** Понятная ошибка для родного формата программы */
export function nativeHint(files, T) {
  for (const f of files) { const e = ext(f.name); if (NATIVE[e]) return (T['x_' + e] || T.x_any).replace(':p', NATIVE[e]).replace(':e', '.' + e); }
  return null;
}

/* ---------- файлы: выбранные + распакованный ZIP ---------- */
async function collect(files, step) {
  const out = [];
  for (const f of files) {
    if (ext(f.name) !== 'zip') { out.push(f); continue; }
    step('zip');
    const { unzipSync } = await import(ADDONS + 'libs/fflate.module.js');
    const z = unzipSync(new Uint8Array(await f.arrayBuffer()));
    for (const [path, data] of Object.entries(z)) {
      if (path.endsWith('/') || /(^|\/)(__MACOSX|\.)/.test(path)) continue;
      out.push(new File([data], path));
    }
  }
  return out;
}

/* ---------- чтение модели ---------- */
// прозрачная картинка 1×1 вместо текстуры, которой нет среди выбранных файлов (потом такие текстуры убираем)
const EMPTY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
const IMG = /\.(jpe?g|png|webp|tga|bmp|gif|tiff?|dds|ktx2?|hdr|exr|psd)$/i;

async function load(list, main, step, T, missing, flags = {}) {
  const byName = new Map(), urls = [];
  for (const f of list) byName.set(base(f.name).toLowerCase(), f);
  const manager = new THREE.LoadingManager();
  // текстуры и .bin ищем среди выбранных файлов по имени (пути внутри модели часто абсолютные, с диска автора)
  manager.setURLModifier(url => {
    if (/^(blob|data):/.test(url)) return url;
    const name = decodeURIComponent(base(url.split('?')[0]));
    const f = byName.get(name.toLowerCase());
    if (!f) {
      // текстуры нет (её не выбрали вместе с моделью) — не ходим за ней на сервер, модель загрузится без неё
      if (IMG.test(name)) { missing.add(name); return EMPTY_PNG; }
      return url;
    }
    const u = URL.createObjectURL(f); urls.push(u); return u;
  });
  // ждать текстуры нужно, только если модель их действительно запросила (STL, PLY, IFC и т. п. ничего не грузят)
  let started = false;
  manager.onStart = () => { started = true; };
  const done = new Promise(r => { manager.onLoad = r; });
  const e = ext(main.name), path = 'oftob-model/';
  const buf = () => main.arrayBuffer(), txt = () => main.text();
  step('read');
  let root;
  switch (e) {
    case 'glb': case 'gltf': {
      const { GLTFLoader } = await import(ADDONS + 'loaders/GLTFLoader.js');
      const { DRACOLoader } = await import(ADDONS + 'loaders/DRACOLoader.js');
      const { MeshoptDecoder } = await import(ADDONS + 'libs/meshopt_decoder.module.js');
      const l = new GLTFLoader(manager), d = new DRACOLoader(manager);
      d.setDecoderPath(import.meta.resolve(ADDONS + 'libs/draco/gltf/'));
      l.setDRACOLoader(d); l.setMeshoptDecoder(MeshoptDecoder);
      const data = e === 'glb' ? await buf() : await txt();
      root = (await new Promise((ok, bad) => l.parse(data, path, ok, bad))).scene;
      d.dispose(); break;
    }
    case 'fbx': { const { FBXLoader } = await import(ADDONS + 'loaders/FBXLoader.js'); root = new FBXLoader(manager).parse(await buf(), path); break; }
    case 'obj': {
      const { OBJLoader } = await import(ADDONS + 'loaders/OBJLoader.js');
      const l = new OBJLoader(manager), text = await txt();
      const lib = (text.match(/^mtllib\s+(.+)$/m) || [])[1];
      const mtl = (lib && byName.get(base(lib.trim()).toLowerCase())) || list.find(f => ext(f.name) === 'mtl');
      if (mtl) { const { MTLLoader } = await import(ADDONS + 'loaders/MTLLoader.js'); const m = new MTLLoader(manager).parse(await mtl.text(), path); m.preload(); l.setMaterials(m); }
      // цвета и текстуры OBJ лежат в .mtl: без него модель была бы белой — запомним, раскрасим по названиям материалов и предупредим
      else if (lib || /^usemtl\s/m.test(text)) flags.noMtl = lib ? base(lib.trim()) : true;
      root = l.parse(text); break;
    }
    case 'dae': { const { ColladaLoader } = await import(ADDONS + 'loaders/ColladaLoader.js'); root = new ColladaLoader(manager).parse(await txt(), path).scene; break; }
    case 'kmz': { const { KMZLoader } = await import(ADDONS + 'loaders/KMZLoader.js'); root = new KMZLoader(manager).parse(await buf()).scene; break; }
    case '3ds': { const { TDSLoader } = await import(ADDONS + 'loaders/TDSLoader.js'); root = new TDSLoader(manager).parse(await buf(), path); break; }
    case 'wrl': case 'vrml': { const { VRMLLoader } = await import(ADDONS + 'loaders/VRMLLoader.js'); root = new VRMLLoader(manager).parse(await txt(), path); break; }
    case 'usdz': { const { USDZLoader } = await import(ADDONS + 'loaders/USDZLoader.js'); root = new USDZLoader(manager).parse(await buf()); break; }
    case '3mf': { const { ThreeMFLoader } = await import(ADDONS + 'loaders/3MFLoader.js'); root = new ThreeMFLoader(manager).parse(await buf()); break; }
    case 'stl': case 'ply': {
      const mod = e === 'stl' ? await import(ADDONS + 'loaders/STLLoader.js') : await import(ADDONS + 'loaders/PLYLoader.js');
      const geo = new (e === 'stl' ? mod.STLLoader : mod.PLYLoader)(manager).parse(await buf());
      if (!geo.attributes.normal) geo.computeVertexNormals();
      root = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ color: geo.attributes.color ? 0xffffff : 0xd8dce3, vertexColors: !!geo.attributes.color, roughness: .8 }));
      break;
    }
    case '3dm': {
      step('lib');
      const { Rhino3dmLoader } = await import(ADDONS + 'loaders/3DMLoader.js');
      const l = new Rhino3dmLoader(manager); l.setLibraryPath(RHINO_LIB);
      const data = await buf();
      root = await new Promise((ok, bad) => l.parse(data, ok, bad));
      l.dispose(); break;
    }
    case 'ifc': root = await ifc(await buf(), step, T); break;
    default: throw new Error(T.unknown);
  }
  // ждём текстуры (если модель их запросила); не дольше 60 секунд
  await new Promise(r => setTimeout(r, 50));
  if (started) await Promise.race([done, new Promise(r => setTimeout(r, 60000))]);
  await new Promise(r => setTimeout(r, 50));
  urls.forEach(u => setTimeout(() => URL.revokeObjectURL(u), 120000));
  return root;
}
/* ---------- IFC (Revit, ArchiCAD, Tekla): web-ifc → сетки по цветам ---------- */
async function ifc(buffer, step, T) {
  step('lib');
  const W = await import(IFC_LIB + 'web-ifc-api.js');
  const api = new W.IfcAPI();
  api.SetWasmPath(IFC_LIB, true);
  await api.Init();
  step('ifc');
  const id = api.OpenModel(new Uint8Array(buffer));
  if (id < 0) throw new Error(T.ifcBad);
  const group = new THREE.Group(), mats = new Map();
  api.StreamAllMeshes(id, mesh => {
    const pgs = mesh.geometries;
    for (let i = 0; i < pgs.size(); i++) {
      const pg = pgs.get(i), g = api.GetGeometry(id, pg.geometryExpressID);
      const v = api.GetVertexArray(g.GetVertexData(), g.GetVertexDataSize()), ix = api.GetIndexArray(g.GetIndexData(), g.GetIndexDataSize());
      if (v.length && ix.length) {
        const n = v.length / 6, pos = new Float32Array(n * 3), nor = new Float32Array(n * 3);
        for (let k = 0; k < n; k++) { pos[k * 3] = v[k * 6]; pos[k * 3 + 1] = v[k * 6 + 1]; pos[k * 3 + 2] = v[k * 6 + 2]; nor[k * 3] = v[k * 6 + 3]; nor[k * 3 + 1] = v[k * 6 + 4]; nor[k * 3 + 2] = v[k * 6 + 5]; }
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3)); geo.setAttribute('normal', new THREE.BufferAttribute(nor, 3)); geo.setIndex(new THREE.BufferAttribute(new Uint32Array(ix), 1));
        const c = pg.color, key = [c.x, c.y, c.z, c.w].map(x => x.toFixed(3)).join();
        if (!mats.has(key)) mats.set(key, new THREE.MeshStandardMaterial({ color: new THREE.Color().setRGB(c.x, c.y, c.z, THREE.SRGBColorSpace), opacity: c.w, transparent: c.w < 0.99, roughness: .85, side: THREE.DoubleSide }));
        const m = new THREE.Mesh(geo, mats.get(key));
        m.matrix.fromArray(pg.flatTransformation); m.matrixAutoUpdate = false;
        group.add(m);
      }
      g.delete();
    }
  });
  api.CloseModel(id);
  if (!group.children.length) throw new Error(T.ifcEmpty);
  return group;
}

/* ---------- приведение к виду для сайта ---------- */
function toStandard(m) {
  if (!m || m.isMeshStandardMaterial || m.isMeshBasicMaterial || m.isShadowMaterial) return m;
  const shin = m.shininess !== undefined ? m.shininess : 20;
  const s = new THREE.MeshStandardMaterial({
    name: m.name, color: m.color ? m.color.clone() : new THREE.Color(0xffffff), map: m.map || null, normalMap: m.normalMap || null, bumpMap: m.bumpMap || null,
    aoMap: m.aoMap || null, alphaMap: m.alphaMap || null, emissive: m.emissive ? m.emissive.clone() : new THREE.Color(0), emissiveMap: m.emissiveMap || null,
    transparent: !!m.transparent, opacity: m.opacity ?? 1, alphaTest: m.alphaTest || 0, side: m.side ?? THREE.FrontSide, vertexColors: !!m.vertexColors,
    roughness: Math.min(1, Math.max(0.12, 1 - shin / 120)), metalness: 0,
  });
  if (m.isMeshLambertMaterial) s.roughness = 0.95;
  return s;
}

/** Объединить детали по материалам: тысячи мелких объектов → десятки сеток (модель открывается в разы быстрее) */
async function merge(root) {
  const { mergeGeometries } = await import(ADDONS + 'utils/BufferGeometryUtils.js');
  root.updateMatrixWorld(true);
  const groups = new Map(), keepers = [];
  root.traverse(o => {
    if (!o.isMesh) return;
    if (o.isSkinnedMesh || o.isInstancedMesh || Array.isArray(o.material) || o.morphTargetInfluences) { keepers.push(o); return; }
    const g = o.geometry; if (!g || !g.attributes.position) return;
    const attrs = Object.keys(g.attributes).sort().filter(k => ['position', 'normal', 'uv', 'color'].includes(k));
    const key = o.material.uuid + '|' + attrs.join(',');
    let geo = g.clone();
    for (const k of Object.keys(geo.attributes)) if (!attrs.includes(k)) geo.deleteAttribute(k);
    if (geo.index) geo = geo.toNonIndexed();
    geo.applyMatrix4(o.matrixWorld);
    if (o.matrixWorld.determinant() < 0) flip(geo);   // зеркальные объекты — иначе вывернутся гранями внутрь
    if (!groups.has(key)) groups.set(key, { m: o.material, list: [] });
    groups.get(key).list.push(geo);
  });
  const out = new THREE.Group(); out.name = root.name || 'model';
  for (const { m, list } of groups.values()) {
    const geo = list.length === 1 ? list[0] : mergeGeometries(list, false);
    if (!geo) continue;
    if (!geo.attributes.normal) geo.computeVertexNormals();
    const mesh = new THREE.Mesh(geo, m); mesh.name = m.name || 'part'; out.add(mesh);
  }
  for (const k of keepers) { const c = k.clone(); c.matrix.copy(k.matrixWorld); c.matrix.decompose(c.position, c.quaternion, c.scale); out.add(c); }
  return out;
}
function flip(geo) {
  const p = geo.attributes.position;
  for (let i = 0; i < p.count; i += 3) for (const a of Object.values(geo.attributes)) {
    for (let c = 0; c < a.itemSize; c++) { const t = a.getComponent(i + 1, c); a.setComponent(i + 1, c, a.getComponent(i + 2, c)); a.setComponent(i + 2, c, t); }
  }
}

const UNIT = { m: 1, cm: 0.01, mm: 0.001, in: 0.0254, ft: 0.3048 };

/** Единицы «на глаз»: здание 5–600 м, квартира 3–60 м; огромные числа — значит миллиметры или сантиметры */
function guessUnit(maxDim, kind) {
  if (maxDim > 3000) return 'mm';
  if (maxDim > (kind === 'interior' ? 80 : 900)) return 'cm';
  return 'm';
}

function stats(root) {
  let tris = 0, meshes = 0; const mats = new Set(), tex = new Set();
  root.traverse(o => {
    if (!o.isMesh) return; meshes++;
    const g = o.geometry; tris += (g.index ? g.index.count : g.attributes.position.count) / 3 * (o.isInstancedMesh ? o.count : 1);
    (Array.isArray(o.material) ? o.material : [o.material]).forEach(m => { if (!m) return; mats.add(m); for (const k of ['map', 'normalMap', 'roughnessMap', 'metalnessMap', 'aoMap', 'emissiveMap', 'alphaMap']) if (m[k]) tex.add(m[k]); });
  });
  return { tris: Math.round(tris), meshes, materials: mats.size, textures: tex.size };
}

/**
 * Перевести выбранные файлы в GLB.
 * opt: {kind, units: auto|m|cm|mm|in|ft, up: auto|y|z, tex: 1024|2048|4096, merge: bool, T}
 * Возвращает {file: File(.glb), scene, info: {format, unit, size, tris, meshes, materials, textures, bytes}}
 */
/**
 * Модель без файла материалов (.mtl): все материалы белые. Красим по названию материала (glass, grass, road, roof…),
 * остальным — спокойные оттенки отделки по хэшу названия, чтобы дома не сливались в одно белое пятно.
 */
const NAMED = [
  [/glass|window|glazing|okno|окн|стекл|oyna/i, 0x8fb4d8, { transparent: true, opacity: 0.55, roughness: 0.1 }],
  [/water|sea|river|suv|вода/i, 0x4f8fc0], [/grass|lawn|green|tree|leaf|foliage|bush|трав|дерев|зелен|o.t/i, 0x6f9a52],
  [/road|asphalt|street|tarmac|асфальт|дорог|yo.l/i, 0x4a4d52], [/roof|tile|черепиц|крыш|tom/i, 0x9a5b42], [/brick|кирпич|g.isht/i, 0xa45a45],
  [/wood|timber|plank|доск|брус|yog.och/i, 0x8a6a4a], [/metal|steel|iron|chrome|alumin|сталь|металл/i, 0x9aa1a9, { metalness: 0.6, roughness: 0.4 }],
  [/concrete|cement|stone|бетон|камен|tosh/i, 0xb9b6ae], [/sand|песок|qum/i, 0xd8c79a], [/line|marking|stripe|разметк/i, 0xf2f2ee],
  [/red/i, 0xc0504d], [/blue/i, 0x4f7fbf], [/yellow/i, 0xd9b44a], [/white/i, 0xf1f1ee], [/black|dark/i, 0x2f3236], [/gr[ae]y/i, 0x9a9da3],
  [/orange/i, 0xd98a3a], [/brown/i, 0x8a6a4a], [/pink/i, 0xd98aa8], [/purple|violet/i, 0x8a6ac0],
];
const SOFT = [0xd9d4c7, 0xc9d3dc, 0xe2d6c3, 0xcfd8c6, 0xd8c9c4, 0xc8ccd8, 0xe0dccf, 0xbfc7cc, 0xd6cfc1, 0xc4cfc8];
function autoColor(root) {
  const hash = s => { let h = 0; for (const ch of String(s)) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return h; };
  root.traverse(o => {
    if (!o.isMesh) return;
    (Array.isArray(o.material) ? o.material : [o.material]).forEach(m => {
      if (!m || m.map || !m.color || m.color.r + m.color.g + m.color.b < 2.9) return;   // уже своя краска — не трогаем
      const name = m.name || o.name || '', hit = NAMED.find(([re]) => re.test(name));
      m.color.setHex(hit ? hit[1] : SOFT[hash(name) % SOFT.length]);
      if (hit && hit[2]) Object.assign(m, hit[2]);
      m.needsUpdate = true;
    });
  });
}

export async function convert(files, opt = {}, onStep = () => {}) {
  const T = opt.T || {};
  const step = k => onStep(T['p_' + k] || k);
  const hint = nativeHint(files, T); if (hint && !mainFile(files)) throw new Error(hint);
  const list = await collect(files, step);
  const main = mainFile(list);
  if (!main) throw new Error(nativeHint(list, T) || T.noModel);
  const e = ext(main.name), missing = new Set(), flags = {};
  let root = await load(list, main, step, T, missing, flags);
  if (!root) throw new Error(T.unknown);
  if (flags.noMtl) autoColor(root);
  const dropped = dropBadTextures(root);
  root.traverse(o => {
    if (o.isLight || o.isCamera) { o.visible = false; return; }
    if (o.isMesh || o.isLine || o.isPoints) {
      if (Array.isArray(o.material)) o.material = o.material.map(toStandard); else o.material = toStandard(o.material);
      (Array.isArray(o.material) ? o.material : [o.material]).forEach(m => {
        if (!m) return;
        if (m.map) m.map.colorSpace = THREE.SRGBColorSpace;
        // фото-текстуры без прозрачности — в JPEG: файл в разы меньше
        for (const k of ['map', 'normalMap', 'roughnessMap', 'aoMap', 'emissiveMap', 'bumpMap']) if (m[k] && !m.transparent && !m.alphaTest) m[k].userData.mimeType = 'image/jpeg';
      });
    }
  });
  // «верх» модели: Z → Y (3DS, STL, Rhino, 3D-печать)
  const up = opt.up && opt.up !== 'auto' ? opt.up : FORMATS[e] || 'y';
  const wrap = new THREE.Group(); wrap.add(root);
  if (up === 'z') root.rotation.x = -Math.PI / 2;
  wrap.updateMatrixWorld(true);
  step('opt');
  let scene = opt.merge === false ? wrap : await merge(wrap);
  // единицы и положение: метры, по центру, на земле
  scene.updateMatrixWorld(true);
  let box = new THREE.Box3().setFromObject(scene);
  if (box.isEmpty()) throw new Error(T.emptyModel);
  const size0 = box.getSize(new THREE.Vector3()), unit = opt.units && opt.units !== 'auto' ? opt.units : guessUnit(Math.max(size0.x, size0.y, size0.z), opt.kind);
  const k = UNIT[unit] || 1, c = box.getCenter(new THREE.Vector3());
  const holder = new THREE.Group(); holder.name = 'oftob';
  scene.position.set(-c.x, -box.min.y, -c.z);
  holder.add(scene); holder.scale.setScalar(k);
  holder.updateMatrixWorld(true);
  box = new THREE.Box3().setFromObject(holder);
  const size = box.getSize(new THREE.Vector3());
  step('glb');
  const { GLTFExporter } = await import(ADDONS + 'exporters/GLTFExporter.js');
  const exportGlb = () => new GLTFExporter().parseAsync(holder, { binary: true, onlyVisible: true, maxTextureSize: +opt.tex || 2048 });
  let glb, stripped = 0;
  try {
    glb = await exportGlb();
  } catch (err) {
    // необычные текстуры (сжатые, видео, повреждённые) экспортёр не берёт — сохраняем модель без них, а не останавливаемся
    console.warn(err);
    stripped = stripTextures(holder);
    if (!stripped) throw err;
    glb = await exportGlb();
  }
  const name = base(main.name).replace(/\.[^.]+$/, '') + '.glb';
  const file = new File([glb], name, { type: 'model/gltf-binary' });
  return { file, scene: holder, info: Object.assign({ format: e.toUpperCase(), unit, size: { x: size.x, y: size.y, z: size.z }, bytes: file.size, source: main.name,
    missing: [...missing], dropped, stripped, noMtl: flags.noMtl || null }, stats(holder)) };
}

/** Текстуры без картинки (файл не выбрали вместе с моделью, формат не прочитался) — убираем: остаётся цвет материала */
function dropBadTextures(root) {
  let n = 0;
  root.traverse(o => (Array.isArray(o.material) ? o.material : [o.material]).forEach(m => {
    if (!m) return;
    for (const k of Object.keys(m)) {
      const t = m[k];
      if (!t || !t.isTexture || t.isCompressedTexture || t.isVideoTexture) continue;
      const im = t.image, w = im ? (im.naturalWidth || im.width || 0) : 0, h = im ? (im.naturalHeight || im.height || 0) : 0;
      if (im && (im.data ? im.data.length > 4 : w > 1 && h > 1)) continue;
      m[k] = null; m.needsUpdate = true; n++;
    }
  }));
  return n;
}

/** Убрать все текстуры (если экспорт с ними не удался) */
function stripTextures(root) {
  let n = 0;
  root.traverse(o => (Array.isArray(o.material) ? o.material : [o.material]).forEach(m => {
    if (!m) return;
    for (const k of Object.keys(m)) if (m[k] && m[k].isTexture) { m[k] = null; m.needsUpdate = true; n++; }
  }));
  return n;
}

/* ---------- просмотр результата перед загрузкой ---------- */
export async function preview(host, object) {
  const { OrbitControls } = await import(ADDONS + 'controls/OrbitControls.js');
  const { RoomEnvironment } = await import(ADDONS + 'environments/RoomEnvironment.js');
  host.innerHTML = '';
  const w = host.clientWidth || 600, h = host.clientHeight || 360;
  const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
  renderer.setPixelRatio(Math.min(2, devicePixelRatio)); renderer.setSize(w, h); renderer.toneMapping = THREE.ACESFilmicToneMapping;
  host.append(renderer.domElement);
  const scene = new THREE.Scene(); scene.background = new THREE.Color(getComputedStyle(document.documentElement).getPropertyValue('--sunken').trim() || '#eef2f7');
  scene.environment = new THREE.PMREMGenerator(renderer).fromScene(new RoomEnvironment(), 0.04).texture;
  scene.add(object);
  const box = new THREE.Box3().setFromObject(object), size = box.getSize(new THREE.Vector3()), r = Math.max(size.x, size.y, size.z) || 1;
  const grid = new THREE.GridHelper(r * 2, 20, 0x94a3b8, 0xcbd5e1); grid.material.opacity = .5; grid.material.transparent = true; scene.add(grid);
  const cam = new THREE.PerspectiveCamera(45, w / h, r / 1000, r * 100);
  const ctr = box.getCenter(new THREE.Vector3());
  cam.position.set(ctr.x + r * 1.1, ctr.y + r * 0.8, ctr.z + r * 1.3); cam.lookAt(ctr);
  const ctl = new OrbitControls(cam, renderer.domElement); ctl.target.copy(ctr); ctl.update();
  let live = true;
  const tick = () => { if (!live) return; ctl.update(); renderer.render(scene, cam); requestAnimationFrame(tick); }; tick();
  const ro = new ResizeObserver(() => { const W = host.clientWidth, H = host.clientHeight; if (!W || !H) return; renderer.setSize(W, H); cam.aspect = W / H; cam.updateProjectionMatrix(); }); ro.observe(host);
  return { dispose() { live = false; ro.disconnect(); renderer.dispose(); scene.remove(object); host.innerHTML = ''; } };
}
