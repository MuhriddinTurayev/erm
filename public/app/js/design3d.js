'use strict';
/* ===== Фотореалистичная 3D-визуализация квартиры (Three.js r128) ===== */
const D3 = { walk: false, night: false, keys: {}, mv: 0, yaw: 0, pitch: 0, hq: true, dirty: 60 };
const STYLES = {
  modern: { name: 'Современный', wall: '#e6e2db', accentWall: '#46525c', floor: '#a47148', tile: '#d6d2cb', fabric: '#5f6872', fabric2: '#cdb99a', accent: '#c4973a', wood: '#4b3527', light: '#efe9df', metal: '#1f1f1f', kitchen: '#ecebe8', counter: '#2b2b2b', frame: '#2b2b2b', rug: '#b9b2a6', price: 2800000 },
  scandi: { name: 'Скандинавский', wall: '#f5f3ef', accentWall: '#a9bfb7', floor: '#d8be97', tile: '#ffffff', fabric: '#c9c4ba', fabric2: '#8fb0a5', accent: '#e3b04b', wood: '#d9b98e', light: '#ffffff', metal: '#3a3a3a', kitchen: '#ffffff', counter: '#cfa978', frame: '#ffffff', rug: '#e9e4da', price: 2300000 },
  classic: { name: 'Классика', wall: '#eadfcb', accentWall: '#7c5b46', floor: '#6b4428', tile: '#e7dfd0', fabric: '#7b2d3a', fabric2: '#d8c49a', accent: '#b8963e', wood: '#4a2e1d', light: '#f3ead8', metal: '#b8963e', kitchen: '#efe5d2', counter: '#e4ded4', frame: '#f3ead8', rug: '#8a3b3b', price: 3600000 },
  loft: { name: 'Лофт', wall: '#d9d4cc', accentWall: 'brick', floor: '#5b4535', tile: '#7d7d7b', fabric: '#3c3a37', fabric2: '#7a4a2a', accent: '#d0702e', wood: '#6b4a33', light: '#c9c4bb', metal: '#151515', kitchen: '#2e2e2e', counter: '#9c9b97', frame: '#151515', rug: '#6e6458', price: 2500000 },
  minimal: { name: 'Минимализм', wall: '#fafafa', accentWall: '#dcd7cf', floor: '#cbc3b6', tile: '#f0f0ee', fabric: '#e7e3dc', fabric2: '#b9b2a7', accent: '#1a1a1a', wood: '#e2d8ca', light: '#ffffff', metal: '#1a1a1a', kitchen: '#f8f8f8', counter: '#ffffff', frame: '#1a1a1a', rug: '#ecebe7', price: 2400000 },
  eco: { name: 'Эко / натуральный', wall: '#efe8dc', accentWall: '#6f7f5a', floor: '#b88c5e', tile: '#e8e0d2', fabric: '#a89f8a', fabric2: '#5f7350', accent: '#c47f45', wood: '#9a7048', light: '#f1ebe0', metal: '#2f2a24', kitchen: '#d9c9ad', counter: '#6d5a44', frame: '#9a7048', rug: '#cdbf9f', price: 2700000 },
};
const H = 2.8, TE = 0.25, TI = 0.1;
const HAS = () => ({ rb: !!THREE.RoundedBoxGeometry, ra: !!(THREE.RectAreaLight && THREE.RectAreaLightUniformsLib), room: !!THREE.RoomEnvironment, pp: !!(THREE.EffectComposer && THREE.SSAOPass && THREE.UnrealBloomPass && THREE.ShaderPass && THREE.GammaCorrectionShader && THREE.FXAAShader) });

/* ---------- Планировка ---------- */
function planApt(n, area) {
  n = Math.max(1, Math.min(3, n | 0 || 1));
  const Df = { 1: 4.6, 2: 5.0, 3: 5.2 }[n], C = 1.4, Bk = 2.4, D = Df + C + Bk;
  const W = Math.max({ 1: 5.4, 2: 7.0, 3: 9.2 }[n], area / D);
  const shares = { 1: [['room', 'Комната', .6], ['kitchen', 'Кухня', .4]], 2: [['bedroom', 'Спальня', .42], ['kl', 'Кухня-гостиная', .58]], 3: [['bedroom', 'Спальня', .3], ['kl', 'Кухня-гостиная', .42], ['bedroom', 'Детская', .28]] }[n];
  const rooms = []; let x = 0;
  shares.forEach(([type, name, s]) => { const w = W * s; rooms.push({ type, name, x, z: 0, w, d: Df }); x += w; });
  rooms.push({ type: 'corr', name: 'Коридор', x: 0, z: Df, w: W, d: C });
  const bathW = Math.min(2.6, Math.max(2.0, W * 0.3)), stW = n >= 2 ? 1.8 : 0;
  rooms.push({ type: 'bath', name: 'Ванная', x: 0, z: Df + C, w: bathW, d: Bk });
  rooms.push({ type: 'hall', name: 'Прихожая', x: bathW, z: Df + C, w: W - bathW - stW, d: Bk });
  if (stW) rooms.push({ type: n >= 3 ? 'wc' : 'closet', name: n >= 3 ? 'Санузел' : 'Гардеробная', x: W - stW, z: Df + C, w: stW, d: Bk });
  rooms.forEach(r => { r.ix0 = r.x + (r.x < 0.01 ? 0 : TI / 2); r.ix1 = r.x + r.w - (r.x + r.w > W - 0.01 ? 0 : TI / 2); r.iz0 = r.z + (r.z < 0.01 ? 0 : TI / 2); r.iz1 = r.z + r.d - (r.z + r.d > D - 0.01 ? 0 : TI / 2); });
  const front = rooms.filter(r => r.z === 0), hall = rooms.find(r => r.type === 'hall');
  const walls = [
    { axis: 'x', c: -TE / 2, a0: -TE, a1: W + TE, t: TE, ext: 1, inn: 1, ops: front.map(r => r.type === 'kl' ? { at: r.x + r.w / 2, w: Math.min(r.w - 1, 2.6), b: 0.25, top: 2.5, kind: 'window' } : { at: r.x + r.w / 2, w: Math.min(r.w - 0.9, 1.6), b: 0.8, top: 2.45, kind: 'window' }) },
    { axis: 'x', c: D + TE / 2, a0: -TE, a1: W + TE, t: TE, ext: 1, inn: -1, ops: [{ at: hall.x + hall.w / 2, w: 0.96, b: 0, top: 2.1, kind: 'entry' }] },
    { axis: 'z', c: -TE / 2, a0: 0, a1: D, t: TE, ext: 1, inn: 1 },
    { axis: 'z', c: W + TE / 2, a0: 0, a1: D, t: TE, ext: 1, inn: -1 },
    { axis: 'x', c: Df, a0: 0, a1: W, t: TI, ops: front.map(r => r.type === 'kl' ? { at: r.x + r.w - 0.85, w: 1.1, b: 0, top: 2.2, kind: 'door' } : { at: r.x + r.w - 0.6, w: 0.86, b: 0, top: 2.1, kind: 'door', leaf: true, swing: -1, hinge: 1 }) },
    { axis: 'x', c: Df + C, a0: 0, a1: bathW, t: TI, ops: [{ at: bathW / 2, w: 0.78, b: 0, top: 2.05, kind: 'door', leaf: true, swing: 1, hinge: -1 }] },
    { axis: 'z', c: bathW, a0: Df + C, a1: D, t: TI },
  ];
  front.slice(0, -1).forEach(r => walls.push({ axis: 'z', c: r.x + r.w, a0: 0, a1: Df, t: TI }));
  if (stW) {
    walls.push({ axis: 'x', c: Df + C, a0: W - stW, a1: W, t: TI, ops: [{ at: W - stW / 2, w: 0.72, b: 0, top: 2.05, kind: 'door', leaf: true, swing: 1, hinge: 1 }] });
    walls.push({ axis: 'z', c: W - stW, a0: Df + C, a1: D, t: TI });
  }
  return { W, D, Df, C, rooms, walls, n };
}

