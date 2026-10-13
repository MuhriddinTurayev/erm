'use strict';
/* ===== Фото-тур 360°: реалистичные фотопанорамы квартиры — осмотреться кругом ===== */
// Полные фотопанорамы 360° (равнопромежуточные, 2:1). Добавляются после генерации: { style, room, url }
const INTERIOR_PANOS = [];
const PV = { r: null, raf: 0, yaw: 0, pitch: 0, fov: 75, auto: true, gyro: false, items: [], i: 0 };
const pvPanoFor = ph => INTERIOR_PANOS.find(x => x.style === ph.style && x.room === ph.room);
/* список для тура: фото выбранного стиля (или все), у каждого — полная панорама или «обзор по фото» */
function pvItems(style) {
  const own = INTERIOR_PHOTOS.filter(p => !style || p.style === style), list = own.length ? own : INTERIOR_PHOTOS;
  return list.map(p => { const pn = pvPanoFor(p); return { title: `${STYLES[p.style].name} · ${p.room}`, url: pn ? pn.url : phUrl(p), full: !!pn, style: p.style }; });
}
function pvOpen(items, i = 0) {
  PV.items = items; PV.i = i;
  openModal(`<div class="pv" id="pvBox">
    <div class="pv-bar"><b id="pvTitle"></b><span class="pv-badge" id="pvBadge"></span><span class="right"></span>
      <button data-act="pvAuto" id="pvAutoB">🔄 Авто</button><button data-act="pvGyro" id="pvGyroB">📱 Гироскоп</button><button data-act="pvFull">⛶</button><button data-act="closeModal">✕</button></div>
    <div class="pv-rooms" id="pvRooms"></div>
    <div class="pv-hint" id="pvHint">Тяните мышью или пальцем — осмотритесь кругом · колесо: приблизить</div>
    <div class="pv-load" id="pvLoad">Загрузка фото…</div></div>`);
  $('.modal-box').classList.add('wide', 'pv-modal');
  pvInit($('#pvBox')); pvShow(i);
}
function pvInit(el) {
  pvDispose(true);
  if (!window.THREE) { el.innerHTML = '<p class="note">Нужен интернет при первом открытии.</p>'; return; }
  const r = new THREE.WebGLRenderer({ antialias: true }); r.setPixelRatio(Math.min(2, devicePixelRatio || 1)); r.outputEncoding = THREE.sRGBEncoding;
  el.prepend(r.domElement); PV.r = r; PV.el = el; PV.scene = new THREE.Scene(); PV.cam = new THREE.PerspectiveCamera(PV.fov, 1, 0.1, 2000);
  const resize = () => { const w = el.clientWidth, h = el.clientHeight; if (!w || !h) return; r.setSize(w, h, false); PV.cam.aspect = w / h; PV.cam.updateProjectionMatrix(); };
  PV.ro = new ResizeObserver(resize); PV.ro.observe(el); resize();
  let drag = null; const cv = r.domElement;
  cv.addEventListener('pointerdown', e => { drag = { x: e.clientX, y: e.clientY, yaw: PV.yaw, pitch: PV.pitch }; cv.setPointerCapture(e.pointerId); pvPause(); });
  cv.addEventListener('pointermove', e => { if (!drag) return; const k = PV.fov / el.clientHeight * Math.PI / 180; PV.yaw = drag.yaw + (e.clientX - drag.x) * k; PV.pitch = drag.pitch + (e.clientY - drag.y) * k; pvClamp(); });
  cv.addEventListener('pointerup', () => drag = null); cv.addEventListener('pointercancel', () => drag = null);
  cv.addEventListener('wheel', e => { e.preventDefault(); PV.fov = Math.max(35, Math.min(95, PV.fov + e.deltaY * 0.04)); PV.cam.fov = PV.fov; PV.cam.updateProjectionMatrix(); pvClamp(); }, { passive: false });
  let last = performance.now();
  const loop = t => { PV.raf = requestAnimationFrame(loop); const dt = Math.min(0.05, (t - last) / 1000); last = t;
    if (PV.auto && !drag && !PV.gyro) { PV.yaw += dt * (PV.cur && PV.cur.full ? 0.12 : 0.18) * (PV.dir || 1); pvClamp(true); }
    const cp = Math.cos(PV.pitch); PV.cam.lookAt(-Math.sin(PV.yaw) * cp, Math.sin(PV.pitch), -Math.cos(PV.yaw) * cp); r.render(PV.scene, PV.cam); };
  PV.raf = requestAnimationFrame(loop); pvBtns();
}
/* обзор по обычному фото: снимок на дуге ~100°, можно поворачиваться в пределах кадра */
const PV_ARC = 100 * Math.PI / 180;
function pvClamp(auto) {
  PV.pitch = Math.max(-1.35, Math.min(1.35, PV.pitch));
  if (PV.cur && !PV.cur.full && PV.mesh) {
    const hf = PV.cam.fov * Math.PI / 180, hw = Math.atan(Math.tan(hf / 2) * PV.cam.aspect), lim = Math.max(0, PV_ARC / 2 - hw), vlim = Math.max(0, Math.atan(PV.vh / 2 / PV.rad) - hf / 2);
    if (auto && Math.abs(PV.yaw) >= lim) PV.dir = -(Math.sign(PV.yaw) || 1);
    PV.yaw = Math.max(-lim, Math.min(lim, PV.yaw)); PV.pitch = Math.max(-vlim, Math.min(vlim, PV.pitch));
  }
}
function pvShow(i) {
  if (!PV.r) return; const it = PV.items[i]; PV.i = i; PV.cur = it; PV.yaw = 0; PV.pitch = 0; PV.dir = 1;
  $('#pvTitle').textContent = T(it.title); $('#pvBadge').textContent = T(it.full ? '360° фотопанорама' : 'обзор по фото');
  $('#pvBadge').className = 'pv-badge' + (it.full ? ' full' : '');
  $('#pvRooms').innerHTML = PV.items.map((x, k) => `<button class="${k === i ? 'on' : ''}" data-act="pvGo" data-i="${k}">${T(x.title)}${x.full ? ' · 360°' : ''}</button>`).join('') + (it.full ? '' : `<button class="pv-3d" data-act="pv3d" data-style="${it.style}">🌐 Полный круг 360° в 3D</button>`);
  $('#pvLoad').style.display = '';
  new THREE.TextureLoader().setCrossOrigin('anonymous').load(it.url, tex => {
    if (!PV.r || PV.cur !== it) return tex.dispose(); tex.encoding = THREE.sRGBEncoding; tex.anisotropy = PV.r.capabilities.getMaxAnisotropy();
    if (PV.mesh) { PV.scene.remove(PV.mesh); PV.mesh.geometry.dispose(); PV.mesh.material.map.dispose(); PV.mesh.material.dispose(); }
    let geo;
    if (it.full) { geo = new THREE.SphereGeometry(500, 96, 64); geo.scale(-1, 1, 1); }
    else { const asp = tex.image.width / tex.image.height, rad = 100, arc = rad * PV_ARC; PV.rad = rad; PV.vh = arc / asp;
      geo = new THREE.CylinderGeometry(rad, rad, PV.vh, 96, 1, true, Math.PI - PV_ARC / 2, PV_ARC); geo.scale(-1, 1, 1); }
    PV.mesh = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ map: tex })); PV.scene.add(PV.mesh);
    PV.fov = it.full ? 75 : 62; PV.cam.fov = PV.fov; PV.cam.updateProjectionMatrix(); pvClamp();
    $('#pvLoad').style.display = 'none';
  }, undefined, () => { $('#pvLoad').textContent = T('Фото не загрузилось — нужен интернет'); });
}
function pvPause() { PV.auto = false; clearTimeout(PV.t); PV.t = setTimeout(() => { PV.auto = true; pvBtns(); }, 8000); pvBtns(); }
function pvBtns() { const a = $('#pvAutoB'), g = $('#pvGyroB'); if (a) a.classList.toggle('on', PV.auto); if (g) g.classList.toggle('on', PV.gyro); }
function pvDispose(keepModal) {
  if (PV.raf) cancelAnimationFrame(PV.raf); PV.raf = 0; if (PV.ro) PV.ro.disconnect(); PV.ro = null; pvGyroOff(); clearTimeout(PV.t);
  if (PV.r) { if (PV.mesh) { PV.mesh.geometry.dispose(); PV.mesh.material.map && PV.mesh.material.map.dispose(); PV.mesh.material.dispose(); } PV.r.dispose(); try { PV.r.forceContextLoss(); } catch (e) { } PV.r.domElement.remove(); }
  PV.r = null; PV.mesh = null; PV.cur = null; if (!keepModal) { const b = $('.modal-box'); if (b) b.classList.remove('pv-modal'); }
}
function pvGyroOff() { if (PV.gh) window.removeEventListener('deviceorientation', PV.gh); PV.gh = null; PV.gyro = false; }
ACT.pvGo = d => pvShow(+d.i);
ACT.pvAuto = () => { PV.auto = !PV.auto; clearTimeout(PV.t); pvBtns(); };
ACT.pvFull = () => { const v = $('#pvBox'); if (!document.fullscreenElement) (v.requestFullscreen || v.webkitRequestFullscreen || (() => { })).call(v); else document.exitFullscreen(); };
ACT.pvGyro = async () => {
  if (PV.gyro) { pvGyroOff(); return pvBtns(); }
  try { if (window.DeviceOrientationEvent && DeviceOrientationEvent.requestPermission && (await DeviceOrientationEvent.requestPermission()) !== 'granted') return toast('Нет доступа к гироскопу'); } catch (e) { }
  let base = null;
  PV.gh = e => { if (e.alpha == null) return; const a = e.alpha * Math.PI / 180; if (base == null) base = PV.yaw - a; PV.yaw = a + base; PV.pitch = ((e.beta || 90) - 90) * Math.PI / 180; pvClamp(); };
  window.addEventListener('deviceorientation', PV.gh); PV.gyro = true; PV.auto = false; pvBtns(); toast('Поворачивайте телефон — панорама повернётся вместе с ним');
};
ACT.pv3d = d => { closeModal(); if (curPage === 'apt' && pageArgs.id) { aptObj(P(), pageArgs.id).style = d.style; save(); go('apt', { id: pageArgs.id, tab: 'interior', lead: pageArgs.lead, pano: 1 }); } else { ACT.d3Style({ style: d.style }); toast('Нажмите «🌐 Тур 360°» над 3D-моделью'); } };
ACT.ph360 = d => { const it = pvItems(d.style || ''), k = Math.max(0, it.findIndex(x => x.title === d.title)); pvOpen(it, k); };
// закрытие окна — освобождаем видеокарту
{ const _close = closeModal; closeModal = function () { pvDispose(); return _close.apply(this, arguments); }; }
