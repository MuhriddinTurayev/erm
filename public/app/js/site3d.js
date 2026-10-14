'use strict';
/* ===== 3D всего жилого комплекса: фотореалистичный вид — здание, озеленение, детская площадка, парковка, маршрут до квартиры ===== */
const S3 = { raf: 0, mode: 'done', night: false, spin: false, dirty: 30, route: false, photo: true };
function siteProgress(p) {
  const f = re => { const s = p.stages.find(x => re.test(x.name)); return s ? s.progress : 0; };
  return { frame: f(/каркас/i), facade: f(/фасад/i), windows: f(/окн/i), land: f(/благоустр/i), roof: f(/кровл/i), total: progress(p) };
}
function siteLayout(p) {
  const b = p.b, L = b.length, Wd = b.width, A = Math.max(b.plot, (L + 40) * (Wd + 50));
  const SW = Math.max(L + 48, Math.sqrt(A * 1.5)), SD = Math.max(Wd + 66, A / SW);
  return { L, Wd, SW, SD, z0: -SD / 2 + 17 + Wd / 2, H: b.floors * b.floorH };
}
function siteStats(p) {
  const B = bld(p), Ly = siteLayout(p), fp = B.fp;
  const need = Math.ceil(B.apts * 0.5 + B.commercial / 50);
  const north = Math.floor((Ly.L + 24) / 2.6), east = Math.floor((Ly.SD - 30) / 2.6);
  const placed = Math.min(need, north + east);
  const play = 26 * 16, sport = 18 * 12;
  const hard = (Ly.L + 24) * 14 + east * 2.6 * 6 + play + sport + Ly.L * 4 + 400;
  const green = Math.max(0, Ly.SW * Ly.SD - fp - hard);
  return { need, placed, under: Math.max(0, need - placed), green, greenPct: green / (Ly.SW * Ly.SD) * 100, play, sport, area: Ly.SW * Ly.SD };
}

/* ---------- текстуры: цвет (sRGB), шероховатость/металл (линейные), свечение окон ночью ---------- */
const s3aniso = () => S3.renderer ? Math.min(16, S3.renderer.capabilities.getMaxAnisotropy()) : 4;
function s3cv(w, h, draw, srgb = true) { const t = cvTex(w, h, draw, srgb); t.anisotropy = s3aniso(); return t; }
function s3noise(g, w, h, n, cols, a0, a1, sz = 1.5) { for (let i = 0; i < n; i++) { g.fillStyle = `rgba(${cols[i % cols.length]},${a0 + Math.random() * (a1 - a0)})`; g.fillRect(Math.random() * w, Math.random() * h, sz, sz); } }
function s3plaster(g, x, y, w, h, base) { g.fillStyle = base; g.fillRect(x, y, w, h); for (let i = 0; i < w * h / 18; i++) { g.fillStyle = `rgba(${Math.random() < .5 ? '0,0,0' : '255,255,255'},${.025 + Math.random() * .04})`; g.fillRect(x + Math.random() * w, y + Math.random() * h, 1.2, 1.2); } }
function s3brick(g, x, y, w, h, base) {
  g.fillStyle = '#cfc6ba'; g.fillRect(x, y, w, h); const bw = 14, bh = 5;
  for (let r = 0; r * bh < h; r++) for (let c = -1; c * bw < w; c++) { const bx = x + c * bw + (r % 2) * bw / 2, by = y + r * bh; g.fillStyle = tint(base, (Math.random() - .5) * .09, (Math.random() - .5) * .08); g.fillRect(Math.max(x, bx + .6), by + .6, Math.min(bw - 1.2, x + w - bx - .6), bh - 1.2); }
}
/* Фасад жилых этажей. style: 0 — штукатурка + кирпичные простенки, 1 — светлая штукатурка + графитовые рамы, 2 — тёплый камень */
function s3Facade(cols, rows, style, night, seed, balcony) {
  const R = rng(seed), CW = 64, CH = 80, W = cols * CW, H = rows * CH;
  const pal = [{ wall: '#d9cdb9', acc: '#94553c', frame: '#ecebe7' }, { wall: '#dedbd3', acc: '#5f6870', frame: '#3b4046' }, { wall: '#cdbb9e', acc: '#a08a6a', frame: '#e4e0d8' }][style % 3];
  const lit = []; for (let i = 0; i < cols * rows; i++) lit.push(R() < 0.45);
  const curtain = []; for (let i = 0; i < cols * rows; i++) curtain.push(R());
  const win = (c, r) => { const x = c * CW, y = r * CH, tall = balcony && c % 2 === 1; return { x: x + 12, y: y + (tall ? 8 : 14), w: CW - 24, h: tall ? CH - 12 : CH - 34 }; };
  const map = s3cv(W, H, g => {
    s3plaster(g, 0, 0, W, H, pal.wall);
    for (let c = 0; c < cols; c++) if (style === 0 && c % 4 >= 2 || style === 2 && c % 2 === 0 || style === 1 && c % 4 === 3) { if (style === 0) s3brick(g, c * CW, 0, CW, H, pal.acc); else s3plaster(g, c * CW, 0, CW, H, style === 1 ? pal.acc : tint(pal.wall, -.06)); }
    if (style === 1) for (let c = 0; c < cols; c += 2) { g.fillStyle = 'rgba(70,80,90,.10)'; g.fillRect(c * CW + 4, 0, 2 * CW - 8, H); }
    for (let r = 0; r < rows; r++) { g.fillStyle = 'rgba(0,0,0,.10)'; g.fillRect(0, r * CH, W, 2); g.fillStyle = 'rgba(255,255,255,.35)'; g.fillRect(0, r * CH + 2, W, 1); }
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) {
      const o = win(c, r), k = r * cols + c, cu = curtain[k];
      g.fillStyle = 'rgba(0,0,0,.28)'; g.fillRect(o.x - 3, o.y - 3, o.w + 6, o.h + 6);              // откос
      g.fillStyle = pal.frame; g.fillRect(o.x - 2, o.y - 2, o.w + 4, o.h + 4);                      // рама
      const gr = g.createLinearGradient(o.x, o.y, o.x + o.w * .4, o.y + o.h);
      if (night) { gr.addColorStop(0, lit[k] ? '#ffd9a0' : '#141a24'); gr.addColorStop(1, lit[k] ? '#f2a95c' : '#0b0f16'); }
      else { gr.addColorStop(0, '#9db3c6'); gr.addColorStop(.45, '#5f768b'); gr.addColorStop(1, '#34465a'); }
      g.fillStyle = gr; g.fillRect(o.x, o.y, o.w, o.h);
      if (!night) { g.fillStyle = 'rgba(255,255,255,.16)'; g.beginPath(); g.moveTo(o.x + o.w * .15, o.y); g.lineTo(o.x + o.w * .45, o.y); g.lineTo(o.x + o.w * .2, o.y + o.h); g.lineTo(o.x, o.y + o.h * .8); g.fill(); }
      if (!night && cu < .35) { g.fillStyle = `rgba(${cu < .15 ? '235,226,210' : '205,212,220'},.55)`; g.fillRect(o.x + (cu < .2 ? 0 : o.w * .55), o.y, o.w * .45, o.h); }   // шторы
      g.fillStyle = pal.frame; g.fillRect(o.x + o.w / 2 - 1.5, o.y, 3, o.h); if (o.h > 50) g.fillRect(o.x, o.y + o.h * .7, o.w, 2);
      g.fillStyle = '#d7d3cc'; g.fillRect(o.x - 4, o.y + o.h + 2, o.w + 8, 3);                        // отлив
    }
  });
  const rm = s3cv(W, H, g => {   // G — шероховатость, B — металличность
    g.fillStyle = 'rgb(0,225,0)'; g.fillRect(0, 0, W, H);
    for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) { const o = win(c, r); g.fillStyle = 'rgb(0,110,150)'; g.fillRect(o.x - 2, o.y - 2, o.w + 4, o.h + 4); g.fillStyle = curtain[r * cols + c] < .35 ? 'rgb(0,60,70)' : 'rgb(0,28,115)'; g.fillRect(o.x, o.y, o.w, o.h); g.fillStyle = 'rgb(0,110,150)'; g.fillRect(o.x + o.w / 2 - 1.5, o.y, 3, o.h); }
  }, false);
  let em = null;
  if (night) em = s3cv(W, H, g => { g.fillStyle = '#000'; g.fillRect(0, 0, W, H); for (let r = 0; r < rows; r++) for (let c = 0; c < cols; c++) if (lit[r * cols + c]) { const o = win(c, r); g.fillStyle = '#ffcf8c'; g.fillRect(o.x, o.y, o.w, o.h); g.fillStyle = '#000'; g.fillRect(o.x + o.w / 2 - 1.5, o.y, 3, o.h); } });
  return { map, rm, em };
}
function s3FacadeMat(F, rx = 1, ry = 1) {
  [F.map, F.rm, F.em].forEach(t => t && t.repeat.set(rx, ry));
  return new THREE.MeshStandardMaterial({ map: F.map, roughnessMap: F.rm, metalnessMap: F.rm, roughness: 1, metalness: 1, envMapIntensity: S3.night ? .35 : 1, ...(F.em ? { emissive: 0xffffff, emissiveMap: F.em, emissiveIntensity: 1.6 } : {}) });
}
function s3ShopFacade(cols, night) {
  const CW = 128, H = 128, W = cols * CW;
  const map = s3cv(W, H, g => {
    g.fillStyle = '#2f343a'; g.fillRect(0, 0, W, H);
    for (let c = 0; c < cols; c++) { const x = c * CW;
      const gr = g.createLinearGradient(0, 30, 0, H); gr.addColorStop(0, night ? '#fff1d6' : '#4a5a6a'); gr.addColorStop(1, night ? '#f5c98a' : '#1f2933'); g.fillStyle = gr; g.fillRect(x + 6, 30, CW - 12, H - 34);
      if (!night) { g.fillStyle = 'rgba(255,255,255,.12)'; g.beginPath(); g.moveTo(x + 10, 30); g.lineTo(x + 40, 30); g.lineTo(x + 20, H - 4); g.lineTo(x + 6, H - 4); g.fill(); }
      g.fillStyle = '#1a1d21'; g.fillRect(x + CW / 2 - 2, 30, 4, H - 34);
      g.fillStyle = ['#c8642d', '#2e7d57', '#b08a3c', '#2f5fae'][c % 4]; g.fillRect(x + 14, 8, CW - 28, 16); g.fillStyle = '#fff'; g.font = 'bold 11px sans-serif'; g.textAlign = 'center'; g.fillText(['CAFE', 'MARKET', 'PHARMACY', 'BANK'][c % 4], x + CW / 2, 20);
    }
  });
  const rm = s3cv(W, H, g => { g.fillStyle = 'rgb(0,120,120)'; g.fillRect(0, 0, W, H); for (let c = 0; c < cols; c++) { g.fillStyle = 'rgb(0,10,235)'; g.fillRect(c * CW + 6, 30, CW - 12, H - 34); } }, false);
  const em = night ? s3cv(W, H, g => { g.fillStyle = '#000'; g.fillRect(0, 0, W, H); for (let c = 0; c < cols; c++) { g.fillStyle = '#ffe2b0'; g.fillRect(c * CW + 6, 30, CW - 12, H - 34); g.fillStyle = '#fff'; g.fillRect(c * CW + 14, 8, CW - 28, 16); } }) : null;
  return { map, rm, em };
}
function s3Plinth() { return s3cv(256, 128, (g, w, h) => { g.fillStyle = '#6d6a66'; g.fillRect(0, 0, w, h); for (let y = 0; y < h; y += 32) for (let x = (y / 32 % 2) * 32; x < w; x += 64) { g.fillStyle = tint('#76726d', (Math.random() - .5) * .08); g.fillRect(x + 1, y + 1, 62, 30); } s3noise(g, w, h, 3000, ['0,0,0', '255,255,255'], .03, .08); }); }
function s3Grass(dark) { return s3cv(512, 512, (g, w, h) => { g.fillStyle = dark ? '#2b3a22' : '#5f7240'; g.fillRect(0, 0, w, h); for (let i = 0; i < 26; i++) { const r = 30 + Math.random() * 90, x = Math.random() * w, y = Math.random() * h, gr = g.createRadialGradient(x, y, 0, x, y, r); gr.addColorStop(0, Math.random() < .5 ? 'rgba(150,140,90,.28)' : 'rgba(40,60,25,.28)'); gr.addColorStop(1, 'rgba(0,0,0,0)'); g.fillStyle = gr; g.fillRect(x - r, y - r, 2 * r, 2 * r); }
  for (let i = 0; i < 26000; i++) { const x = Math.random() * w, y = Math.random() * h; g.strokeStyle = `rgba(${[[56, 76, 34], [92, 112, 56], [128, 136, 80], [72, 92, 42], [150, 142, 98], [110, 98, 70]][i % 5].join(',')},${.25 + Math.random() * .4})`; g.lineWidth = 1; g.beginPath(); g.moveTo(x, y); g.lineTo(x + (Math.random() - .5) * 2, y - 2 - Math.random() * 3); g.stroke(); } }); }