/* ---------- Процедурные текстуры ---------- */
const rnd = (a, b) => a + Math.random() * (b - a);
function cvTex(w, h, draw, srgb = true) {
  const c = document.createElement('canvas'); c.width = w; c.height = h; draw(c.getContext('2d'), w, h);
  const t = new THREE.CanvasTexture(c); t.wrapS = t.wrapT = THREE.RepeatWrapping; if (srgb) t.encoding = THREE.sRGBEncoding;
  t.anisotropy = D3.renderer ? D3.renderer.capabilities.getMaxAnisotropy() : 4; return t;
}
function tint(hex, dl, ds = 0) { const c = new THREE.Color(hex), h = {}; c.getHSL(h); c.setHSL(h.h, Math.min(1, Math.max(0, h.s + ds)), Math.min(1, Math.max(0, h.l + dl))); return '#' + c.getHexString(); }
function rep(t, x, y) { const c = t.clone(); c.needsUpdate = true; c.repeat.set(x, y); return c; }
function parquetTex(base) {
  const planks = [];
  const col = cvTex(1024, 1024, (g, w, h) => {
    const pw = 92; g.fillStyle = tint(base, -0.22); g.fillRect(0, 0, w, h);
    for (let y = 0; y < h; y += pw) {
      let x = -Math.random() * 400;
      while (x < w) {
        const len = 380 + Math.random() * 420; planks.push([x, y, len, pw]);
        const grd = g.createLinearGradient(x, y, x + len, y + pw); const c0 = tint(base, rnd(-.06, .05), rnd(-.05, .05));
        grd.addColorStop(0, c0); grd.addColorStop(1, tint(c0, rnd(-.04, .03))); g.fillStyle = grd; g.fillRect(x + 1.5, y + 1.5, len - 3, pw - 3);
        g.globalAlpha = 0.09;
        for (let k = 0; k < 22; k++) { g.strokeStyle = Math.random() < .55 ? '#000' : '#fff'; g.lineWidth = rnd(.6, 2); g.beginPath(); const yy = y + 4 + Math.random() * (pw - 8); g.moveTo(x, yy); g.bezierCurveTo(x + len * .3, yy + rnd(-6, 6), x + len * .65, yy + rnd(-6, 6), x + len, yy + rnd(-3, 3)); g.stroke(); }
        if (Math.random() < .25) { g.globalAlpha = .18; g.fillStyle = tint(base, -.25); g.beginPath(); g.ellipse(x + rnd(40, len - 40), y + pw / 2, rnd(6, 14), rnd(3, 6), 0, 0, 7); g.fill(); }
        g.globalAlpha = 1; x += len;
      }
    }
  });
  const bump = cvTex(1024, 1024, (g, w, h) => { g.fillStyle = '#bbb'; g.fillRect(0, 0, w, h); g.fillStyle = '#444'; planks.forEach(([x, y, len, pw]) => { g.fillRect(x, y, len, 2); g.fillRect(x, y, 2, pw); }); }, false);
  return { col, bump };
}
function tileTex(base, marble) {
  const n = 2;
  const col = cvTex(512, 512, (g, w, h) => {
    const s = w / n; g.fillStyle = tint(base, -0.14); g.fillRect(0, 0, w, h);
    for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) {
      g.fillStyle = tint(base, rnd(-.02, .02)); g.fillRect(i * s + 2, j * s + 2, s - 4, s - 4);
      if (marble) { g.globalAlpha = 0.16; g.strokeStyle = tint(base, -0.35); for (let k = 0; k < 5; k++) { g.lineWidth = rnd(.5, 1.8); g.beginPath(); let px = i * s + Math.random() * s, py = j * s; g.moveTo(px, py); for (let q = 0; q < 10; q++) { px += rnd(-25, 25); py += s / 10; g.lineTo(px, py); } g.stroke(); } g.globalAlpha = 1; }
    }
  });
  const bump = cvTex(512, 512, (g, w, h) => { const s = w / n; g.fillStyle = '#ddd'; g.fillRect(0, 0, w, h); g.fillStyle = '#555'; for (let i = 0; i <= n; i++) { g.fillRect(i * s - 2, 0, 4, h); g.fillRect(0, i * s - 2, w, 4); } }, false);
  return { col, bump };
}
function noiseBump(sz = 256, amt = 40) { return cvTex(sz, sz, (g, w, h) => { const d = g.createImageData(w, h); for (let i = 0; i < d.data.length; i += 4) { const v = 128 + (Math.random() - .5) * amt; d.data[i] = d.data[i + 1] = d.data[i + 2] = v; d.data[i + 3] = 255; } g.putImageData(d, 0, 0); }, false); }
function fabricTex(base) {
  return cvTex(256, 256, (g, w, h) => {
    g.fillStyle = base; g.fillRect(0, 0, w, h);
    for (let y = 0; y < h; y += 2) { g.fillStyle = `rgba(0,0,0,${rnd(.02, .07)})`; g.fillRect(0, y, w, 1); }
    for (let x = 0; x < w; x += 2) { g.fillStyle = `rgba(255,255,255,${rnd(.02, .06)})`; g.fillRect(x, 0, 1, h); }
    for (let k = 0; k < 900; k++) { g.fillStyle = `rgba(0,0,0,${rnd(0, .05)})`; g.fillRect(Math.random() * w, Math.random() * h, 1, 1); }
  });
}
function woodTex(base) {
  return cvTex(512, 512, (g, w, h) => {
    g.fillStyle = base; g.fillRect(0, 0, w, h);
    for (let k = 0; k < 70; k++) { g.strokeStyle = Math.random() < .6 ? `rgba(0,0,0,${rnd(.04, .12)})` : `rgba(255,255,255,${rnd(.03, .08)})`; g.lineWidth = rnd(.5, 2.5); g.beginPath(); const y = Math.random() * h; g.moveTo(0, y); g.bezierCurveTo(w * .3, y + rnd(-14, 14), w * .7, y + rnd(-14, 14), w, y + rnd(-6, 6)); g.stroke(); }
  });
}
function rugTex(base, acc) {
  return cvTex(512, 512, (g, w, h) => {
    g.fillStyle = base; g.fillRect(0, 0, w, h);
    g.strokeStyle = tint(acc, .1); g.globalAlpha = .55; g.lineWidth = 10; g.strokeRect(26, 26, w - 52, h - 52); g.lineWidth = 3; g.strokeRect(48, 48, w - 96, h - 96);
    g.globalAlpha = .22; g.fillStyle = tint(base, -.2); for (let i = 0; i < 6; i++) for (let j = 0; j < 6; j++) { g.save(); g.translate(80 + i * 70, 80 + j * 70); g.rotate(Math.PI / 4); g.fillRect(-12, -12, 24, 24); g.restore(); }
    g.globalAlpha = 1; for (let k = 0; k < 6000; k++) { g.fillStyle = `rgba(${Math.random() < .5 ? '0,0,0' : '255,255,255'},${rnd(0, .07)})`; g.fillRect(Math.random() * w, Math.random() * h, 1.5, 1.5); }
  });
}
function brickTex() {
  return cvTex(1024, 1024, (g, w, h) => {
    g.fillStyle = '#cfc6ba'; g.fillRect(0, 0, w, h); const bh = 34, bw = 128;
    for (let r = 0, y = 0; y < h; r++, y += bh) for (let x = (r % 2) * -bw / 2; x < w; x += bw) {
      g.fillStyle = tint('#9a5a3e', rnd(-.07, .06), rnd(-.05, .05)); g.fillRect(x + 3, y + 3, bw - 6, bh - 6);
      g.globalAlpha = .15; g.fillStyle = '#000'; for (let k = 0; k < 18; k++) g.fillRect(x + Math.random() * bw, y + Math.random() * bh, 2, 2); g.globalAlpha = 1;
    }
  });
}
function artTex(S) {
  return cvTex(512, 384, (g, w, h) => {
    const bg = g.createLinearGradient(0, 0, w, h); bg.addColorStop(0, tint(S.wall, .05)); bg.addColorStop(1, tint(S.fabric2, .15)); g.fillStyle = bg; g.fillRect(0, 0, w, h);
    const cols = [S.accent, S.fabric, S.fabric2, S.wood, tint(S.accent, .2)];
    for (let i = 0; i < 7; i++) { g.globalAlpha = rnd(.55, .9); g.fillStyle = cols[i % cols.length]; g.beginPath(); if (Math.random() < .5) g.arc(Math.random() * w, Math.random() * h, rnd(30, 110), 0, 7); else { g.moveTo(Math.random() * w, h); g.quadraticCurveTo(Math.random() * w, Math.random() * h, Math.random() * w, h); } g.fill(); }
    g.globalAlpha = 1;
  });
}
function skylineTex(night) {
  return cvTex(4096, 1024, (g, w, h) => {
    const sky = g.createLinearGradient(0, 0, 0, h * .55);
    if (night) { sky.addColorStop(0, '#0b1230'); sky.addColorStop(1, '#3a3560'); } else { sky.addColorStop(0, '#5f97d6'); sky.addColorStop(1, '#d6e6f4'); }
    g.fillStyle = sky; g.fillRect(0, 0, w, h);
    if (!night) { g.globalAlpha = .55; g.fillStyle = '#fff'; for (let i = 0; i < 26; i++) { const cx = Math.random() * w, cy = rnd(60, 320); for (let k = 0; k < 6; k++) { g.beginPath(); g.ellipse(cx + rnd(-80, 80), cy + rnd(-12, 12), rnd(40, 110), rnd(14, 30), 0, 0, 7); g.fill(); } } g.globalAlpha = 1; }
    else { g.fillStyle = '#fff'; for (let i = 0; i < 400; i++) { g.globalAlpha = rnd(.2, .9); g.fillRect(Math.random() * w, Math.random() * h * .4, 1.5, 1.5); } g.globalAlpha = 1; }
    const layer = (n, hmin, hmax, base, lit, alpha) => {
      g.globalAlpha = alpha; let x = 0;
      while (x < w) { const bw = rnd(40, 140), top = h * .5 + rnd(-hmax, -hmin) + 40; g.fillStyle = tint(base, rnd(-.05, .05)); g.fillRect(x, top, bw, h - top);
        for (let wy = top + 10; wy < h - 10; wy += 14) for (let wx = x + 6; wx < x + bw - 8; wx += 12) { const on = Math.random() < (night ? lit : .08); g.fillStyle = on ? (night ? `rgba(255,${190 + rnd(0, 50) | 0},120,.9)` : 'rgba(255,255,255,.35)') : (night ? 'rgba(10,12,30,.6)' : 'rgba(40,60,80,.25)'); g.fillRect(wx, wy, 6, 8); }
        x += bw + rnd(2, 30); }
      g.globalAlpha = 1;
    };
    layer(1, 20, 150, night ? '#1f2240' : '#a9b6c4', .35, .75);
    layer(2, -40, 90, night ? '#141629' : '#7d8a99', .45, 1);
    g.fillStyle = night ? '#0d1a12' : '#4f6b3f'; for (let i = 0; i < 260; i++) { g.globalAlpha = rnd(.6, 1); g.beginPath(); g.arc(Math.random() * w, h * .5 + 150 + Math.random() * 120, rnd(12, 34), 0, 7); g.fill(); }
    g.globalAlpha = 1; g.fillStyle = night ? '#0a0c14' : '#59605a'; g.fillRect(0, h * .5 + 230, w, h);
  });
}
function makeEnv(renderer) {
  const pm = new THREE.PMREMGenerator(renderer); let t;
  if (THREE.RoomEnvironment) t = pm.fromScene(new THREE.RoomEnvironment(), 0.04).texture;
  else {
    const sc = new THREE.Scene(), room = new THREE.Mesh(new THREE.BoxGeometry(12, 6, 12), new THREE.MeshBasicMaterial({ color: 0x3a3836, side: THREE.BackSide })); room.position.y = 3; sc.add(room);
    const p = new THREE.Mesh(new THREE.PlaneGeometry(8, 3), new THREE.MeshBasicMaterial({ color: new THREE.Color(1.9, 2, 2.3), side: THREE.DoubleSide })); p.position.set(0, 2.5, -5.9); sc.add(p);
    t = pm.fromScene(sc, 0.04).texture;
  }
  pm.dispose(); return t;
}
function mkMats(S) {
  const std = (c, r = 0.8, m = 0, o = {}) => new THREE.MeshStandardMaterial(Object.assign({ color: new THREE.Color(c), roughness: r, metalness: m, envMapIntensity: 0.6 }, o));
  const pq = parquetTex(S.floor), tl = tileTex(S.tile, S === STYLES.classic || S === STYLES.minimal), tw = tileTex(tint(S.tile, 0.02), S === STYLES.classic);
  const nb = noiseBump(256, 60), wood = woodTex(S.wood), woodL = woodTex(S.light);
  const fab = c => { const t = rep(fabricTex(c), 3, 3); return std('#ffffff', 0.95, 0, { map: t, bumpMap: t, bumpScale: 0.004, envMapIntensity: 0.3 }); };
  return {
    wall: std(S.wall, 0.92, 0, { bumpMap: rep(nb, 6, 6), bumpScale: 0.0012, envMapIntensity: 0.3 }),
    slab: std('#a29d95', 0.95), ceil: std('#fbfaf7', 0.95, 0, { envMapIntensity: 0.3 }),
    floorFor: (w, d) => std('#ffffff', 0.38, 0, { map: rep(pq.col, w / 2, d / 2), bumpMap: rep(pq.bump, w / 2, d / 2), bumpScale: 0.003, envMapIntensity: 0.45 }),
    tileFor: (w, d) => std('#ffffff', 0.18, 0, { map: rep(tl.col, w / 1.2, d / 1.2), bumpMap: rep(tl.bump, w / 1.2, d / 1.2), bumpScale: 0.004, envMapIntensity: 0.9 }),
    tileWall: (w, h) => std('#ffffff', 0.12, 0, { map: rep(tw.col, w / 0.9, h / 0.9), bumpMap: rep(tw.bump, w / 0.9, h / 0.9), bumpScale: 0.004, envMapIntensity: 1 }),
    fabric: fab(S.fabric), fabric2: fab(S.fabric2), blanket: fab(tint(S.fabric, 0.06)), linen: fab('#f4f2ed'), curtain: (() => { const m = fab(tint(S.fabric2, 0.22)); m.side = THREE.DoubleSide; m.transparent = true; m.opacity = 0.93; return m; })(),
    accent: std(S.accent, 0.4, 0.4), wood: std('#ffffff', 0.5, 0, { map: rep(wood, 1, 1), color: new THREE.Color('#ffffff') }), light: std('#ffffff', 0.55, 0, { map: woodL }),
    lightPlain: std(S.light, 0.45), metal: std(S.metal, 0.3, 0.8), kitchen: std(S.kitchen, 0.28), counter: std(S.counter, 0.15, 0.05, { envMapIntensity: 1 }), steel: std('#c9ccce', 0.22, 0.9, { envMapIntensity: 1.2 }), black: std('#141414', 0.35),
    ceramic: std('#fbfbfa', 0.06, 0, { envMapIntensity: 1.1 }), glass: new THREE.MeshPhysicalMaterial({ color: 0xe8f4fb, roughness: 0.02, metalness: 0, transparent: true, opacity: 0.12, depthWrite: false, envMapIntensity: 1.2 }),
    frame: std(S.frame, 0.4, 0.15), sill: std('#f2f0ec', 0.3), rug: std('#ffffff', 1, 0, { map: rugTex(S.rug, S.accent), envMapIntensity: 0.2 }), trim: std(S.light, 0.4), entry: std('#2d2a28', 0.35, 0.55),
    pot: std('#d9d4cc', 0.6), soil: std('#3b2a1e', 1), leaf: std('#3f6b3a', 0.6, 0, { side: THREE.DoubleSide }), leaf2: std('#5a8a4c', 0.6, 0, { side: THREE.DoubleSide }), stem: std('#4a3b22', 0.8),
    screen: std('#050505', 0.05, 0.3, { envMapIntensity: 1.4 }), mirror: std('#e6ecef', 0.02, 1, { envMapIntensity: 1.6 }), water: std('#cfe4ec', 0.05, 0.1),
    lampOn: new THREE.MeshStandardMaterial({ color: 0xfff2d8, emissive: 0xffd9a0, emissiveIntensity: 1.4, roughness: 0.6 }), lampDisc: new THREE.MeshStandardMaterial({ color: 0xf6f3ee, emissive: 0xffe2b8, emissiveIntensity: 0.55, roughness: 0.5 }), bulb: new THREE.MeshBasicMaterial({ color: 0xfff1d6 }), art: std('#ffffff', 0.8, 0, { map: artTex(S) }),
    book: ['#7b2d3a', '#2f4858', '#c4973a', '#556b2f', '#e8e2d6', '#3b3b3b'].map(c => std(c, 0.7)),
    accentWall: S.accentWall === 'brick' ? std('#ffffff', 0.9, 0, { map: brickTex(), bumpMap: brickTex(), bumpScale: 0.01 }) : std(S.accentWall, 0.9, 0, { bumpMap: rep(nb, 4, 4), bumpScale: 0.0015 }),
  };
}

