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
async function load(list, main, step, T) {
  const byName = new Map(), urls = [];
  for (const f of list) byName.set(base(f.name).toLowerCase(), f);
  const manager = new THREE.LoadingManager();
  // текстуры и .bin ищем среди выбранных файлов по имени (пути внутри модели часто абсолютные, с диска автора)
  manager.setURLModifier(url => {
    if (/^(blob|data):/.test(url)) return url;
    const f = byName.get(decodeURIComponent(base(url.split('?')[0])).toLowerCase());
    if (!f) return url;
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
export async function convert(files, opt = {}, onStep = () => {}) {
  const T = opt.T || {};
  const step = k => onStep(T['p_' + k] || k);
  const hint = nativeHint(files, T); if (hint && !mainFile(files)) throw new Error(hint);
  const list = await collect(files, step);
  const main = mainFile(list);
  if (!main) throw new Error(nativeHint(list, T) || T.noModel);
  const e = ext(main.name);
  let root = await load(list, main, step, T);
  if (!root) throw new Error(T.unknown);
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
  const glb = await new GLTFExporter().parseAsync(holder, { binary: true, onlyVisible: true, maxTextureSize: +opt.tex || 2048 });
  const name = base(main.name).replace(/\.[^.]+$/, '') + '.glb';
  const file = new File([glb], name, { type: 'model/gltf-binary' });
  return { file, scene: holder, info: Object.assign({ format: e.toUpperCase(), unit, size: { x: size.x, y: size.y, z: size.z }, bytes: file.size, source: main.name }, stats(holder)) };
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