function s3Asphalt() { return s3cv(512, 512, (g, w, h) => { g.fillStyle = '#3e4146'; g.fillRect(0, 0, w, h); s3noise(g, w, h, 30000, ['20,20,22', '110,110,112', '80,78,74'], .15, .45, 1.3); for (let i = 0; i < 6; i++) { g.fillStyle = 'rgba(0,0,0,.08)'; g.beginPath(); g.ellipse(Math.random() * w, Math.random() * h, 30 + Math.random() * 60, 15 + Math.random() * 30, Math.random() * 3, 0, 7); g.fill(); } }); }
function s3Pave() { return s3cv(512, 512, (g, w, h) => { g.fillStyle = '#7d776e'; g.fillRect(0, 0, w, h); for (let y = 0; y < h; y += 32) for (let x = (y / 32 % 2) * 21; x < w + 64; x += 64) { g.fillStyle = tint(Math.random() < .18 ? '#9c8f80' : '#b9b2a6', (Math.random() - .5) * .06); g.fillRect(x + 1.5, y + 1.5, 61, 29); } s3noise(g, w, h, 12000, ['0,0,0', '255,255,255'], .03, .09); }); }
function s3Rubber(c) { return s3cv(256, 256, (g, w, h) => { g.fillStyle = c; g.fillRect(0, 0, w, h); s3noise(g, w, h, 9000, ['0,0,0', '255,255,255'], .05, .14, 1.2); }); }
function s3Leaves(dark) { return s3cv(256, 256, (g, w, h) => { g.fillStyle = dark ? '#1c2c18' : '#3a5a2c'; g.fillRect(0, 0, w, h); const cs = dark ? ['#16241a', '#22361f', '#2c4226'] : ['#33502a', '#4d6b35', '#5d7a3d', '#3f5f2e', '#6f8a4a', '#2c4424', '#7d8a4a']; for (let i = 0; i < 2600; i++) { g.fillStyle = cs[i % cs.length]; g.beginPath(); g.ellipse(Math.random() * w, Math.random() * h, 2 + Math.random() * 3, 1 + Math.random() * 1.6, Math.random() * 3.14, 0, 7); g.fill(); } }); }
function s3Bark() { return s3cv(64, 256, (g, w, h) => { g.fillStyle = '#5a4a3c'; g.fillRect(0, 0, w, h); for (let i = 0; i < 90; i++) { g.fillStyle = `rgba(${Math.random() < .5 ? '30,24,18' : '140,120,100'},.35)`; g.fillRect(Math.random() * w, Math.random() * h, 1 + Math.random() * 2, 8 + Math.random() * 30); } }); }
/* Небо: равнопромежуточная панорама с облаками — фон и отражения в стёклах */
function s3Sky(night, sunAz, sunEl) {
  return s3cv(2048, 1024, (g, w, h) => {
    const gr = g.createLinearGradient(0, 0, 0, h / 2);
    if (night) { gr.addColorStop(0, '#04081a'); gr.addColorStop(.75, '#121a3c'); gr.addColorStop(1, '#3a2f4a'); } else { gr.addColorStop(0, '#2f63b3'); gr.addColorStop(.55, '#6f9fd8'); gr.addColorStop(.9, '#c7dbec'); gr.addColorStop(1, '#e6edf1'); }
    g.fillStyle = gr; g.fillRect(0, 0, w, h / 2 + 2);
    const gg = g.createLinearGradient(0, h / 2, 0, h); gg.addColorStop(0, night ? '#1c1c26' : '#9aa38f'); gg.addColorStop(.15, night ? '#0e0f14' : '#5f6852'); gg.addColorStop(1, night ? '#050507' : '#3a4034'); g.fillStyle = gg; g.fillRect(0, h / 2, w, h / 2);
    if (night) { for (let i = 0; i < 900; i++) { g.fillStyle = `rgba(255,255,255,${Math.random() * .8})`; g.fillRect(Math.random() * w, Math.random() * h * .42, 1.3, 1.3); } const hz = g.createLinearGradient(0, h * .42, 0, h / 2); hz.addColorStop(0, 'rgba(255,150,80,0)'); hz.addColorStop(1, 'rgba(255,160,90,.35)'); g.fillStyle = hz; g.fillRect(0, h * .42, w, h * .08); return; }
    const sx = (sunAz / (Math.PI * 2)) * w, sy = (0.5 - sunEl / Math.PI) * h;
    let sg = g.createRadialGradient(sx, sy, 0, sx, sy, 260); sg.addColorStop(0, 'rgba(255,250,235,1)'); sg.addColorStop(.06, 'rgba(255,244,214,.9)'); sg.addColorStop(.35, 'rgba(255,236,200,.25)'); sg.addColorStop(1, 'rgba(255,236,200,0)'); g.fillStyle = sg; g.fillRect(sx - 260, sy - 260, 520, 520);
    for (let c = 0; c < 70; c++) {   // кучевые облака
      const cx = Math.random() * w, cy = h * (0.12 + Math.random() * 0.33), s = 0.4 + Math.random() * (cy / h) * 2.2;
      for (let k = 0; k < 14; k++) { const x = cx + (Math.random() - .5) * 140 * s, y = cy + (Math.random() - .5) * 26 * s, r = (16 + Math.random() * 26) * s, cg = g.createRadialGradient(x, y - r * .3, 0, x, y, r);
        cg.addColorStop(0, 'rgba(255,255,255,.85)'); cg.addColorStop(.6, 'rgba(236,240,246,.55)'); cg.addColorStop(1, 'rgba(210,220,232,0)'); g.fillStyle = cg; g.beginPath(); g.ellipse(x, y, r * 1.6, r, 0, 0, 7); g.fill(); }
    }
    const hz = g.createLinearGradient(0, h * .44, 0, h / 2); hz.addColorStop(0, 'rgba(230,236,240,0)'); hz.addColorStop(1, 'rgba(232,236,238,.9)'); g.fillStyle = hz; g.fillRect(0, h * .44, w, h * .06);
  });
}