/* ---------- Примитивы ---------- */
const GEO = {};
function G(x, z, ry = 0, parent = D3.scene) { const g = new THREE.Group(); g.position.set(x, 0, z); g.rotation.y = ry; parent.add(g); return g; }
function boxGeo(w, h, d, r) {
  if (!r || !THREE.RoundedBoxGeometry) return new THREE.BoxGeometry(w, h, d);
  const rr = Math.min(r, w / 2 - 0.001, h / 2 - 0.001, d / 2 - 0.001), k = [w, h, d, rr].map(v => v.toFixed(3)).join('_');
  return GEO[k] || (GEO[k] = new THREE.RoundedBoxGeometry(w, h, d, 2, rr));
}
function bx(g, w, h, d, m, x = 0, y = 0, z = 0, sh = true, r = 0) { const ms = new THREE.Mesh(boxGeo(w, h, d, r), m); ms.position.set(x, y + h / 2, z); ms.castShadow = sh; ms.receiveShadow = true; g.add(ms); return ms; }
function cy(g, rt, rb, h, m, x = 0, y = 0, z = 0, seg = 18) { const ms = new THREE.Mesh(new THREE.CylinderGeometry(rt, rb, h, seg), m); ms.position.set(x, y + h / 2, z); ms.castShadow = true; ms.receiveShadow = true; g.add(ms); return ms; }
function sp(g, r, m, x, y, z, sx = 1, sy = 1, sz = 1) { const ms = new THREE.Mesh(new THREE.SphereGeometry(r, 16, 12), m); ms.position.set(x, y, z); ms.scale.set(sx, sy, sz); ms.castShadow = true; ms.receiveShadow = true; g.add(ms); return ms; }
function legs(g, w, d, h, m, r = 0.018, inset = 0.06) { [[-1, -1], [1, -1], [-1, 1], [1, 1]].forEach(([a, b]) => cy(g, r, r * 0.8, h, m, a * (w / 2 - inset), 0, b * (d / 2 - inset), 12)); }
let LEAF_GEO = null;
function leafGeo() {
  if (LEAF_GEO) return LEAF_GEO;
  const s = new THREE.Shape(); s.moveTo(0, 0); s.bezierCurveTo(0.05, 0.04, 0.05, 0.14, 0, 0.2); s.bezierCurveTo(-0.05, 0.14, -0.05, 0.04, 0, 0);
  return (LEAF_GEO = new THREE.ShapeGeometry(s, 6));
}

/* ---------- Мебель (лицевая сторона смотрит в +z) ---------- */
function sofa(x, z, ry, len = 2.2) {
  const g = G(x, z, ry), M = D3.M; legs(g, len - 0.12, 0.82, 0.1, M.metal, 0.016);
  bx(g, len, 0.3, 0.95, M.fabric, 0, 0.1, 0, true, 0.04); bx(g, len - 0.02, 0.5, 0.24, M.fabric, 0, 0.36, -0.355, true, 0.08);
  [-1, 1].forEach(s => bx(g, 0.2, 0.32, 0.95, M.fabric, s * (len / 2 - 0.1), 0.36, 0, true, 0.08));
  const cw = (len - 0.42) / 2;
  [-1, 1].forEach(s => { bx(g, cw - 0.01, 0.16, 0.72, M.fabric, s * cw / 2, 0.38, 0.09, true, 0.07); const pl = bx(g, 0.46, 0.44, 0.15, M.fabric2, s * cw / 2, 0.5, -0.2, true, 0.07); pl.rotation.x = -0.22; pl.rotation.z = s * 0.06; });
  const t = bx(g, 0.55, 0.05, 0.7, M.blanket, cw / 2 + 0.05, 0.54, 0.12, true, 0.02); t.rotation.y = 0.18;
  const c = bx(g, 0.38, 0.36, 0.12, M.accent.clone ? D3.M.fabric2 : M.fabric2, -cw / 2 - 0.15, 0.5, -0.12, true, 0.06); c.rotation.set(-0.25, 0.3, 0.1);
}
function coffeeTable(x, z, ry) {
  const g = G(x, z, ry), M = D3.M; bx(g, 1.0, 0.035, 0.56, M.wood, 0, 0.38, 0, true, 0.012); bx(g, 0.92, 0.02, 0.48, M.wood, 0, 0.14, 0, true, 0.008); legs(g, 1.0, 0.56, 0.38, M.metal, 0.012);
  [0, 1, 2].forEach(i => bx(g, 0.24, 0.025, 0.17, M.book[i], -0.24, 0.415 + i * 0.026, 0.02, true, 0.003).rotation.y = i * 0.15);
  cy(g, 0.045, 0.06, 0.2, M.ceramic, 0.26, 0.415, -0.06); plantSmall(g, 0.26, 0.6, -0.06);
}
function plantSmall(g, x, y, z) { const M = D3.M; for (let i = 0; i < 9; i++) { const l = new THREE.Mesh(leafGeo(), i % 2 ? M.leaf : M.leaf2); l.scale.setScalar(0.5); l.position.set(x, y, z); l.rotation.set(rnd(-0.6, 0.6), i * 0.7, rnd(-0.8, 0.8)); g.add(l); } }
function tvUnit(x, z, ry) {
  const g = G(x, z, ry), M = D3.M; bx(g, 1.8, 0.42, 0.4, M.wood, 0, 0.12, 0, true, 0.01); legs(g, 1.8, 0.4, 0.12, M.metal, 0.012);
  [-0.45, 0.45].forEach(xx => bx(g, 0.86, 0.38, 0.005, M.lightPlain, xx, 0.14, 0.2, false));
  bx(g, 1.45, 0.83, 0.035, M.black, 0, 0.98, -0.17, true, 0.01); bx(g, 1.41, 0.79, 0.004, M.screen, 0, 1.0, -0.15, false);
  bx(g, 0.9, 0.07, 0.09, M.black, 0, 0.55, 0.02, true, 0.02); cy(g, 0.06, 0.05, 0.22, M.accent, 0.72, 0.54, 0); plantSmall(g, -0.72, 0.62, 0);
}
function rug(x, z, w, d, ry = 0) { const g = G(x, z, ry); bx(g, w, 0.01, d, D3.M.rug, 0, 0.002, 0, false, 0.004).receiveShadow = true; }
function floorLamp(x, z) { const g = G(x, z), M = D3.M; cy(g, 0.16, 0.17, 0.03, M.metal); cy(g, 0.012, 0.012, 1.45, M.metal, 0, 0.03); const sh = cy(g, 0.17, 0.24, 0.32, M.lampOn, 0, 1.4); sh.castShadow = false; D3.addLamp(x, z, 0.35, 4, 1.5); }
function plant(x, z, s = 1) {
  const g = G(x, z), M = D3.M; cy(g, 0.19 * s, 0.14 * s, 0.42 * s, M.pot); cy(g, 0.17 * s, 0.17 * s, 0.02, M.soil, 0, 0.39 * s);
  for (let k = 0; k < 3; k++) cy(g, 0.012, 0.016, 0.9 * s, M.stem, rnd(-.04, .04), 0.4 * s, rnd(-.04, .04), 8).rotation.set(rnd(-.15, .15), 0, rnd(-.15, .15));
  for (let i = 0; i < 70; i++) { const l = new THREE.Mesh(leafGeo(), i % 3 ? M.leaf : M.leaf2), a = Math.random() * 7, rr = rnd(0.05, 0.3) * s, hy = rnd(0.65, 1.45) * s;
    l.position.set(Math.cos(a) * rr, hy, Math.sin(a) * rr); l.rotation.set(rnd(-1.2, 0.4), a + Math.PI / 2, rnd(-0.7, 0.7)); l.scale.setScalar(rnd(0.9, 1.4) * s); l.castShadow = true; g.add(l); }
}
function bed(x, z, ry, bw) {
  const g = G(x, z, ry), M = D3.M; legs(g, bw + 0.1, 2.1, 0.08, M.wood, 0.025);
  bx(g, bw + 0.12, 0.26, 2.14, M.fabric, 0, 0.08, 0, true, 0.03); bx(g, bw, 0.24, 2.0, M.linen, 0, 0.34, 0.03, true, 0.06); bx(g, bw + 0.28, 1.15, 0.12, M.fabric, 0, 0.08, -1.07, true, 0.05);
  bx(g, bw + 0.06, 0.07, 1.42, M.blanket, 0, 0.56, 0.32, true, 0.035); bx(g, bw + 0.1, 0.07, 0.42, M.fabric2, 0, 0.6, 0.78, true, 0.03);
  [-1, 1].forEach(s => { const p = bx(g, bw / 2 - 0.1, 0.17, 0.42, M.linen, s * bw / 4, 0.58, -0.74, true, 0.08); p.rotation.x = -0.35; });
  const c = bx(g, 0.42, 0.3, 0.12, M.accent.isMaterial ? M.fabric2 : M.fabric2, 0, 0.6, -0.52, true, 0.06); c.rotation.x = -0.3;
}
function nightstand(x, z, ry) { const g = G(x, z, ry), M = D3.M; bx(g, 0.46, 0.42, 0.4, M.wood, 0, 0.12, 0, true, 0.015); legs(g, 0.46, 0.4, 0.12, M.metal, 0.01); bx(g, 0.42, 0.003, 0.002, M.metal, 0, 0.33, 0.2, false); cy(g, 0.06, 0.08, 0.22, M.ceramic, 0, 0.54, 0); const s = cy(g, 0.11, 0.15, 0.18, M.lampOn, 0, 0.76, 0); s.castShadow = false; D3.addLamp(x, z, 0.18, 2.2, 0.9); }
function wardrobe(x, z, ry, len, h = 2.35) {
  const g = G(x, z, ry), M = D3.M; bx(g, len, h, 0.6, M.light, 0, 0, 0, true, 0.008); const n = Math.max(1, Math.round(len / 0.55));
  for (let i = 1; i < n; i++) bx(g, 0.004, h - 0.04, 0.01, M.black, -len / 2 + i * len / n, 0.02, 0.3, false);
  for (let i = 0; i < n; i++) bx(g, 0.015, 0.32, 0.03, M.metal, -len / 2 + (i + (i % 2 ? 0.12 : 0.88)) * len / n, 0.95, 0.31, false, 0.006);
}
function kitchenRun(x, z, ry, L) {
  const g = G(x, z, ry), M = D3.M;
  bx(g, L, 0.1, 0.54, M.black, 0, 0, -0.03); bx(g, L, 0.76, 0.58, M.kitchen, 0, 0.1, 0, true, 0.006); bx(g, L + 0.02, 0.035, 0.63, M.counter, 0, 0.86, 0.01, true, 0.008);
  bx(g, L, 0.6, 0.012, M.counter, 0, 0.895, -0.3, false); bx(g, L, 0.72, 0.34, M.kitchen, 0, 1.5, -0.13, true, 0.006); bx(g, L - 0.06, 0.01, 0.025, M.lampOn, 0, 1.49, 0.0, false);
  const n = Math.max(1, Math.round(L / 0.6));
  for (let i = 1; i < n; i++) { const xx = -L / 2 + i * L / n; bx(g, 0.004, 0.74, 0.01, M.black, xx, 0.11, 0.291, false); bx(g, 0.004, 0.7, 0.01, M.black, xx, 1.51, 0.041, false); }
  for (let i = 0; i < n; i++) bx(g, 0.3, 0.012, 0.02, M.metal, -L / 2 + (i + 0.5) * L / n, 0.78, 0.3, false, 0.005);
  bx(g, 0.6, 0.008, 0.52, M.screen, -L * 0.22, 0.895, 0.02, false); [-0.14, 0.14].forEach(a => [-0.12, 0.12].forEach(b => cy(g, 0.075, 0.075, 0.002, M.black, -L * 0.22 + a, 0.904, 0.02 + b)));
  bx(g, 0.6, 0.36, 0.46, M.steel, -L * 0.22, 1.86, -0.08, true, 0.01); bx(g, 0.58, 0.5, 0.02, M.black, -L * 0.22, 0.2, 0.3, false, 0.005);
  bx(g, 0.5, 0.01, 0.42, M.steel, L * 0.22, 0.892, 0.03, false); const f = new THREE.Group(); f.position.set(L * 0.22, 0.9, -0.2); g.add(f); cy(f, 0.015, 0.018, 0.32, M.steel, 0, 0, 0, 12); const sp2 = cy(f, 0.012, 0.012, 0.18, M.steel, 0, 0.3, 0.08, 12); sp2.rotation.x = Math.PI / 2;
  cy(g, 0.08, 0.06, 0.12, M.accent, L * 0.4, 0.895, -0.05); bx(g, 0.36, 0.26, 0.03, M.wood, -L * 0.42, 0.895, -0.26, true, 0.01); [0, 1, 2].forEach(i => cy(g, 0.04, 0.04, 0.14, M.ceramic, L * 0.05 + i * 0.1, 0.895, -0.2));
}
function fridge(x, z, ry) { const g = G(x, z, ry), M = D3.M; bx(g, 0.62, 1.95, 0.65, M.steel, 0, 0, 0, true, 0.02); bx(g, 0.6, 0.006, 0.01, M.black, 0, 1.25, 0.326, false); bx(g, 0.02, 0.5, 0.03, M.black, 0.25, 1.45, 0.34, false, 0.008); bx(g, 0.02, 0.4, 0.03, M.black, 0.25, 0.75, 0.34, false, 0.008); }
function chair(parent, x, z, ry) { const g = G(x, z, ry, parent), M = D3.M; bx(g, 0.45, 0.07, 0.45, M.fabric2, 0, 0.42, 0, true, 0.03); legs(g, 0.44, 0.44, 0.43, M.metal, 0.012, 0.03); const b = bx(g, 0.45, 0.42, 0.05, M.fabric2, 0, 0.5, -0.2, true, 0.025); b.rotation.x = -0.08; }
function diningSet(x, z, ry, L = 1.4, seats = 4) {
  const g = G(x, z, ry), M = D3.M; bx(g, L, 0.04, 0.88, M.wood, 0, 0.72, 0, true, 0.015); legs(g, L, 0.88, 0.72, M.wood, 0.03, 0.09);
  const per = Math.max(1, seats / 2); for (let i = 0; i < per; i++) { const xx = -L / 2 + (i + 0.5) * L / per; chair(g, xx, 0.62, Math.PI); chair(g, xx, -0.62, 0); }
  cy(g, 0.1, 0.07, 0.2, M.ceramic, 0, 0.76, 0); plantSmall(g, 0, 0.95, 0); bx(g, 0.35, 0.005, 0.25, M.lightPlain, -L / 4, 0.761, 0.18, false);
}
function pendant(x, z, h0 = 0.75) { const g = G(x, z), M = D3.M; cy(g, 0.003, 0.003, h0 - 0.15, M.black, 0, H - h0 + 0.15); const s = cy(g, 0.04, 0.2, 0.18, M.metal, 0, H - h0); s.castShadow = false; const b = sp(g, 0.06, M.bulb, 0, H - h0 + 0.02, 0); b.castShadow = false; D3.addLamp(x, z, 0.35, 3, H - h0 - 0.05); }
function painting(x, y, z, ry, w = 0.9, h = 0.6) { const g = G(x, z, ry), M = D3.M; bx(g, w + 0.05, h + 0.05, 0.03, M.frame, 0, y, 0, false, 0.004); bx(g, w - 0.02, h - 0.02, 0.01, M.art, 0, y + 0.035, 0.012, false); }
function tub(x, z, ry, L = 1.7) { const g = G(x, z, ry), M = D3.M; bx(g, L, 0.56, 0.76, M.ceramic, 0, 0, 0, true, 0.05); bx(g, L - 0.14, 0.01, 0.6, M.water, 0, 0.5, 0, false, 0.004); cy(g, 0.015, 0.015, 0.25, M.steel, L / 2 - 0.15, 0.56, -0.3, 10); bx(g, 0.5, 0.3, 0.02, M.linen, L / 2 - 0.4, 0.3, 0.385, false, 0.01); }
function toilet(x, z, ry) { const g = G(x, z, ry), M = D3.M; const b = cy(g, 0.19, 0.15, 0.4, M.ceramic, 0, 0, 0.06); b.scale.z = 1.3; const s = cy(g, 0.2, 0.2, 0.03, M.ceramic, 0, 0.4, 0.06); s.scale.z = 1.3; bx(g, 0.38, 0.36, 0.17, M.ceramic, 0, 0.42, -0.2, true, 0.03); }
function vanity(x, z, ry, w = 0.8) { const g = G(x, z, ry), M = D3.M; bx(g, w, 0.48, 0.46, M.wood, 0, 0.35, 0, true, 0.01); bx(g, w + 0.02, 0.12, 0.48, M.ceramic, 0, 0.83, 0, true, 0.03); cy(g, 0.012, 0.012, 0.22, M.steel, 0, 0.95, -0.17, 10); bx(g, w - 0.1, 0.85, 0.02, M.mirror, 0, 1.15, -0.22, false, 0.01); bx(g, w - 0.1, 0.02, 0.04, M.lampOn, 0, 2.02, -0.2, false); }
function desk(x, z, ry) { const g = G(x, z, ry), M = D3.M; bx(g, 1.1, 0.03, 0.55, M.wood, 0, 0.72, 0, true, 0.01); legs(g, 1.1, 0.55, 0.72, M.metal, 0.014); bx(g, 0.34, 0.015, 0.24, M.black, -0.1, 0.75, 0, true, 0.004); const l = bx(g, 0.34, 0.22, 0.008, M.screen, -0.1, 0.76, -0.12, false); l.rotation.x = -0.25; [0, 1, 2, 3].forEach(i => bx(g, 0.04, 0.24, 0.17, M.book[i], 0.3 + i * 0.045, 0.75, -0.15, true, 0.003)); chair(g, 0, 0.55, Math.PI); }
function bench(x, z, ry) { const g = G(x, z, ry), M = D3.M; bx(g, 0.9, 0.35, 0.38, M.wood, 0, 0.08, 0, true, 0.01); legs(g, 0.9, 0.38, 0.08, M.metal, 0.012); bx(g, 0.86, 0.07, 0.36, M.fabric2, 0, 0.43, 0, true, 0.03); }
function shelf(x, z, ry, len) {
  const g = G(x, z, ry), M = D3.M; for (let i = 0; i < 5; i++) bx(g, len, 0.025, 0.45, M.light, 0, 0.1 + i * 0.5, 0); [-1, 1].forEach(s => bx(g, 0.025, 2.2, 0.45, M.light, s * len / 2, 0, 0));
  for (let r = 0; r < 4; r++) { let xx = -len / 2 + 0.06; while (xx < len / 2 - 0.1) { const bw = rnd(0.03, 0.06), bh = rnd(0.22, 0.32); bx(g, bw, bh, 0.2, M.book[(Math.random() * 6) | 0], xx + bw / 2, 0.125 + r * 0.5, 0.05, true, 0.003); xx += bw + 0.004; if (Math.random() < .08) xx += 0.12; } }
}
function panelOnWall(x0, x1, z0, z1, mat, y0 = 0, y1 = H) { const g = G(0, 0); bx(g, Math.max(0.012, x1 - x0), y1 - y0, Math.max(0.012, z1 - z0), mat, (x0 + x1) / 2, y0, (z0 + z1) / 2, false); }