/* ---------- примитивы ---------- */
function s3m(c, r = 0.8, m = 0, o = {}) { return new THREE.MeshStandardMaterial(Object.assign({ color: new THREE.Color(c).convertSRGBToLinear(), roughness: r, metalness: m, envMapIntensity: S3.night ? .3 : .8 }, o)); }
function s3tm(t, rx, ry, r = .9, o = {}) { const c = t.clone(); c.needsUpdate = true; c.repeat.set(rx, ry); return s3m('#ffffff', r, 0, Object.assign({ map: c }, o)); }
function s3box(w, h, d, mat, x, y, z, sh = true, parent = S3.scene) { const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), mat); m.position.set(x, y + h / 2, z); m.castShadow = sh; m.receiveShadow = true; parent.add(m); return m; }
function s3plane(w, d, mat, x, y, z) { const m = new THREE.Mesh(new THREE.PlaneGeometry(w, d), mat); m.rotation.x = -Math.PI / 2; m.position.set(x, y, z); m.receiveShadow = true; S3.scene.add(m); return m; }
function s3inst(geo, mat, list, colors, sh = true) {
  if (!list.length) return null; const im = new THREE.InstancedMesh(geo, mat, list.length), o = new THREE.Object3D();
  list.forEach((t, i) => { o.position.set(t[0], t[1], t[2]); o.rotation.set(0, t[3] || 0, 0); o.scale.set(t[4] || 1, t[5] || t[4] || 1, t[6] || t[4] || 1); o.updateMatrix(); im.setMatrixAt(i, o.matrix); if (colors) im.setColorAt(i, new THREE.Color(colors[i % colors.length]).convertSRGBToLinear()); });
  im.castShadow = sh; im.receiveShadow = true; S3.scene.add(im); return im;
}
/* отрезки (бордюры, разметка): [x0, z0, x1, z1] */
function s3segs(segs, h, w, mat, y = 0, sh = false) { return s3inst(new THREE.BoxGeometry(1, 1, 1), mat, segs.map(([a, b, c, d]) => [(a + c) / 2, y + h / 2, (b + d) / 2, -Math.atan2(d - b, c - a), Math.hypot(c - a, d - b), h, w]), null, sh); }
const s3rbox = (w, h, d, r) => THREE.RoundedBoxGeometry ? new THREE.RoundedBoxGeometry(w, h, d, 2, r) : new THREE.BoxGeometry(w, h, d);
/* крона дерева: несколько «облаков» листвы с неровной поверхностью, снизу темнее */
function s3Crown(seed, lobes, rad, squash, det = 2) {
  const R = rng(seed), U = THREE.BufferGeometryUtils, gs = [];
  for (let i = 0; i < lobes; i++) {
    let g = new THREE.IcosahedronGeometry(rad * (0.55 + R() * 0.3), det); g.deleteAttribute('normal');
    if (U && U.mergeVertices) { g.deleteAttribute('uv'); g = U.mergeVertices(g); }
    const a = R() * 6.28, rr = i ? rad * (0.35 + R() * 0.3) : 0, oy = i ? (R() - .2) * rad * 0.7 : 0, pos = g.attributes.position, v = new THREE.Vector3(), uv = [], col = [];
    for (let k = 0; k < pos.count; k++) {
      v.fromBufferAttribute(pos, k); const n = v.clone().normalize(), d = 1 + 0.16 * Math.sin(n.x * 9.1 + i) * Math.sin(n.y * 7.3 + 1) * Math.sin(n.z * 8.7 + i * 2) + 0.05 * Math.sin(n.x * 23 + n.z * 19);
      v.multiplyScalar(d); v.x += Math.cos(a) * rr; v.z += Math.sin(a) * rr; v.y = v.y * squash + oy; pos.setXYZ(k, v.x, v.y, v.z);
      uv.push(0.5 + Math.atan2(n.z, n.x) / 6.283 * 3, n.y * 1.5 + 0.5); const sh = 0.55 + 0.45 * Math.min(1, Math.max(0, (n.y + 0.6) / 1.4)); col.push(sh, sh, sh);
    }
    g.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2)); g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3)); g.computeVertexNormals(); gs.push(g);
  }
  return U && U.mergeBufferGeometries ? (U.mergeBufferGeometries(gs) || gs[0]) : gs[0];
}
function s3Trees(list, kind, mats) {   // list: [x, z, scale, rot]
  if (!list.length) return;
  const spec = { round: [6, 2.5, 0.85, 5.0, 3.6, 2], street: [5, 2.4, 0.85, 5.0, 3.6, 1], tall: [5, 1.5, 2.1, 6.2, 3.2, 2], small: [4, 1.7, 0.9, 3.4, 2.3, 1] }[kind];
  const crown = s3Crown(kind.length * 7 + 3, spec[0], spec[1], spec[2], spec[5]), trunk = new THREE.CylinderGeometry(0.14, 0.26, spec[4], 7); trunk.translate(0, spec[4] / 2, 0);
  s3inst(trunk, mats.bark, list.map(t => [t[0], 0, t[1], t[3], t[2]]));
  s3inst(crown, mats.leaf, list.map(t => [t[0], spec[3] * t[2], t[1], t[3], t[2]]), S3.night ? ['#5d6e5a', '#4f604c'] : ['#ffffff', '#e8e6cf', '#d4dcc0', '#f0e8cc', '#c4ccb0', '#e0d8b8']);
}
function s3Label(text, x, y, z, color = '#ea580c', scale = 1) {
  const c = document.createElement('canvas'), g = c.getContext('2d'); g.font = 'bold 44px sans-serif'; const w = Math.ceil(g.measureText(text).width) + 44; c.width = w; c.height = 72;
  g.font = 'bold 44px sans-serif'; g.fillStyle = color; const r = 20; g.beginPath(); g.moveTo(r, 0); g.arcTo(w, 0, w, 72, r); g.arcTo(w, 72, 0, 72, r); g.arcTo(0, 72, 0, 0, r); g.arcTo(0, 0, w, 0, r); g.fill();
  g.fillStyle = '#fff'; g.textBaseline = 'middle'; g.fillText(text, 22, 38);
  const t = new THREE.CanvasTexture(c); t.encoding = THREE.sRGBEncoding;
  const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: t, depthTest: false, transparent: true })); s.scale.set(w / 72 * 2.6 * scale, 2.6 * scale, 1); s.position.set(x, y, z); s.renderOrder = 20; S3.scene.add(s); return s;
}