/* ---------- Наполнение комнат ---------- */
function furnish(r) {
  const x0 = r.ix0, x1 = r.ix1, z0 = r.iz0, z1 = r.iz1, w = x1 - x0, d = z1 - z0, cx = (x0 + x1) / 2, cz = (z0 + z1) / 2, PI = Math.PI, M = D3.M;
  if (r.type === 'kl') {
    const L = Math.max(1.2, w - 1.75 - 0.65);
    fridge(x0 + 0.33, z1 - 0.34, PI); kitchenRun(x0 + 0.66 + L / 2, z1 - 0.3, PI, L);
    const dx = x0 + Math.min(w * 0.45, 1.6);
    diningSet(dx, z1 - 2.05, 0, Math.min(1.6, w - 1.6), w > 4.2 ? 6 : 4); pendant(dx - 0.35, z1 - 2.05); pendant(dx + 0.35, z1 - 2.05);
    const sz = z0 + 1.55; rug((x0 + 1 + x1 - 0.45) / 2, sz, Math.min(2.4, w - 1.3), 1.9);
    sofa(x0 + 0.5, sz, PI / 2, 2.2); tvUnit(x1 - 0.22, sz, -PI / 2); coffeeTable((x0 + 0.98 + x1 - 0.45) / 2, sz, PI / 2);
    floorLamp(x0 + 0.3, z0 + 0.25); plant(x1 - 0.38, z0 + 0.38, 1.15); painting(x0 + 0.02, 1.35, sz, PI / 2, 1.2, 0.75);
    D3.addLamp(cx, cz, 0.45, Math.max(w, d) * 1.6);
  } else if (r.type === 'bedroom' || r.type === 'room') {
    const bw = w >= 3 ? 1.6 : 1.4, bz = z0 + Math.min(d * 0.42, 2.0);
    panelOnWall(x0, x0 + 0.015, z0, z1, M.accentWall);
    bed(x0 + 1.12, bz, PI / 2, bw); nightstand(x0 + 0.25, bz - bw / 2 - 0.32, PI / 2); nightstand(x0 + 0.25, bz + bw / 2 + 0.32, PI / 2);
    rug(x0 + 1.45, bz, 2.1, bw + 1.1, 0); painting(x0 + 0.03, 1.4, bz, PI / 2, 1.1, 0.55);
    const Lw = Math.min(2.0, w - 1.15); if (Lw > 0.8) wardrobe(x0 + 0.02 + Lw / 2, z1 - 0.31, PI, Lw);
    if (r.type === 'room') tvUnit(x1 - 0.22, bz, -PI / 2); else if (w > 2.6) desk(x1 - 0.32, z0 + 0.85, -PI / 2);
    plant(x1 - 0.34, r.type === 'room' ? z0 + 0.42 : z0 + 1.8, 0.9);
    D3.addLamp(cx, cz, 0.35, Math.max(w, d) * 1.5);
  } else if (r.type === 'kitchen') {
    const L = Math.max(1.2, d - 1.25); kitchenRun(x0 + 0.3, z0 + 0.45 + L / 2, PI / 2, L); fridge(x0 + 0.33, z1 - 0.4, PI / 2);
    diningSet(x1 - 0.55, z0 + 1.2, PI / 2, 0.8, 2); pendant(x1 - 0.55, z0 + 1.2); D3.addLamp(cx, cz, 0.35, 4);
  } else if (r.type === 'corr') {
    rug(cx, cz, w - 1.2, 0.8); D3.addLamp(x0 + w * 0.3, cz, 0.25, 3); D3.addLamp(x0 + w * 0.7, cz, 0.25, 3);
  } else if (r.type === 'bath' || r.type === 'wc') {
    panelOnWall(x0, x0 + 0.012, z0, z1, M.tileWall(d, 2.5), 0, 2.5); panelOnWall(x1 - 0.012, x1, z0, z1, M.tileWall(d, 2.5), 0, 2.5); panelOnWall(x0, x1, z1 - 0.012, z1, M.tileWall(w, 2.5), 0, 2.5);
    if (r.type === 'bath') { tub(cx, z1 - 0.4, 0, Math.min(1.7, w - 0.04)); toilet(x0 + 0.36, z0 + 0.95, PI / 2); vanity(x1 - 0.26, z0 + 0.85, -PI / 2); }
    else { toilet(cx, z1 - 0.38, PI); vanity(x0 + 0.26, z0 + 1.0, PI / 2, 0.6); }
    D3.addLamp(cx, cz, 0.3, 3);
  } else if (r.type === 'closet') {
    shelf(x0 + 0.24, cz + 0.2, PI / 2, d - 0.9); shelf(x1 - 0.24, cz + 0.2, -PI / 2, d - 0.9); D3.addLamp(cx, cz, 0.25, 2.5);
  } else if (r.type === 'hall') {
    const L = Math.min(1.9, d - 0.3); wardrobe(x0 + 0.31, z0 + 0.15 + L / 2, PI / 2, L);
    bench(x1 - 0.22, cz, -PI / 2); bx(G(0, 0), 0.02, 1.5, 0.6, M.mirror, x1 - 0.012, 0.6, cz, false, 0.01);
    rug(cx, z1 - 0.45, 1.0, 0.6); D3.addLamp(cx, cz, 0.3, 3.5);
  }
}

/* ---------- Стены, окна, шторы ---------- */
function wbox(w, a0, a1, y0, y1, dep, off, mat, sh = true) {
  if (a1 - a0 < 0.004 || y1 - y0 < 0.004) return null;
  const len = a1 - a0, h = y1 - y0, mid = (a0 + a1) / 2;
  const m = new THREE.Mesh(w.axis === 'x' ? new THREE.BoxGeometry(len, h, dep) : new THREE.BoxGeometry(dep, h, len), mat);
  if (w.axis === 'x') m.position.set(mid, (y0 + y1) / 2, w.c + off); else m.position.set(w.c + off, (y0 + y1) / 2, mid);
  m.castShadow = sh; m.receiveShadow = true; D3.scene.add(m); return m;
}
function curtain(w, a0, a1, off) {
  const cw = a1 - a0, ch = H - 0.14, seg = 40, geo = new THREE.PlaneGeometry(cw, ch, seg, 1), pos = geo.attributes.position;
  for (let i = 0; i < pos.count; i++) { const u = pos.getX(i); pos.setZ(i, Math.sin((u + cw / 2) / cw * Math.PI * 7) * 0.035); }
  geo.computeVertexNormals();
  const m = new THREE.Mesh(geo, D3.M.curtain); m.castShadow = true; m.receiveShadow = true;
  if (w.axis === 'x') m.position.set((a0 + a1) / 2, 0.04 + ch / 2, w.c + off); else { m.rotation.y = Math.PI / 2; m.position.set(w.c + off, 0.04 + ch / 2, (a0 + a1) / 2); }
  D3.scene.add(m);
}
function buildWall(w) {
  const M = D3.M, mat = M.wall, ops = (w.ops || []).slice().sort((a, b) => a.at - b.at); let a = w.a0;
  const solid = (p, q) => { wbox(w, p, q, 0, H, w.t, 0, mat); wbox(w, p, q, 0, 0.08, w.t + 0.024, 0, M.trim, false); };
  ops.forEach(o => {
    const o0 = o.at - o.w / 2, o1 = o.at + o.w / 2;
    solid(a, o0); wbox(w, o0, o1, 0, o.b, w.t, 0, mat); wbox(w, o0, o1, o.top, H, w.t, 0, mat);
    if (o.kind === 'window') {
      const f = 0.06, inn = w.inn || 1;
      [[o0, o1, o.b, o.b + f], [o0, o1, o.top - f, o.top], [o0, o0 + f, o.b, o.top], [o1 - f, o1, o.b, o.top], [o.at - f / 2, o.at + f / 2, o.b, o.top]].forEach(([p, q, y0, y1]) => wbox(w, p, q, y0, y1, 0.09, 0, M.frame));
      wbox(w, o0 + f, o1 - f, o.b + f, o.top - f, 0.012, 0, M.glass, false);
      if (o.b > 0.3) wbox(w, o0 - 0.05, o1 + 0.05, o.b - 0.03, o.b, 0.3, inn * 0.12, M.sill);
      curtain(w, o0 - 0.55, o0 - 0.02, inn * 0.24); curtain(w, o1 + 0.02, o1 + 0.55, inn * 0.24);
      wbox(w, o0 - 0.6, o1 + 0.6, H - 0.1, H - 0.08, 0.025, inn * 0.24, M.metal, false);
      if (HAS().ra) {
        const rl = new THREE.RectAreaLight(0xe8f1ff, 1, o.w - 0.1, o.top - o.b - 0.1), yy = (o.b + o.top) / 2;
        if (w.axis === 'x') { rl.position.set(o.at, yy, w.c + inn * 0.06); rl.lookAt(o.at, yy, w.c + inn * 5); } else { rl.position.set(w.c + inn * 0.06, yy, o.at); rl.lookAt(w.c + inn * 5, yy, o.at); }
        rl.userData.area = (o.w - 0.1) * (o.top - o.b - 0.1); D3.scene.add(rl); D3.winLights.push(rl);
      }
    } else if (o.kind === 'door') {
      const tr = 0.06, dep = w.t + 0.03;
      wbox(w, o0 - tr, o0, 0, o.top + tr, dep, 0, M.trim); wbox(w, o1, o1 + tr, 0, o.top + tr, dep, 0, M.trim); wbox(w, o0 - tr, o1 + tr, o.top, o.top + tr, dep, 0, M.trim);
      if (o.leaf) {
        const lw = o.w - 0.03, hx = o.hinge > 0 ? o1 - 0.03 : o0 + 0.03, lh = o.top - 0.01, pc = w.c + o.swing * (w.t / 2 + lw / 2);
        const g = new THREE.Mesh(boxGeo(w.axis === 'x' ? 0.04 : lw, lh, w.axis === 'x' ? lw : 0.04, 0.006), M.trim);
        if (w.axis === 'x') g.position.set(hx, lh / 2, pc); else g.position.set(pc, lh / 2, hx);
        g.castShadow = true; g.receiveShadow = true; D3.scene.add(g);
      }
    } else if (o.kind === 'entry') { wbox(w, o0, o1, 0, o.top, 0.07, 0, M.entry); wbox(w, o1 - 0.12, o1 - 0.1, 1.0, 1.04, 0.16, 0, M.steel, false); }
    a = o1;
  });
  solid(a, w.a1);
}

/* ---------- Сцена ---------- */
function d3Build(cfg) {
  const S = STYLES[cfg.style] || STYLES.modern, scene = new THREE.Scene(); D3.scene = scene; D3.S = S; D3.lamps = []; D3.winLights = [];
  scene.environment = D3.env;
  const plan = planApt(cfg.rooms, cfg.area); D3.plan = plan; const { W, D } = plan;
  const M = mkMats(S); D3.M = M;
  D3.hemi = new THREE.HemisphereLight(0xeaf2ff, 0x8a7a66, 0.12); scene.add(D3.hemi);
  const sun = new THREE.DirectionalLight(0xfff0d8, 1.8); sun.position.set(W * 0.3 - 7, 11, -9); sun.target.position.set(W / 2, 0, D / 2);
  sun.castShadow = true; sun.shadow.mapSize.set(2048, 2048); Object.assign(sun.shadow.camera, { left: -11, right: 11, top: 11, bottom: -11, near: 1, far: 45 });
  sun.shadow.bias = -0.0004; sun.shadow.normalBias = 0.025; sun.shadow.radius = 3; scene.add(sun, sun.target); D3.sun = sun;
  D3.addLamp = (x, z, i, dist, y = H - 0.3) => { const l = new THREE.PointLight(0xffd2a0, i, dist, 2); l.position.set(x, y >= H - 0.31 ? H - 0.65 : y, z); l.userData.i = i; scene.add(l); D3.lamps.push(l); if (y >= H - 0.31) { const g = G(x, z, 0, D3.ceilG); const f = cy(g, 0.17, 0.19, 0.035, M.lampDisc, 0, H - 0.045, 0); f.castShadow = false; } };
  const sky = new THREE.Mesh(new THREE.CylinderGeometry(160, 160, 220, 64, 1, true), new THREE.MeshBasicMaterial({ map: skylineTex(false), side: THREE.BackSide, fog: false, toneMapped: false }));
  sky.position.set(W / 2, 1.6, D / 2); sky.userData.noMerge = true; scene.add(sky); D3.sky = sky;
  const ground = new THREE.Mesh(new THREE.PlaneGeometry(400, 400), new THREE.MeshBasicMaterial({ color: 0x4d5a4a, fog: false })); ground.rotation.x = -Math.PI / 2; ground.position.y = -107; scene.add(ground);
  const slab = new THREE.Mesh(new THREE.BoxGeometry(W + TE * 2 + 0.6, 0.22, D + TE * 2 + 0.6), M.slab); slab.position.set(W / 2, -0.112, D / 2); slab.receiveShadow = true; scene.add(slab);
  D3.ceilG = new THREE.Group(); scene.add(D3.ceilG);
  const ceil = new THREE.Mesh(new THREE.BoxGeometry(W + TE * 2, 0.15, D + TE * 2), M.ceil); ceil.position.set(W / 2, H + 0.075, D / 2); ceil.castShadow = true; ceil.receiveShadow = true; D3.ceilG.add(ceil);
  plan.rooms.forEach(r => {
    const wet = r.type === 'bath' || r.type === 'wc';
    const m = new THREE.Mesh(new THREE.PlaneGeometry(r.w, r.d), wet ? M.tileFor(r.w, r.d) : M.floorFor(r.w, r.d));
    m.rotation.x = -Math.PI / 2; m.position.set(r.x + r.w / 2, 0.001, r.z + r.d / 2); m.receiveShadow = true; scene.add(m);
  });
  plan.walls.forEach(buildWall);
  plan.rooms.forEach(furnish);
  mergeStatic(D3.ceilG, null); mergeStatic(scene, D3.ceilG);
  D3.ceilG.visible = D3.walk;
  applyLighting();
  if (D3.renderer) D3.renderer.shadowMap.needsUpdate = true;
}
function applyLighting() {
  const n = D3.night;
  D3.scene.background = new THREE.Color(n ? 0x0b1230 : 0xbcd3ea);
  D3.sky.material.map = skylineTex(n); D3.sky.material.needsUpdate = true;
  D3.scene.fog = null;
  D3.sun.intensity = n ? 0 : 1.8; D3.hemi.intensity = n ? 0.02 : 0.1;
  D3.lamps.forEach(l => { l.intensity = l.userData.i * 1.7; l.visible = n; });
  D3.winLights.forEach(l => { l.intensity = 1.7; l.visible = !n; });
  D3.renderer.toneMappingExposure = n ? 1.0 : 0.82;
  D3.scene.traverse(o => { if (o.material && o.material.isMeshStandardMaterial) { const m = o.material; if (m.userData.env == null) m.userData.env = m.envMapIntensity; m.envMapIntensity = m.userData.env * (n ? 0.1 : 0.7); } });
  if (D3.renderer) D3.renderer.shadowMap.needsUpdate = true;
  if (D3.bloom) { D3.bloom.strength = n ? 0.32 : 0.08; D3.bloom.threshold = n ? 0.88 : 0.97; D3.bloom.radius = 0.35; }
  D3.dirty = 90;
}