/* ---------- сборка сцены ---------- */
function s3Build(p) {
  const sc = new THREE.Scene(); S3.scene = sc; S3.lights = [];
  const b = bView(p), Ly = siteLayout(p), { L, Wd, SW, SD, z0 } = Ly, n = S3.night, prog = siteProgress(p), building = S3.mode === 'build' && prog.total < 100;
  const done = !building || prog.land >= 100, R = rng(11);
  // небо, окружение, свет
  const sunAz = 3.75, sunEl = 0.62;
  const sky = s3Sky(n, sunAz, sunEl); sky.mapping = THREE.EquirectangularReflectionMapping;
  if (S3.skyRT) S3.skyRT.dispose(); if (S3.envRT) S3.envRT.dispose();
  S3.skyRT = new THREE.WebGLCubeRenderTarget(1024).fromEquirectangularTexture(S3.renderer, sky); sc.background = S3.skyRT.texture;
  const pm = new THREE.PMREMGenerator(S3.renderer); S3.envRT = pm.fromEquirectangular(sky); pm.dispose(); sc.environment = S3.envRT.texture; sky.dispose();
  sc.fog = new THREE.Fog(n ? 0x1a1a2a : 0xdfe7ec, 260, 1500);
  S3.hemi = new THREE.HemisphereLight(n ? 0x30406a : 0xdde9ff, n ? 0x0a0a10 : 0x5f6a48, n ? 0.15 : 0.3); sc.add(S3.hemi);
  const sun = new THREE.DirectionalLight(n ? 0x9bb0ff : 0xffeccc, n ? 0.18 : 2.5), ext = Math.max(SW, SD) * 0.7;
  const sd = 260; sun.position.set(Math.cos(sunAz) * Math.cos(sunEl) * sd, Math.sin(sunEl) * sd, -Math.sin(sunAz) * Math.cos(sunEl) * sd);
  sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048); Object.assign(sun.shadow.camera, { left: -ext, right: ext, top: ext, bottom: -ext, near: 20, far: 600 }); sun.shadow.bias = -0.0004; sun.shadow.normalBias = 0.04; sc.add(sun); S3.sun = sun;
  // материалы
  const TX = { grass: s3Grass(n), asph: s3Asphalt(), pave: s3Pave(), leaf: s3Leaves(n), bark: s3Bark() };
  const curb = s3m('#c9c6bf', .85), mark = s3m('#f1f1ee', .6);
  // земля вокруг, улицы
  s3plane(5000, 5000, s3tm(TX.grass, 500, 500, 1, { color: new THREE.Color(n ? '#6b7562' : '#a8ab92').convertSRGBToLinear() }), 0, -0.03, 0);
  const rN = -SD / 2 - 9, rW = -SW / 2 - 9, rL = 900;
  s3plane(rL, 12, s3tm(TX.asph, rL / 10, 1.2), 0, 0.01, rN); s3plane(12, rL, s3tm(TX.asph, 1.2, rL / 10), rW, 0.012, 0);
  const dash = []; for (let x = -rL / 2; x < rL / 2; x += 6) if (Math.abs(x - rW) > 8) dash.push([x, rN, x + 3, rN]); for (let z = -rL / 2; z < rL / 2; z += 6) if (Math.abs(z - rN) > 8) dash.push([rW, z, rW, z + 3]);
  s3segs(dash, 0.012, 0.14, mark, 0.012);
  const zebra = []; for (let i = -5; i <= 5; i++) zebra.push([-SW / 4 + i * 0.9, rN - 5.5, -SW / 4 + i * 0.9, rN + 5.5]); s3segs(zebra, 0.013, 0.45, mark, 0.012);
  s3plane(rL, 3.2, s3tm(TX.pave, rL / 4, 0.8), 0, 0.03, rN + 7.6); s3plane(3.2, rL, s3tm(TX.pave, 0.8, rL / 4), rW + 7.6, 0.031, 0);
  s3plane(rL, 3.2, s3tm(TX.pave, rL / 4, 0.8), 0, 0.03, rN - 7.6); s3plane(3.2, rL, s3tm(TX.pave, 0.8, rL / 4), rW - 7.6, 0.031, 0);
  s3segs([[-rL / 2, rN + 6, rL / 2, rN + 6], [-rL / 2, rN - 6, rL / 2, rN - 6], [rW + 6, -rL / 2, rW + 6, rL / 2], [rW - 6, -rL / 2, rW - 6, rL / 2]], 0.15, 0.22, curb);
  // участок
  s3plane(SW, SD, building && !done ? s3m('#7d6a52', 1) : s3tm(TX.grass, SW / 7, SD / 7, 1, { color: new THREE.Color('#dcdccc').convertSRGBToLinear() }), 0, 0.005, 0);
  // парковка (север)
  const st = siteStats(p), pz = -SD / 2 + 7.5, pw = L + 24;
  s3plane(pw, 13, s3tm(TX.asph, pw / 10, 1.3), 0, 0.015, pz);
  s3segs([[-pw / 2, pz - 6.5, pw / 2, pz - 6.5], [-pw / 2, pz + 6.5, pw / 2, pz + 6.5], [-pw / 2, pz - 6.5, -pw / 2, pz + 6.5], [pw / 2, pz - 6.5, pw / 2, pz + 6.5]], 0.15, 0.2, curb);
  const lines = [], cars = []; let placed = 0; const north = Math.floor(pw / 2.6);
  for (let i = 0; i < north && placed < st.placed; i++, placed++) { const x = -pw / 2 + 1.3 + i * 2.6; lines.push([x - 1.3, pz - 6, x - 1.3, pz - 1]); if (!building && R() < 0.78) cars.push([x, pz - 3.5, Math.PI / 2 * 0 + (R() < .5 ? 0 : Math.PI)]); }
  const ex = SW / 2 - 6, eLen = SD - 30; if (placed < st.placed) { s3plane(12, eLen, s3tm(TX.asph, 1.2, eLen / 10), ex, 0.015, 0); s3segs([[ex - 6, -eLen / 2, ex - 6, eLen / 2], [ex + 6, -eLen / 2, ex + 6, eLen / 2]], 0.15, 0.2, curb); }
  for (let i = 0; placed < st.placed && i < Math.floor(eLen / 2.6); i++, placed++) { const z = -eLen / 2 + 1.3 + i * 2.6; lines.push([ex + 1, z - 1.3, ex + 6, z - 1.3]); if (!building && R() < 0.7) cars.push([ex + 3.4, z, Math.PI / 2 + (R() < .5 ? 0 : Math.PI)]); }
  s3segs(lines, 0.012, 0.12, mark, 0.016);
  for (let i = 0; i < 26; i++) { const x = (R() - .5) * rL * .8; if (Math.abs(x - rW) > 10 && R() < .7) cars.push([x, rN + (R() < .5 ? 3 : -3), Math.PI / 2 + (R() < .5 ? 0 : Math.PI)]); }   // машины на улице
  s3Cars(cars, n);
  // дорожки и площадь перед подъездами (юг)
  const zs = z0 + Wd / 2, cz0 = zs + 4, cz1 = SD / 2 - 4, cmid = (cz0 + cz1) / 2, wx = -L / 2 - 6, wz0 = -SD / 2 + 14, wz1 = zs + 3;
  s3plane(L + 8, 6, s3tm(TX.pave, (L + 8) / 4, 1.5), 0, 0.02, zs + 3);
  s3plane(4, cz1 - cz0, s3tm(TX.pave, 1, (cz1 - cz0) / 4), 0, 0.02, cmid); s3plane(SW - 16, 3, s3tm(TX.pave, (SW - 16) / 4, .75), 0, 0.021, cz1 - 1);
  s3plane(4, wz1 - wz0, s3tm(TX.pave, 1, (wz1 - wz0) / 4), wx, 0.02, (wz0 + wz1) / 2);
  s3segs([[-L / 2 - 4, zs + 6, -2, zs + 6], [2, zs + 6, L / 2 + 4, zs + 6], [-2, zs + 6, -2, cz1 - 2.5], [2, zs + 6, 2, cz1 - 2.5], [wx - 2, wz0, wx - 2, wz1 + 3], [wx + 2, wz0, wx + 2, zs]], 0.12, 0.16, curb);
  S3.paths = { wx, wz0, wz1, zs, gateX: -SW / 4, gateZ: -SD / 2 };
  // здание
  s3Building(p, Ly, prog, building);
  if (!done || building) { // стройплощадка
    const cab = s3m('#d9d4c4', 0.7), cab2 = s3m('#3d6fa8', 0.6);
    for (let i = 0; i < 6; i++) s3box(6, 2.6, 2.5, i % 2 ? cab : cab2, SW / 2 - 12, i < 3 ? 0 : 2.6, -SD / 2 + 24 + (i % 3) * 2.8);
    const pile = s3m('#8c8c8c', 0.9); for (let i = 0; i < 5; i++) s3box(4, 1.2, 2, pile, -SW / 2 + 12 + i * 6, 0, cmid + 6, true);
    s3box(SW, 2, 0.15, s3m('#2e5e9c', 0.6), 0, 0, SD / 2, false); s3box(SW, 2, 0.15, s3m('#2e5e9c', 0.6), 0, 0, -SD / 2, false);
  }
  const mats = { leaf: new THREE.MeshStandardMaterial({ map: TX.leaf, vertexColors: true, roughness: .95, metalness: 0, envMapIntensity: n ? .2 : .55 }), bark: s3m('#ffffff', .95, 0, { map: TX.bark }) };
  if (done) { s3Landscape(p, Ly, cz0, cz1, n, TX, mats); s3Fence(Ly); }
  // соседние дома и деревья вдоль улиц
  const nb = []; for (let i = 0; i < 14; i++) { const a = i / 14 * Math.PI * 2 + 0.2, r = Math.max(SW, SD) * 1.05 + 40 + R() * 90; nb.push([Math.cos(a) * r, Math.sin(a) * r, a]); }
  nb.forEach(([x, z, a], i) => {
    if (Math.abs(z - rN) < 30 || Math.abs(x - rW) < 30) return;
    const fl = 5 + (R() * 12 | 0), w = 24 + R() * 30, cols = Math.max(4, Math.round(w / 3.2));
    const F = s3Facade(cols, fl, i % 3, n, 40 + i, false), mL = s3FacadeMat(F), mS = s3FacadeMat(s3Facade(4, fl, i % 3, n, 80 + i, false)), roof = s3m('#5d5f63', .9);
    const mesh = s3box(w, fl * 3, 13, [mS, mS, roof, roof, mL, mL], x, 0, z, true); mesh.rotation.y = -a + Math.PI / 2;
  });
  const street = []; for (let x = -rL / 2 + 6; x < rL / 2; x += 11) if (Math.abs(x - rW) > 12 && Math.abs(x - S3.paths.gateX) > 7) street.push([x, rN - 10.5, 0.9 + R() * .3, R() * 6]);
  for (let z = -rL / 2 + 6; z < rL / 2; z += 11) if (Math.abs(z - rN) > 12) street.push([rW - 10.5, z, 0.9 + R() * .3, R() * 6]);
  s3Trees(street.filter(t => Math.hypot(t[0], t[1]) < 520), 'street', mats);
  S3.hlMesh = null; S3.routeDot = null; S3.routeCurve = null; if (S3.hl) s3AddHighlight(p, Ly);
  S3.dirty = 30; if (S3.renderer) S3.renderer.shadowMap.needsUpdate = true;
}
function s3Cars(cars, n) {
  if (!cars.length) return;
  const cols = ['#e9e9e7', '#151515', '#9aa3ad', '#7d1d1d', '#1f3d7a', '#c9c2b0', '#2f4f3a', '#f2f2f2', '#4a4d52', '#b8b8b8'];
  const off = (c, dx, dz) => [c[0] + Math.cos(c[2]) * dx + Math.sin(c[2]) * dz, c[1] - Math.sin(c[2]) * dx + Math.cos(c[2]) * dz];
  const paint = new THREE.MeshStandardMaterial({ roughness: .26, metalness: .55, envMapIntensity: n ? .4 : 1.3 });
  s3inst(s3rbox(1.82, 0.72, 4.45, 0.22), paint, cars.map(c => [c[0], 0.66, c[1], c[2]]), cols);
  s3inst(s3rbox(1.6, 0.62, 2.35, 0.24), new THREE.MeshStandardMaterial({ color: 0x11161c, roughness: .05, metalness: .9, envMapIntensity: n ? .4 : 1.4 }), cars.map(c => { const o = off(c, 0, -0.25); return [o[0], 1.3, o[1], c[2]]; }), null);
  const wg = new THREE.CylinderGeometry(0.34, 0.34, 0.24, 14); wg.rotateZ(Math.PI / 2);
  const wl = []; cars.forEach(c => [[-0.82, 1.38], [0.82, 1.38], [-0.82, -1.38], [0.82, -1.38]].forEach(([dx, dz]) => { const o = off(c, dx, dz); wl.push([o[0], 0.34, o[1], c[2]]); }));
  s3inst(wg, s3m('#141414', .8), wl, null, false);
  if (n) s3inst(new THREE.BoxGeometry(1.5, 0.12, 0.05), new THREE.MeshBasicMaterial({ color: 0xff3020 }), cars.map(c => { const o = off(c, 0, -2.24); return [o[0], 0.85, o[1], c[2]]; }), null, false);
}
function s3Building(p, Ly, prog, building) {
  const b = bView(p), { L, Wd, z0 } = Ly, n = S3.night, secs = Math.max(1, b.sections), sw = L / secs, fh = b.floorH, apf = b.aptsPerFloor;
  const comm = b.commercialFirst ? 1 : 0, cols = Math.ceil(apf / 2);
  const builtF = building ? Math.round(b.floors * prog.frame / 100) : b.floors;
  const finF = building ? Math.min(builtF, Math.round(b.floors * Math.min(prog.facade, prog.windows || prog.facade) / 100)) : b.floors;
  const roofM = s3m('#4f5155', 0.95), conc = s3m('#8e8c87', 0.95), slabM = s3m('#7c7a75', 0.95), parap = s3m('#d9d4cb', .8), dark = s3m('#3a3e44', .6, .3);
  const plinth = s3Plinth(), balc = [], rail = [], hand = [], colsL = [], pil = [];
  for (let s = 0; s < secs; s++) {
    const x = -L / 2 + sw * (s + 0.5), style = s % 3;
    if (builtF >= 1) {
      if (comm) { const F = s3ShopFacade(Math.max(2, Math.round(sw / 7)), n), Fs = s3ShopFacade(2, n), mL = s3FacadeMat(F), mS = s3FacadeMat(Fs); s3box(sw - 0.3, fh, Wd, [mS, mS, roofM, roofM, mL, mL], x, 0, z0); s3box(sw - 0.1, 0.35, Wd + 2.4, dark, x, fh - 0.35, z0); }
      else { const pm = s3m('#ffffff', .8, 0, { map: (() => { const t = plinth.clone(); t.needsUpdate = true; t.repeat.set(sw / 4, 1); return t; })() }); s3box(sw - 0.3, 1.0, Wd + 0.1, pm, x, 0, z0); }
    }
    const f0 = comm, fDone = Math.max(0, finF - f0), fBuilt = Math.max(0, builtF - f0);
    if (fDone > 0) {
      const FL = s3Facade(cols * 2, fDone, style, n, 100 + s, true), FN = s3Facade((apf - cols) * 2, fDone, style, n, 150 + s, false), FS = s3Facade(4, fDone, style, n, 200 + s, false);
      const mS = s3FacadeMat(FL), mN = s3FacadeMat(FN), mE = s3FacadeMat(FS);
      s3box(sw - 0.3, fDone * fh, Wd, [mE, mE, roofM, roofM, mS, mN], x, f0 * fh, z0);
      for (let f = 0; f < fDone; f++) for (let a = 0; a < cols; a++) { const bx = x - sw / 2 + (a + 0.5) * sw / cols, y = (f0 + f) * fh; balc.push([bx, y + 0.02, z0 + Wd / 2 + 0.7]); rail.push([bx, y + 0.65, z0 + Wd / 2 + 1.38]); hand.push([bx, y + 1.15, z0 + Wd / 2 + 1.38]); }
      if (s > 0) pil.push([x - sw / 2, f0 * fh, z0 + Wd / 2 + 0.12, 0, 1, fDone * fh, 1], [x - sw / 2, f0 * fh, z0 - Wd / 2 - 0.12, 0, 1, fDone * fh, 1]);
    }
    for (let f = fDone; f < fBuilt; f++) { const y = (f0 + f) * fh; s3box(sw - 0.3, 0.25, Wd, slabM, x, y + fh - 0.25, z0); for (let cx = -sw / 2 + 1; cx <= sw / 2 - 1; cx += 6) for (let cz = -Wd / 2 + 1; cz <= Wd / 2 - 1; cz += 5.5) colsL.push([x + cx, y + (fh - 0.25) / 2, z0 + cz]); }
    if (!building || builtF >= b.floors) { // кровля
      const top = b.floors * fh; [[sw - 0.3, 0.3, 0, -Wd / 2 + 0.15], [sw - 0.3, 0.3, 0, Wd / 2 - 0.15], [0.3, Wd, -sw / 2 + 0.3, 0], [0.3, Wd, sw / 2 - 0.3, 0]].forEach(([w, d, ox, oz]) => s3box(w, 1.1, d, parap, x + ox, top, z0 + oz));
      s3box(sw - 0.4, 0.25, Wd + 0.3, parap, x, top - 0.25, z0, false);
      s3box(4, 3, 5, s3m('#c9c2b4', .8), x, top, z0 - 2); for (let k = 0; k < 4; k++) s3box(1, 0.8, 1, s3m('#b0b4b8', .4, .7), x + 4 + k * 1.6, top, z0 + 3);
    }
    // вход в подъезд: козырёк, стеклянная дверь, номер подъезда
    if (builtF >= 1) {
      const dz = z0 + Wd / 2;
      s3box(4.4, 0.22, 2.6, dark, x, 3.1, dz + 1.3); [-1.9, 1.9].forEach(k => s3box(0.12, 3.1, 0.12, dark, x + k, 0, dz + 2.45));
      s3box(2.2, 2.6, 0.12, new THREE.MeshStandardMaterial({ color: 0x1b2430, roughness: .05, metalness: .85 }), x, 0, dz + 0.06);
      s3box(2.4, 0.12, 0.16, dark, x, 2.6, dz + 0.08);
      const t = s3cv(128, 128, g => { g.fillStyle = '#1f4e8c'; g.fillRect(0, 0, 128, 128); g.fillStyle = '#fff'; g.font = 'bold 84px sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(String(s + 1), 64, 70); });
      const plate = new THREE.Mesh(new THREE.PlaneGeometry(0.8, 0.8), new THREE.MeshBasicMaterial({ map: t })); plate.position.set(x + 1.6, 2.75, dz + 0.1); S3.scene.add(plate);
      s3plane(4.4, 2.6, s3m('#5d5a56', .7), x, 0.08, dz + 1.3);
    }
    if (building && prog.frame < 100) s3Crane(x + (s % 2 ? 6 : -6), z0 - Wd / 2 - 6, b.floors * fh + 14, s);
  }
  const bw = sw / cols - 0.5;
  s3inst(new THREE.BoxGeometry(bw, 0.2, 1.4), s3m('#ecebe7', .7), balc);
  s3inst(new THREE.BoxGeometry(bw, 1.0, 0.05), new THREE.MeshStandardMaterial({ color: 0x9fb7c8, roughness: .04, metalness: .6, transparent: true, opacity: .42, envMapIntensity: 1.5 }), rail, null, false);
  s3inst(new THREE.BoxGeometry(bw, 0.06, 0.08), dark, hand, null, false);
  s3inst(new THREE.BoxGeometry(0.5, 1, 0.5), s3m('#6f6a63', .8), pil);
  s3inst(new THREE.BoxGeometry(0.5, fh - 0.25, 0.5), conc, colsL);
  // мягкая тень у основания дома (эффект «прижатия» к земле)
  const ao = s3cv(256, 128, (g, w, h) => { g.fillStyle = '#000'; g.fillRect(0, 0, w, h); g.filter = 'blur(14px)'; g.fillStyle = '#fff'; g.fillRect(26, 22, w - 52, h - 44); }, false);
  const aoM = new THREE.MeshBasicMaterial({ color: 0x000000, alphaMap: ao, transparent: true, opacity: n ? .35 : .5, depthWrite: false }); ao.repeat.set(1, 1);
  s3plane(L + 10, Wd + 10, aoM, 0, 0.045, z0).receiveShadow = false;
  S3.topY = (building ? builtF : b.floors) * fh;
}
function s3Crane(x, z, h, s) {
  const y = s3m('#e8b10f', .5, .3), dk = s3m('#2b2b2b', .6);
  s3box(1.8, h, 1.8, y, x, 0, z); s3box(2.2, 2.2, 2.2, dk, x, h - 3, z + 1.4);
  const g = new THREE.Group(); g.position.set(x, h, z); g.rotation.y = s * 1.3 + 0.4; S3.scene.add(g);
  s3box(48, 1.2, 1.2, y, 22, 0, 0, true, g); s3box(14, 1.2, 1.2, y, -9, 0, 0, true, g); s3box(4, 2.5, 2.5, s3m('#7d7d7d', .9), -14, -1.3, 0, true, g); s3box(0.8, 4, 0.8, y, 0, 1.2, 0, true, g);
  s3box(0.06, 18, 0.06, dk, 30, -18, 0, false, g); s3box(0.8, 0.8, 0.8, dk, 30, -18.8, 0, true, g);
}
function s3Landscape(p, Ly, cz0, cz1, n, TX, mats) {
  const { L, SW, SD } = Ly, R = rng(5), cmid = (cz0 + cz1) / 2;
  // детская площадка: резиновое покрытие, песочница, горка, качели, карусель
  const px = -L / 4, pz = cmid;
  s3plane(26, 16, s3tm(s3Rubber('#b8604a'), 4, 3, .95), px, 0.03, pz); s3plane(12, 8, s3tm(s3Rubber('#3f7fb3'), 2, 1.5, .95), px + 6, 0.035, pz + 3);
  s3segs([[px - 13, pz - 8, px + 13, pz - 8], [px - 13, pz + 8, px + 13, pz + 8], [px - 13, pz - 8, px - 13, pz + 8], [px + 13, pz - 8, px + 13, pz + 8]], 0.18, 0.18, s3m('#cfccc5', .8));
  const red = s3m('#c9452e', .45), blue = s3m('#2a6fb8', .45), yel = s3m('#e8b52c', .45), wood = s3m('#8a6440', .75), met = s3m('#7b8086', .35, .7), hpl = s3m('#3c8c5a', .5);
  s3box(3, 3, 3, red, px - 7, 0, pz - 3); s3box(1.3, 0.12, 5, yel, px - 7, 1.6, pz + 1.2).rotation.x = 0.55; s3box(3.2, 1.6, 3.2, hpl, px - 7, 3, pz - 3); s3box(2.6, 0.12, 2.6, blue, px - 7, 4.6, pz - 3);
  [-1, 1].forEach(k => { const c = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 3, 8), met); c.position.set(px + 2 + k * 2, 1.5, pz - 4); c.castShadow = true; S3.scene.add(c); }); s3box(4.4, 0.12, 0.12, met, px + 2, 3, pz - 4); [-1, 1].forEach(k => s3box(0.6, 0.08, 0.3, wood, px + 2 + k, 0.5, pz - 4));
  const car = new THREE.Mesh(new THREE.CylinderGeometry(1.6, 1.6, 0.18, 32), yel); car.position.set(px + 7, 0.35, pz - 2); car.castShadow = true; S3.scene.add(car);
  [[0, -2], [0, 2], [-2, 0], [2, 0]].forEach(([a, c]) => s3box(Math.abs(a) ? 0.2 : 4, 0.35, Math.abs(c) ? 0.2 : 4, wood, px + 7 + a, 0, pz + 4 + c)); s3plane(3.8, 3.8, s3m('#e3cf9c', 1), px + 7, 0.2, pz + 4);
  // воркаут
  const wx = L / 4, wz = cmid; s3plane(18, 12, s3tm(s3Rubber('#3f6f50'), 3, 2, .95), wx, 0.03, wz);
  for (let i = 0; i < 4; i++) { s3box(0.12, 2.4, 0.12, met, wx - 6 + i * 4, 0, wz - 2); s3box(0.12, 2.4, 0.12, met, wx - 4.6 + i * 4, 0, wz - 2); s3box(1.5, 0.08, 0.08, met, wx - 5.3 + i * 4, 2.3, wz - 2); }
  // клумба в центре
  const fc = new THREE.Mesh(new THREE.CylinderGeometry(3, 3.2, 0.5, 40), s3m('#cbc6bd', .8)); fc.position.set(0, 0.25, cmid); fc.castShadow = true; fc.receiveShadow = true; S3.scene.add(fc);
  const fl = new THREE.Mesh(new THREE.CylinderGeometry(2.7, 2.7, 0.52, 40), s3m('#ffffff', .95, 0, { map: s3cv(128, 128, (g, w, h) => { g.fillStyle = '#3d6a2c'; g.fillRect(0, 0, w, h); s3noise(g, w, h, 900, ['200,40,80', '240,200,60', '250,250,250', '170,60,160', '230,110,40'], .7, 1, 3); }) })); fl.position.set(0, 0.28, cmid); S3.scene.add(fl);
  // лавочки и фонари
  const benches = [], lamps = []; for (let i = 0; i < 10; i++) benches.push([-SW / 2 + 14 + i * (SW - 28) / 9, 0.25, cz1 - 3.2]);
  [[px, pz - 9], [px, pz + 9], [wx, wz - 7.5], [wx, wz + 7.5]].forEach(([x, z]) => benches.push([x, 0.25, z]));
  for (let i = 0; i < 8; i++) { lamps.push([-SW / 2 + 10 + i * (SW - 20) / 7, 0, cz1 - 1]); lamps.push([-L / 2 - 2 + i * (L + 4) / 7, 0, -SD / 2 + 15]); }
  s3inst(new THREE.BoxGeometry(1.8, 0.08, 0.55), wood, benches.map(b => [b[0], 0.45, b[2]])); s3inst(new THREE.BoxGeometry(1.8, 0.45, 0.06), wood, benches.map(b => [b[0], 0.7, b[2] + 0.26])); s3inst(new THREE.BoxGeometry(0.08, 0.45, 0.5), dark3(), benches.flatMap(b => [[b[0] - .8, 0.22, b[2]], [b[0] + .8, 0.22, b[2]]]));
  s3inst(new THREE.CylinderGeometry(0.07, 0.1, 4.6, 8), s3m('#2b2f33', .45, .6), lamps.map(l => [l[0], 2.3, l[2]]));
  s3inst(new THREE.CylinderGeometry(0.32, 0.22, 0.3, 12), s3m('#2b2f33', .45, .6), lamps.map(l => [l[0], 4.7, l[2]]));
  s3inst(new THREE.CylinderGeometry(0.26, 0.26, 0.06, 12), new THREE.MeshStandardMaterial({ color: 0xfff3d6, emissive: n ? 0xffd28a : 0x000000, emissiveIntensity: n ? 3 : 0 }), lamps.map(l => [l[0], 4.54, l[2]]), null, false);
  if (n) lamps.filter((_, i) => i % 3 === 0).forEach(l => { const pl = new THREE.PointLight(0xffd29a, 1.8, 24, 2); pl.position.set(l[0], 4.3, l[2]); S3.scene.add(pl); });
  // деревья и кусты
  const round = [], tall = [], small = [];
  for (let x = -SW / 2 + 5; x <= SW / 2 - 5; x += 8) (R() < .35 ? tall : round).push([x + R() * 2, SD / 2 - 2, 0.85 + R() * 0.35, R() * 6]);
  for (let z = -SD / 2 + 22; z <= SD / 2 - 6; z += 8) tall.push([-SW / 2 + 3, z + R() * 2, 0.85 + R() * 0.3, R() * 6]);
  for (let i = 0; i < 22; i++) { const x = (R() - .5) * (SW - 20), z = cz0 + 3 + R() * (cz1 - cz0 - 8); if (Math.abs(x - px) < 15 && Math.abs(z - pz) < 10 || Math.abs(x - wx) < 11 && Math.abs(z - wz) < 8 || Math.abs(x) < 5) continue; (R() < .5 ? round : small).push([x, z, 0.8 + R() * 0.5, R() * 6]); }
  s3Trees(round, 'round', mats); s3Trees(tall, 'tall', mats); s3Trees(small, 'small', mats);
  const bushes = []; for (let x = -L / 2; x <= L / 2; x += 1.6) bushes.push([x, 0.45, cz0 - 0.6]); for (let x = -SW / 2 + 12; x < SW / 2 - 12; x += 1.8) if (Math.abs(x) > 3) bushes.push([x, 0.45, cz1 - 5]);
  const bushG = s3Crown(91, 3, 0.75, 0.75, 1);
  s3inst(bushG, mats.leaf, bushes.map(b => [b[0], b[1], b[2], R() * 6, 0.9 + R() * .3]), n ? ['#6d7d6a'] : ['#e8f2dc', '#d6e8c8', '#ffffff']);
}
const dark3 = () => s3m('#2b2f33', .5, .5);
function s3Fence(Ly) {
  const { SW, SD } = Ly, posts = [], m = s3m('#2d3237', .45, .6);
  const run = (x0, z0, x1, z1, gap) => { const len = Math.hypot(x1 - x0, z1 - z0), nn = Math.floor(len / 2.5); for (let i = 0; i <= nn; i++) { const t = i / nn, x = x0 + (x1 - x0) * t, z = z0 + (z1 - z0) * t; if (gap != null && Math.abs(x - gap) < 4) continue; posts.push([x, 0.9, z]); } };
  run(-SW / 2, -SD / 2, SW / 2, -SD / 2, -SW / 4); run(-SW / 2, SD / 2, SW / 2, SD / 2); run(-SW / 2, -SD / 2, -SW / 2, SD / 2); run(SW / 2, -SD / 2, SW / 2, SD / 2);
  s3inst(new THREE.BoxGeometry(0.07, 1.8, 0.07), m, posts);
  s3segs([[-SW / 2, -SD / 2, -SW / 4 - 4, -SD / 2], [-SW / 4 + 4, -SD / 2, SW / 2, -SD / 2], [-SW / 2, SD / 2, SW / 2, SD / 2], [-SW / 2, -SD / 2, -SW / 2, SD / 2], [SW / 2, -SD / 2, SW / 2, SD / 2]].flatMap(([a, b, c, d]) => [[a, b, c, d]]), 0.05, 0.05, m, 1.68);
  s3segs([[-SW / 2, -SD / 2, -SW / 4 - 4, -SD / 2], [-SW / 4 + 4, -SD / 2, SW / 2, -SD / 2], [-SW / 2, SD / 2, SW / 2, SD / 2], [-SW / 2, -SD / 2, -SW / 2, SD / 2], [SW / 2, -SD / 2, SW / 2, SD / 2]], 0.05, 0.05, m, 0.25);
}
/* ---------- запуск и управление ---------- */
function s3Init(el, hl) {
  s3Dispose(); S3.hl = hl || null; S3.hlMesh = null; if (!el) return;
  if (!window.THREE || !THREE.OrbitControls) { el.innerHTML = '<div class="note" style="margin:40px 16px">3D не загрузился: нужен интернет при первом открытии.</div>'; return; }
  const r = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
  S3.prLow = Math.min(1, window.devicePixelRatio || 1); S3.prHigh = Math.min(2, (window.devicePixelRatio || 1) * 1.5);
  r.setPixelRatio(S3.prLow); r.shadowMap.enabled = true; r.shadowMap.type = THREE.PCFSoftShadowMap; r.shadowMap.autoUpdate = false;
  r.outputEncoding = THREE.sRGBEncoding; r.toneMapping = THREE.ACESFilmicToneMapping; r.toneMappingExposure = 0.92; r.physicallyCorrectLights = false;
  el.prepend(r.domElement); S3.renderer = r; S3.el = el;
  S3.camera = new THREE.PerspectiveCamera(40, 1, 0.5, 4000);
  const c = S3.controls = new THREE.OrbitControls(S3.camera, r.domElement); c.enableDamping = true; c.maxPolarAngle = Math.PI * 0.485; c.minDistance = 20; c.maxDistance = 750;
  c.addEventListener('change', () => { S3.dirty = Math.max(S3.dirty, 3); S3.moved = performance.now(); });
  s3Build(P()); s3View(); if (S3.hl) (S3.route ? s3RouteView() : s3FocusApt());
  S3.ro = new ResizeObserver(() => { const w = el.clientWidth, h = el.clientHeight; if (!w || !h) return; r.setSize(w, h, false); S3.camera.aspect = w / h; S3.camera.updateProjectionMatrix(); S3.dirty = 5; S3.hq = false; }); S3.ro.observe(el);
  r.domElement.addEventListener('pointerdown', () => { if (S3.spin) { S3.spin = false; s3Tools(); } });
  S3.moved = performance.now(); S3.hq = false;
  const loop = () => {
    S3.raf = requestAnimationFrame(loop); const now = performance.now();
    if (S3.hlMesh) { S3.hlMesh.material.opacity = 0.55 + 0.3 * Math.sin(now / 280); S3.dirty = Math.max(S3.dirty, 1); }
    if (S3.routeDot && S3.routeCurve) { S3.routeDot.position.copy(S3.routeCurve.getPointAt((now / 7000) % 1)); S3.dirty = Math.max(S3.dirty, 1); }
    if (S3.spin) { c.autoRotate = true; c.autoRotateSpeed = 0.6; S3.dirty = 2; S3.moved = now; } else c.autoRotate = false;
    c.update();
    const anim = S3.hlMesh || S3.routeDot;
    if (S3.dirty > 0) { S3.dirty--; if (r.getPixelRatio() !== S3.prLow && now - S3.moved < 300) r.setPixelRatio(S3.prLow); r.render(S3.scene, S3.camera); S3.hq = false; }
    else if (!S3.hq && !anim && now - S3.moved > 350) { r.setPixelRatio(S3.prHigh); s3Still(); S3.hq = true; }   // чёткий фотокадр после остановки
  };
  S3.raf = requestAnimationFrame(loop); s3Tools();
}
/* Фото-кадр: объёмное затенение (SSAO), лёгкое свечение, сглаживание — рисуется, когда камера остановилась */
function s3Still() {
  const r = S3.renderer;
  if (!S3.photo || !(THREE.EffectComposer && THREE.SSAOPass && THREE.UnrealBloomPass && THREE.ShaderPass && THREE.GammaCorrectionShader && THREE.FXAAShader)) return r.render(S3.scene, S3.camera);
  try {
    const w = Math.max(1, S3.el.clientWidth), h = Math.max(1, S3.el.clientHeight), pr = r.getPixelRatio();
    if (!S3.comp || S3.comp.scene !== S3.scene || S3.comp.w !== w || S3.comp.h !== h || S3.comp.pr !== pr) {
      s3CompFree();
      const c = new THREE.EffectComposer(r); if (c.setPixelRatio) c.setPixelRatio(pr); c.setSize(w, h);
      const ssao = new THREE.SSAOPass(S3.scene, S3.camera, w * pr, h * pr); ssao.kernelRadius = 1.6; ssao.minDistance = 0.00002; ssao.maxDistance = 0.0012; c.addPass(ssao);
      c.addPass(new THREE.UnrealBloomPass(new THREE.Vector2(w, h), S3.night ? 0.55 : 0.1, 0.5, S3.night ? 0.75 : 0.97));
      c.addPass(new THREE.ShaderPass(THREE.GammaCorrectionShader));
      const fx = new THREE.ShaderPass(THREE.FXAAShader); fx.uniforms.resolution.value.set(1 / (w * pr), 1 / (h * pr)); c.addPass(fx);
      S3.comp = { c, scene: S3.scene, w, h, pr };
    }
    S3.comp.c.render();
  } catch (e) { console.warn('s3 photo off', e); S3.photo = false; s3CompFree(); r.render(S3.scene, S3.camera); }
}
function s3CompFree() { if (S3.comp) { try { S3.comp.c.renderTarget1.dispose(); S3.comp.c.renderTarget2.dispose(); S3.comp.c.passes.forEach(p => p.dispose && p.dispose()); } catch (e) { } S3.comp = null; } }
function s3View() { const Ly = siteLayout(P()), d = Math.max(Ly.SW, Ly.SD); S3.controls.target.set(0, Ly.H * 0.3, Ly.z0 * 0.3); S3.camera.position.set(d * 0.7, d * 0.42 + Ly.H * 0.35, d * 1.0); S3.camera.fov = 40; S3.camera.updateProjectionMatrix(); S3.controls.minDistance = 20; S3.controls.update(); S3.dirty = 10; S3.moved = performance.now(); }
function s3Rebuild() { if (!S3.renderer) return; s3Free(S3.scene); s3Build(P()); s3Tools(); }
function s3Free(sc) { if (!sc) return; sc.traverse(o => { if (o.geometry) o.geometry.dispose(); if (o.material) [].concat(o.material).forEach(m => { ['map', 'roughnessMap', 'metalnessMap', 'emissiveMap', 'alphaMap'].forEach(k => m[k] && m[k].dispose()); m.dispose(); }); }); }
function s3Dispose() {
  if (S3.raf) cancelAnimationFrame(S3.raf); S3.raf = 0; if (S3.ro) S3.ro.disconnect(); S3.ro = null;
  if (S3.renderer) { s3Free(S3.scene); if (S3.envRT) S3.envRT.dispose(); if (S3.skyRT) S3.skyRT.dispose(); S3.controls.dispose(); S3.renderer.dispose(); try { S3.renderer.forceContextLoss(); } catch (e) { } S3.renderer.domElement.remove(); }
  s3CompFree(); S3.renderer = null; S3.scene = null; S3.envRT = null; S3.skyRT = null;
}
function s3Tools() {
  const t = $('#s3Tools'); if (!t) return; const pr = siteProgress(P());
  t.innerHTML = `${S3.hl ? `<button class="${S3.route ? 'on' : ''}" data-act="s3Route">🚶 Маршрут до квартиры</button>` : `<button class="${S3.mode === 'done' ? 'on' : ''}" data-act="s3Mode" data-m="done">🏙 Готовый объект</button><button class="${S3.mode === 'build' ? 'on' : ''}" data-act="s3Mode" data-m="build">🏗 Ход строительства (${fmt(pr.total)}%)</button>`}
    <button class="${S3.night ? 'on' : ''}" data-act="s3Night">${S3.night ? '☀️ День' : '🌙 Вечер'}</button><button class="${S3.spin ? 'on' : ''}" data-act="s3Spin">🔄 Облёт</button><button data-act="s3Street">🚶 С улицы</button><button class="${S3.photo ? 'on' : ''}" data-act="s3Photo" title="Фото-качество: тени в углах, свечение, сглаживание">✨ Фото</button><button data-act="s3View">🎯 Вид</button><button data-act="s3Full">⛶</button><button data-act="s3Shot">📷</button>`;
}
ACT.s3Mode = d => { S3.mode = d.m; s3Rebuild(); };
ACT.s3Night = () => { S3.night = !S3.night; s3Rebuild(); };
ACT.s3Photo = () => { S3.photo = !S3.photo; s3CompFree(); S3.hq = false; S3.moved = 0; s3Tools(); };
ACT.s3Spin = () => { S3.spin = !S3.spin; s3Tools(); };
ACT.s3View = () => S3.hl ? (S3.route ? s3RouteView() : s3FocusApt()) : s3View();
ACT.s3Street = () => {   // взгляд человека со двора на дом
  const Ly = siteLayout(P()); if (!S3.camera) return; S3.spin = false;
  S3.controls.minDistance = 2; S3.camera.fov = 55; S3.camera.updateProjectionMatrix();
  S3.camera.position.set(-Ly.L * 0.42, 1.7, Ly.SD / 2 - 6); S3.controls.target.set(0, Ly.H * 0.42, Ly.z0); S3.controls.update(); S3.dirty = 10; S3.moved = performance.now(); s3Tools();
};
ACT.s3Route = () => { S3.route = !S3.route; s3Rebuild(); S3.route ? s3RouteView() : s3FocusApt(); };
ACT.s3Full = () => { const v = S3.el || $('#site3d'); if (!document.fullscreenElement) (v.requestFullscreen || v.webkitRequestFullscreen || (() => { })).call(v); else document.exitFullscreen(); };
ACT.s3Shot = () => { if (!S3.renderer) return; S3.renderer.setPixelRatio(S3.prHigh); s3Still(); S3.renderer.domElement.toBlob(b => download(`obekt_3d_${today()}.png`, b), 'image/png'); };
function site3dCard(p) {
  return `<div class="card"><h3>🏙 Объект в 3D: здание, озеленение, детская площадка, парковка</h3>
    <div class="site-viewer" id="site3d"><div class="v-tools" id="s3Tools"></div><div class="v-help">Мышь: вращать · колесо: приблизить · правая кнопка: сдвиг</div></div>
    <div id="s3stats">${s3StatsHtml(p)}</div><div class="small muted mt">Модель строится автоматически по параметрам проекта. Режим «Ход строительства» берёт готовность каркаса, фасада и окон из графика работ. Норма парковки — 1 место на 2 квартиры + для коммерции.</div></div>`;
}
function s3StatsHtml(p) {
  const st = siteStats(p), B = bld(p);
  return `<div class="big-res mt">
      ${rbox('Этажей / подъездов', b3(p))}${rbox('Квартир', fmt(B.apts))}${rbox('Парковка по норме', fmt(st.need) + ' мест', 1)}
      ${rbox('На участке', fmt(st.placed) + ' мест' + (st.under ? ` · ещё ${fmt(st.under)} — подземный паркинг` : ''))}${rbox('Озеленение', fmt(st.green) + ' м² (' + fmt(st.greenPct) + '%)', 1)}
      ${rbox('Детская площадка', fmt(st.play) + ' м²')}${rbox('Спортивная зона', fmt(st.sport) + ' м²')}${rbox('Площадь участка', fmt(st.area) + ' м²')}
    </div>`;
}
const b3 = p => `${bView(p).floors} / ${bView(p).sections}`;