/* ---------- Пост-обработка (мягкие тени, свечение, сглаживание) ---------- */
function setupComposer() {
  D3.composer = null; D3.bloom = null; if (!D3.hq || !HAS().pp) return;
  try {
    const r = D3.renderer, w = Math.max(1, D3.el.clientWidth), h = Math.max(1, D3.el.clientHeight), c = new THREE.EffectComposer(r); if (c.setPixelRatio) c.setPixelRatio(1);
    const ssao = new THREE.SSAOPass(D3.scene, D3.camera, w, h); ssao.kernelRadius = 0.7; ssao.minDistance = 0.0005; ssao.maxDistance = 0.06; c.addPass(ssao);
    const bloom = new THREE.UnrealBloomPass(new THREE.Vector2(w, h), 0.12, 0.45, 0.95); c.addPass(bloom);
    c.addPass(new THREE.ShaderPass(THREE.GammaCorrectionShader));
    const fx = new THREE.ShaderPass(THREE.FXAAShader); const pr = 1; fx.uniforms.resolution.value.set(1 / (w * pr), 1 / (h * pr)); c.addPass(fx);
    D3.composer = c; D3.ssao = ssao; D3.bloom = bloom; D3.fxaa = fx;
    applyLighting();
  } catch (e) { console.warn('postprocessing off', e); D3.composer = null; }
}
function d3Resize() {
  const el = D3.el, w = el.clientWidth, h = el.clientHeight; if (!w || !h || !D3.renderer) return;
  D3.renderer.setSize(w, h, false); D3.camera.aspect = w / h; D3.camera.updateProjectionMatrix();
  if (D3.composer) { D3.composer.setSize(w, h); D3.ssao.setSize(w, h); const pr = 1; D3.fxaa.uniforms.resolution.value.set(1 / (w * pr), 1 / (h * pr)); }
  D3.dirty = 30;
}
function d3Render(final = true) { if (D3.composer && final) D3.composer.render(); else D3.renderer.render(D3.scene, D3.camera); }
const _pm = new Float32Array(17);
function camMoved(cam) { cam.updateMatrixWorld(); const e = cam.matrixWorld.elements; let ch = Math.abs(_pm[16] - cam.fov) > 1e-4; for (let i = 0; i < 16; i++) if (Math.abs(_pm[i] - e[i]) > 1e-5) { ch = true; break; } if (ch) { _pm.set(e); _pm[16] = cam.fov; } return ch; }

/* ---------- Инициализация и управление ---------- */
function d3Init(el, cfg) {
  d3Dispose();
  if (!window.THREE || !THREE.OrbitControls) { el.insertAdjacentHTML('afterbegin', '<div class="note" style="margin:60px 16px">3D-модуль не загрузился: нужен интернет при первом открытии (библиотека Three.js). Проверьте подключение и обновите страницу.</div>'); return; }
  const mobile = Math.min(window.innerWidth, window.innerHeight) < 600;
  try { const s = localStorage.getItem('oftob_3d_hq'); D3.hq = s == null ? !mobile : s === '1'; } catch (e) { D3.hq = !mobile; }
  const r = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true, powerPreference: 'high-performance' });
  r.setPixelRatio(Math.min(D3.hq ? 1.5 : 1.25, window.devicePixelRatio || 1)); r.shadowMap.enabled = true; r.shadowMap.type = THREE.PCFSoftShadowMap; r.shadowMap.autoUpdate = false;
  r.outputEncoding = THREE.sRGBEncoding; r.toneMapping = THREE.ACESFilmicToneMapping; r.toneMappingExposure = 0.95; r.physicallyCorrectLights = false;
  if (HAS().ra) THREE.RectAreaLightUniformsLib.init();
  el.prepend(r.domElement); D3.renderer = r; D3.el = el; D3.cfg = cfg; D3.walk = false; D3.night = false;
  D3.camera = new THREE.PerspectiveCamera(50, 1, 0.05, 900);
  const c = D3.controls = new THREE.OrbitControls(D3.camera, r.domElement);
  c.enableDamping = true; c.dampingFactor = 0.08; c.maxPolarAngle = Math.PI * 0.495; c.minDistance = 1; c.maxDistance = 40;
  c.addEventListener('change', () => D3.dirty = Math.max(D3.dirty, 20));
  D3.env = makeEnv(r);
  d3Build(cfg); d3View('persp'); setupComposer(); d3UpdateQBtn();
  D3.ro = new ResizeObserver(d3Resize); D3.ro.observe(el); d3Resize();
  setTimeout(d3AutoQuality, 400);
  const cv = r.domElement; let drag = null;
  cv.addEventListener('pointerdown', e => { d3PanoPause(); if (D3.walk) { drag = { x: e.clientX, y: e.clientY }; cv.setPointerCapture(e.pointerId); } });
  cv.addEventListener('pointermove', e => { if (!drag || !D3.walk) return; D3.yaw += (e.clientX - drag.x) * 0.004; D3.pitch = Math.max(-1.2, Math.min(1.2, D3.pitch + (e.clientY - drag.y) * 0.004)); drag = { x: e.clientX, y: e.clientY }; D3.dirty = 20; });
  cv.addEventListener('pointerup', () => drag = null);
  cv.addEventListener('wheel', e => { if (D3.walk) { e.preventDefault(); D3.camera.fov = Math.max(35, Math.min(85, D3.camera.fov + e.deltaY * 0.03)); D3.camera.updateProjectionMatrix(); D3.dirty = 20; } }, { passive: false });
  D3.kd = e => { if (/INPUT|SELECT|TEXTAREA/.test(e.target.tagName)) return; D3.keys[e.code] = true; if (D3.walk && /Arrow|Key[WASD]/.test(e.code)) e.preventDefault(); };
  D3.ku = e => { D3.keys[e.code] = false; };
  document.addEventListener('keydown', D3.kd); document.addEventListener('keyup', D3.ku);
  $$('[data-hold]', el).forEach(b => { b.addEventListener('pointerdown', () => D3.mv = +b.dataset.hold); ['pointerup', 'pointerleave', 'pointercancel'].forEach(ev => b.addEventListener(ev, () => D3.mv = 0)); });
  D3.lt = 0; const loop = t => {
    D3.raf = requestAnimationFrame(loop); const dt = Math.min(0.05, (t - (D3.lt || t)) / 1000); D3.lt = t; const cam = D3.camera;
    if (D3.walk) {
      const k = D3.keys; let f = 0, s = 0;
      if (k.KeyW || k.ArrowUp || D3.mv === 1) f += 1; if (k.KeyS || k.ArrowDown || D3.mv === -1) f -= 1; if (k.KeyA) s -= 1; if (k.KeyD) s += 1;
      if (k.ArrowLeft) D3.yaw += 1.8 * dt; if (k.ArrowRight) D3.yaw -= 1.8 * dt;
      if (f || s || k.ArrowLeft || k.ArrowRight) D3.dirty = Math.max(D3.dirty, 5);
      if (D3.panoOn) { f = s = 0; if (D3.panoAuto) { D3.yaw += 0.12 * dt; D3.dirty = Math.max(D3.dirty, 2); } }
      const sp = 2.0 * dt, sy = Math.sin(D3.yaw), cyw = Math.cos(D3.yaw);
      cam.position.x = Math.max(0.25, Math.min(D3.plan.W - 0.25, cam.position.x + (-sy * f + cyw * s) * sp));
      cam.position.z = Math.max(0.25, Math.min(D3.plan.D - 0.25, cam.position.z + (-cyw * f - sy * s) * sp));
      cam.rotation.set(D3.pitch, D3.yaw, 0, 'YXZ');
    } else D3.controls.update();
    if (D3.walk) cam.updateMatrixWorld();
    const moved = camMoved(cam);
    if (moved) { d3Render(!D3.composer); D3.hqPending = !!D3.composer; D3.stillT = t; if (D3.dirty > 0) D3.dirty--; }
    else if (D3.hqPending && t - D3.stillT > 160) { D3.hqPending = false; d3Render(true); D3.dirty = 0; }
    else if (D3.dirty > 0) { D3.dirty--; if (D3.dirty === 0 || !D3.composer) d3Render(true); else d3Render(false); }
  };
  D3.raf = requestAnimationFrame(loop);
}
function disposeScene(sc) { if (!sc) return; sc.traverse(o => { if (o.geometry && !Object.values(GEO).includes(o.geometry) && o.geometry !== LEAF_GEO) o.geometry.dispose(); if (o.material) [].concat(o.material).forEach(m => { ['map', 'bumpMap'].forEach(k => m[k] && m[k].dispose()); m.dispose(); }); }); }
function d3Dispose() {
  if (D3.raf) cancelAnimationFrame(D3.raf); D3.raf = 0;
  if (D3.ro) D3.ro.disconnect(); D3.ro = null;
  if (D3.kd) { document.removeEventListener('keydown', D3.kd); document.removeEventListener('keyup', D3.ku); D3.kd = null; }
  d3PanoGyro(false); D3.panoOn = false;
  if (D3.renderer) { disposeScene(D3.scene); if (D3.env) D3.env.dispose(); D3.controls.dispose(); if (D3.composer) { D3.composer.renderTarget1.dispose(); D3.composer.renderTarget2.dispose(); } D3.renderer.dispose(); try { D3.renderer.forceContextLoss(); } catch (e) { } D3.renderer.domElement.remove(); }
  D3.renderer = null; D3.scene = null; D3.composer = null; D3.keys = {}; D3.mv = 0;
}
function d3SetStyle(style) {
  if (!D3.renderer) return; const cam = D3.camera, pos = cam.position.clone(), tgt = D3.controls.target.clone(), rot = { y: D3.yaw, p: D3.pitch };
  disposeScene(D3.scene); D3.cfg.style = style; d3Build(D3.cfg); cam.position.copy(pos); D3.controls.target.copy(tgt); D3.yaw = rot.y; D3.pitch = rot.p;
  D3.ceilG.visible = D3.walk; setupComposer();
}
function d3View(v) {
  if (!D3.renderer) return; if (D3.walk) d3SetWalk(false);
  const { W, D } = D3.plan, cam = D3.camera, c = D3.controls;
  c.target.set(W / 2, 0.6, D / 2);
  if (v === 'top') cam.position.set(W / 2, Math.max(W, D) * 1.55 + 2, D / 2 + 0.01);
  else cam.position.set(W / 2 + W * 0.3, Math.max(W, D) * 1.25 + 3, D + Math.max(W, D) * 0.5);
  cam.fov = 50; cam.updateProjectionMatrix(); c.update(); D3.dirty = 30;
}
function d3SetWalk(on) {
  if (!D3.renderer) return; if (!on && D3.panoOn) d3PanoExit(true); D3.walk = on; D3.controls.enabled = !on; D3.ceilG.visible = on;
  D3.el.classList.toggle('walk', on); const b = $('#bWalk'); if (b) b.classList.toggle('on', on);
  const h = $('#vHelp'); if (h) h.textContent = T(on ? 'W/S или ▲▼ — идти · A/D — в сторону · ←→ или мышь — поворот · колесо — угол обзора' : 'Мышь: вращать · колесо: приблизить · правая кнопка: сдвиг');
  if (on) { const hall = D3.plan.rooms.find(r => r.type === 'hall'); D3.camera.position.set(hall.x + hall.w / 2, 1.6, hall.z + hall.d / 2 + 0.4); D3.yaw = 0; D3.pitch = -0.05; D3.camera.fov = 70; D3.camera.updateProjectionMatrix(); }
  else d3View('persp');
  D3.dirty = 30;
}
function d3SetNight(on) { if (!D3.renderer) return; D3.night = on; applyLighting(); const b = $('#bNight'); if (b) { b.classList.toggle('on', on); b.textContent = T(on ? '☀️ День' : '🌙 Вечер'); } }
function d3UpdateQBtn() { const b = $('#bHQ'); if (b) { b.classList.toggle('on', D3.hq && !!D3.composer); b.textContent = T(D3.hq ? '✨ Фото-качество' : '⚡ Быстрый режим'); } }
function d3ToggleHQ() {
  if (!D3.renderer) return; D3.hq = !D3.hq; try { localStorage.setItem('oftob_3d_hq', D3.hq ? '1' : '0'); } catch (e) { }
  D3.renderer.setPixelRatio(Math.min(D3.hq ? 1.5 : 1.25, window.devicePixelRatio || 1)); setupComposer(); d3Resize(); d3UpdateQBtn(); D3.dirty = 30;
}
function d3GoRoom(i) {
  if (!D3.renderer) return; const r = D3.plan.rooms[i], cx = r.x + r.w / 2, cz = r.z + r.d / 2;
  if (D3.walk) {
    let px, pz, tx, tz;
    if (r.z === 0) { px = r.x + r.w - (r.type === 'kl' ? 0.85 : 0.6); pz = r.iz1 - 0.3; tx = r.ix0 + (r.ix1 - r.ix0) * 0.35; tz = r.iz0 + 0.3; }
    else if (r.type === 'corr') { px = r.ix0 + 0.4; pz = cz; tx = r.ix1; tz = cz; }
    else { px = cx; pz = r.iz0 + 0.3; tx = cx; tz = r.iz1; }
    D3.camera.position.set(px, 1.6, pz); D3.yaw = Math.atan2(-(tx - px), -(tz - pz)); D3.pitch = -0.12;
  } else { D3.controls.target.set(cx, 0.5, cz); D3.camera.position.set(cx + 1.2, 4.6, cz + 3.6); }
  D3.dirty = 30;
}
function d3Shot() {
  if (!D3.renderer) return; d3Render();
  D3.renderer.domElement.toBlob(b => download(`kvartira_${D3.cfg.style}_${today()}.png`, b), 'image/png');
}

/* ---------- Тур 360° по комнатам ---------- */
function d3PanoPause() { if (!D3.panoOn) return; D3.panoAuto = false; clearTimeout(D3.panoT); D3.panoT = setTimeout(() => { D3.panoAuto = true; d3PanoBar(); }, 7000); d3PanoBar(); }
function d3Pano(i) {
  if (!D3.renderer) return; const rooms = D3.plan.rooms;
  if (i == null) i = Math.max(0, rooms.findIndex(r => r.type === 'kl' || r.type === 'room'));
  const r = rooms[i]; D3.panoRoom = i;
  if (!D3.walk) d3SetWalk(true);
  D3.panoOn = true; D3.panoAuto = true; D3.el.classList.add('pano');
  const cx = (r.ix0 + r.ix1) / 2, cz = (r.iz0 + r.iz1) / 2, back = r.z >= D3.plan.Df;
  D3.camera.position.set(cx, 1.5, r.z === 0 ? r.iz0 + (r.iz1 - r.iz0) * 0.62 : cz);
  D3.yaw = back ? Math.PI : 0; D3.pitch = -0.06; D3.camera.fov = 78; D3.camera.updateProjectionMatrix();
  const h = $('#vHelp'); if (h) h.textContent = T('360°: тяните мышью или пальцем, чтобы осмотреться · колесо — приблизить · выберите комнату внизу');
  d3PanoBar(); D3.dirty = 40;
}
function d3PanoExit(fromWalk) { D3.panoOn = false; d3PanoGyro(false); if (D3.el) D3.el.classList.remove('pano'); const b = $('#panoBar'); if (b) b.remove(); if (!fromWalk) d3SetWalk(false); }
function d3PanoBar() {
  if (!D3.el) return; let b = $('#panoBar'); if (!D3.panoOn) { if (b) b.remove(); return; }
  if (!b) { b = document.createElement('div'); b.id = 'panoBar'; b.className = 'pano-bar'; D3.el.appendChild(b); }
  const rooms = D3.plan.rooms;
  b.innerHTML = `<div class="pano-rooms">${rooms.map((r, i) => `<button class="${i === D3.panoRoom ? 'on' : ''}" data-act="d3PanoRoom" data-i="${i}">${T(r.name)}</button>`).join('')}</div>
    <div class="pano-ctl"><button data-act="d3PanoAuto" class="${D3.panoAuto ? 'on' : ''}">${T('🔄 Авто-поворот')}</button><button data-act="d3PanoGyro" class="${D3.gyroOn ? 'on' : ''}">${T('📱 Гироскоп')}</button><button data-act="d3Full">${T('⛶ Во весь экран')}</button><button data-act="d3PanoExit">✕ ${T('Выйти из 360°')}</button></div>`;
}
function d3PanoGyro(on) {
  D3.gyroOn = on;
  if (D3.gyroH) { window.removeEventListener('deviceorientation', D3.gyroH); D3.gyroH = null; }
  if (!on) return;
  D3.gyroH = e => { if (e.alpha == null) return; D3.panoAuto = false; D3.yaw = THREE.MathUtils.degToRad(e.alpha) + (D3.gyroBase || 0); D3.pitch = Math.max(-1.2, Math.min(1.2, THREE.MathUtils.degToRad((e.beta || 90) - 90))); D3.dirty = 3; };
  const go = () => { D3.gyroBase = D3.yaw; window.addEventListener('deviceorientation', D3.gyroH); };
  if (window.DeviceOrientationEvent && typeof DeviceOrientationEvent.requestPermission === 'function') DeviceOrientationEvent.requestPermission().then(s => s === 'granted' ? go() : toast('Нет доступа к гироскопу')).catch(() => toast('Нет доступа к гироскопу'));
  else if (window.DeviceOrientationEvent) go(); else toast('Гироскоп недоступен на этом устройстве');
}

/* ---------- Оптимизация: склейка статичных объектов ---------- */
function mergeStatic(root, skip) {
  const U = THREE.BufferGeometryUtils; if (!U || !U.mergeBufferGeometries) return;
  root.updateMatrixWorld(true);
  const groups = new Map(), victims = [];
  const inside = o => { for (let p = o.parent; p; p = p.parent) if (p === skip) return true; return false; };
  root.traverse(o => {
    if (!o.isMesh || o.userData.noMerge || Array.isArray(o.material) || (skip && inside(o))) return;
    let g = o.geometry.index ? o.geometry.toNonIndexed() : o.geometry.clone();
    ['position', 'normal', 'uv'].forEach(a => { if (!g.attributes[a] && a === 'uv') g.setAttribute('uv', new THREE.Float32BufferAttribute(new Float32Array(g.attributes.position.count * 2), 2)); });
    Object.keys(g.attributes).forEach(a => { if (!['position', 'normal', 'uv'].includes(a)) g.deleteAttribute(a); });
    g.clearGroups(); g.morphAttributes = {};
    g.applyMatrix4(o.matrixWorld);
    const k = o.material.uuid + '|' + (o.castShadow ? 1 : 0) + (o.receiveShadow ? 1 : 0);
    if (!groups.has(k)) groups.set(k, { m: o.material, cast: o.castShadow, recv: o.receiveShadow, list: [] });
    groups.get(k).list.push(g); victims.push([o, g]);
  });
  const ok = new Set();
  groups.forEach(gr => {
    let merged = null; try { merged = gr.list.length === 1 ? gr.list[0] : U.mergeBufferGeometries(gr.list, false); } catch (e) { merged = null; }
    if (!merged) return;
    const mesh = new THREE.Mesh(merged, gr.m); mesh.castShadow = gr.cast; mesh.receiveShadow = gr.recv; mesh.userData.noMerge = true; mesh.matrixAutoUpdate = false;
    root.add(mesh); gr.list.forEach(x => ok.add(x));
  });
  // удаляем исходные объекты, которые попали в склейку
  victims.forEach(([o, g]) => { if (ok.has(g) && o.parent) o.parent.remove(o); });
  // пустые группы мебели больше не нужны
  const empty = []; root.traverse(o => { if (o.isGroup && o !== root && o !== skip && !o.children.length) empty.push(o); }); empty.forEach(o => o.parent && o.parent.remove(o));
}
/* ---------- Автоматический подбор качества под видеокарту ---------- */
function d3AutoQuality() {
  if (!D3.renderer) return; let saved = null; try { saved = localStorage.getItem('oftob_3d_hq'); } catch (e) { }
  const r = D3.renderer, gl = r.getContext(), cam = D3.camera;
  cam.updateMatrixWorld(); r.compile(D3.scene, cam); for (let i = 0; i < 3; i++) r.render(D3.scene, cam); gl.finish();
  const t0 = performance.now(); for (let i = 0; i < 6; i++) r.render(D3.scene, cam); gl.finish();
  const ms = (performance.now() - t0) / 6; D3.frameMs = ms;
  if (ms > 30) { r.setPixelRatio(1); d3Resize(); }
  if (saved == null && ms > 18 && D3.hq) { D3.hq = false; setupComposer(); d3Resize(); d3UpdateQBtn(); toast('3D: для этого компьютера включён быстрый режим. Фото-качество — кнопкой ✨'); }
  D3.dirty = 5;
}