/* отдельная страница в группе «Продажи и дизайн» */
PAGES.site = {
  title: 'Объект в 3D',
  render(p) { return site3dCard(p).replace('class="site-viewer"', 'class="site-viewer big"'); },
  mounted() { S3.route = false; s3Init($('#site3d')); },
  destroy() { s3Dispose(); }
};

/* ---------- квартира клиента: подсветка, маршрут от калитки до двери ---------- */
function s3AptGeom(p, Ly, h) {
  const b = bView(p), sw = Ly.L / b.sections, fh = b.floorH, x0 = -Ly.L / 2 + sw * (h.section - 1), xc = x0 + sw / 2;
  if (h.comm) return { x: xc, y: 0, z: Ly.z0, w: sw - 0.2, hh: fh, d: Ly.Wd + 0.6, xc, dir: 1, door: xc };
  const cw = sw / h.cols, x = x0 + cw * (h.col + 0.5), dir = h.row === 'south' ? 1 : -1;
  return { x, y: (h.floor - 1) * fh, z: Ly.z0 + dir * (Ly.Wd / 4 + 0.9), w: cw - 0.2, hh: fh - 0.1, d: Ly.Wd / 2 + 1.8, xc, dir, door: x };
}
function s3AddHighlight(p, Ly) {
  const h = S3.hl, G = s3AptGeom(p, Ly, h), { x, y, z, w, hh, d, dir } = G, zFace = Ly.z0 + dir * (Ly.Wd / 2 + 1.7);
  const m = new THREE.Mesh(new THREE.BoxGeometry(w, hh, d), new THREE.MeshBasicMaterial({ color: 0xff5a00, transparent: true, opacity: 0.8, depthWrite: false }));
  m.position.set(x, y + hh / 2, z); S3.scene.add(m); S3.hlMesh = m;
  const e = new THREE.LineSegments(new THREE.EdgesGeometry(m.geometry), new THREE.LineBasicMaterial({ color: 0xff3d00 })); e.position.copy(m.position); S3.scene.add(e);
  const top = bView(p).floors * p.b.floorH + 7, pin = new THREE.Mesh(new THREE.ConeGeometry(1.6, 3.4, 20), new THREE.MeshBasicMaterial({ color: 0xff3d00 }));
  pin.rotation.x = Math.PI; pin.position.set(x, top, zFace); S3.scene.add(pin);
  const ln = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.09, top - (y + hh), 6), new THREE.MeshBasicMaterial({ color: 0xff7a00 })); ln.position.set(x, (top + y + hh) / 2, zFace); S3.scene.add(ln);
  S3.hlInfo = { x, y, z, zFace, dir, hh };
  if (S3.route && !h.comm) s3AddRoute(p, Ly, h, G);
}
function s3AddRoute(p, Ly, h, G) {
  const P2 = S3.paths, ent = Ly.z0 + Ly.Wd / 2, fy = G.y + 0.4, V = (a, b, c) => new THREE.Vector3(a, b, c), y0 = 0.45;
  const pts = [V(P2.gateX, y0, P2.gateZ - 3), V(P2.gateX, y0, P2.wz0), V(P2.wx, y0, P2.wz0), V(P2.wx, y0, P2.wz1), V(G.xc, y0, P2.wz1), V(G.xc, y0, ent + 0.4), V(G.xc, y0, Ly.z0), V(G.xc, fy, Ly.z0), V(G.door, fy, Ly.z0), V(G.door, fy, Ly.z0 + G.dir * 1.6)];
  const path = new THREE.CurvePath(); for (let i = 1; i < pts.length; i++) if (pts[i].distanceTo(pts[i - 1]) > 0.01) path.add(new THREE.LineCurve3(pts[i - 1], pts[i]));
  const mat = new THREE.MeshBasicMaterial({ color: 0x0ea5e9, depthTest: false, transparent: true, opacity: .95 });
  const tube = new THREE.Mesh(new THREE.TubeGeometry(path, 400, 0.32, 8, false), mat); tube.renderOrder = 15; S3.scene.add(tube);
  const dot = new THREE.Mesh(new THREE.SphereGeometry(0.9, 16, 12), new THREE.MeshBasicMaterial({ color: 0xffffff, depthTest: false })); dot.renderOrder = 16; S3.scene.add(dot); S3.routeDot = dot; S3.routeCurve = path;
  s3Label('🚪 ' + T('Калитка'), P2.gateX, 5, P2.gateZ - 3, '#0f766e', .9);
  s3Label(`${T('Подъезд')} ${h.section}`, G.xc, 6.5, ent + 2, '#1d4ed8');
  s3Label(`🛗 ${h.floor} ${T('этаж')}`, G.xc - 4, fy + 3.5, Ly.z0, '#7c3aed', .9);
}
function s3RouteView() {
  const Ly = siteLayout(P()), i = S3.hlInfo; if (!S3.camera) return; S3.spin = false;
  S3.controls.minDistance = 20; S3.camera.fov = 40; S3.camera.updateProjectionMatrix();
  const d = Math.max(Ly.SW, Ly.SD); S3.controls.target.set(i ? i.x * 0.5 : 0, (i ? i.y : 0) * 0.4, Ly.z0);
  S3.camera.position.set(-d * 0.75, d * 0.55 + Ly.H * 0.3, d * 0.75); S3.controls.update(); S3.dirty = 10; S3.moved = performance.now(); s3Tools();
}
function s3FocusApt() {
  const i = S3.hlInfo; if (!i || !S3.camera) return; S3.spin = false;
  S3.controls.minDistance = 20; S3.controls.target.set(i.x, i.y + i.hh / 2, i.z);
  S3.camera.position.set(i.x + 38, Math.max(18, i.y + 24), i.zFace + i.dir * 82); S3.camera.fov = 40; S3.camera.updateProjectionMatrix(); S3.controls.update(); S3.dirty = 20; S3.moved = performance.now(); s3Tools();
}
function s3WindowView() {
  const i = S3.hlInfo; if (!i || !S3.camera) return; S3.spin = false;
  S3.controls.minDistance = 1; S3.camera.position.set(i.x, i.y + 1.6, i.zFace + i.dir * 0.4); S3.controls.target.set(i.x, i.y + 0.4, i.zFace + i.dir * 45);
  S3.camera.fov = 70; S3.camera.updateProjectionMatrix(); S3.controls.update(); S3.dirty = 20; S3.moved = performance.now();
  toast('Вид из окна квартиры: вращайте мышью, чтобы осмотреться');
}
