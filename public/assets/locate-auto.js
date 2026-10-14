/* Автопривязка корпуса к 3D-модели дома (вызывают api.autoFit и api.snap из assets/locate3d.js).
   В модели нет сведений о квартирах — рамку корпуса находим сами:
   1) пятно дома сверху: точки на поверхностях стен (по площади, а не по вершинам — у простой коробки их всего 8),
      самый маленький прямоугольник вокруг них даёт поворот, длину и глубину;
   2) фасады без перспективы (спереди, сзади, сбоку): по силуэту — земля и верх стен; скаты крыши и будки на крыше не в счёт;
   3) ряды окон: в строке через окна много вертикальных краёв, между этажами — мало; период повторения — высота этажа;
   4) ИИ смотрит на оба фасада: сколько этажей, где двери подъездов, какая сторона лицевая (Services\Locate::askAi).
   Итог — рамка корпуса и отчёт: что совпало с данными объекта, а что человеку стоит проверить. */

const median = (arr) => { const a = Array.from(arr).sort((x, y) => x - y); return a.length ? a[a.length >> 1] : 0; };
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const wrap = (r) => { while (r > Math.PI) r -= 2 * Math.PI; while (r <= -Math.PI) r += 2 * Math.PI; return r; };

export function meshesOf(model) {
  const out = [];
  model.updateMatrixWorld(true);
  model.traverse((o) => { if (o.isMesh && o.visible !== false && o.geometry?.attributes?.position) out.push(o); });
  return out;
}

/** Точки на поверхностях (по площади): x, y, z и 1 — если поверхность отвесная (стена, окно), 0 — скат крыши, плита, земля. keep(x, z) — нужная область сверху */
function surface(THREE, meshes, keep, N = 36000) {
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3(), e1 = new THREE.Vector3(), e2 = new THREE.Vector3();
  let T = 0;
  for (const m of meshes) T += ((m.geometry.index ? m.geometry.index.count : m.geometry.attributes.position.count) / 3) | 0;
  const stride = Math.max(1, Math.ceil(T / 250000));
  const each = (fn) => {
    for (const m of meshes) {
      const g = m.geometry, pos = g.attributes.position, idx = g.index, n = ((idx ? idx.count : pos.count) / 3) | 0, mw = m.matrixWorld;
      for (let t = 0; t < n; t++) {
        // в большой модели берём не каждый stride-й треугольник (у квадрата стены тогда всегда одна и та же половина — точки «съезжают» вниз), а вразброс
        if (stride > 1 && ((Math.imul(t + 1, 2654435761) >>> 0) / 4294967296) * stride >= 1) continue;   // фибоначчиево хеширование: старшие биты
        const i = 3 * t;
        a.fromBufferAttribute(pos, idx ? idx.getX(i) : i).applyMatrix4(mw);
        b.fromBufferAttribute(pos, idx ? idx.getX(i + 1) : i + 1).applyMatrix4(mw);
        c.fromBufferAttribute(pos, idx ? idx.getX(i + 2) : i + 2).applyMatrix4(mw);
        if (!keep((a.x + b.x + c.x) / 3, (a.z + b.z + c.z) / 3)) continue;
        e1.subVectors(b, a); e2.subVectors(c, a); e1.cross(e2);
        const len = e1.length();
        if (!(len > 0)) continue;
        const ny = Math.abs(e1.y / len);
        // большие горизонтальные плоскости (земля, плиты) — с меньшим весом, иначе все точки уйдут на подложку
        fn(len * 0.5 * (ny > 0.9 ? 0.25 : 1), ny < 0.2 ? 1 : 0);
      }
    }
  };
  let A = 0;
  each((ar) => { A += ar; });
  const out = [];
  if (!(A > 0)) return out;
  const step = A / N;
  let next = step * Math.random(), cum = 0;
  each((ar, vert) => {
    cum += ar;
    while (next < cum && out.length < N * 4 + 40) {
      let r1 = Math.random(), r2 = Math.random();
      if (r1 + r2 > 1) { r1 = 1 - r1; r2 = 1 - r2; }
      const x = a.x + (b.x - a.x) * r1 + (c.x - a.x) * r2, z = a.z + (b.z - a.z) * r1 + (c.z - a.z) * r2;
      if (keep(x, z)) out.push(x, a.y + (b.y - a.y) * r1 + (c.y - a.y) * r2, z, vert);
      next += step;
    }
  });
  return out;
}

/** Самое крупное связное пятно точек сверху: дом, а не деревья и машины рядом */
function mainBlob(xs, zs) {
  let x0 = Infinity, x1 = -Infinity, z0 = Infinity, z1 = -Infinity;
  for (let i = 0; i < xs.length; i++) { x0 = Math.min(x0, xs[i]); x1 = Math.max(x1, xs[i]); z0 = Math.min(z0, zs[i]); z1 = Math.max(z1, zs[i]); }
  const cs = Math.max(x1 - x0, z1 - z0, 1e-6) / 120, gw = Math.ceil((x1 - x0) / cs) + 3, gh = Math.ceil((z1 - z0) / cs) + 3;
  const occ = new Uint8Array(gw * gh), cellOf = new Int32Array(xs.length);
  for (let i = 0; i < xs.length; i++) {
    const gx = 1 + Math.floor((xs[i] - x0) / cs), gz = 1 + Math.floor((zs[i] - z0) / cs);
    cellOf[i] = gz * gw + gx;
    for (let dz = -1; dz <= 1; dz++) for (let dx = -1; dx <= 1; dx++) occ[(gz + dz) * gw + gx + dx] = 1;   // щели в стенах не рвут дом на части
  }
  const lab = new Int32Array(gw * gh).fill(-1), comps = [];
  for (let s = 0; s < occ.length; s++) {
    if (!occ[s] || lab[s] >= 0) continue;
    const id = comps.length, st = [s], box = [Infinity, -Infinity, Infinity, -Infinity];
    lab[s] = id;
    while (st.length) {
      const k = st.pop(), kx = k % gw, kz = (k / gw) | 0;
      box[0] = Math.min(box[0], kx); box[1] = Math.max(box[1], kx); box[2] = Math.min(box[2], kz); box[3] = Math.max(box[3], kz);
      for (let dz = -1; dz <= 1; dz++) for (let dx = -1; dx <= 1; dx++) {
        const nx = kx + dx, nz = kz + dz, n = nz * gw + nx;
        if (nx < 0 || nz < 0 || nx >= gw || nz >= gh || !occ[n] || lab[n] >= 0) continue;
        lab[n] = id; st.push(n);
      }
    }
    comps.push({ area: (box[1] - box[0] + 1) * (box[3] - box[2] + 1), n: 0 });
  }
  for (let i = 0; i < xs.length; i++) comps[lab[cellOf[i]]].n++;
  let best = -1;
  comps.forEach((c, i) => { if (c.n >= xs.length * 0.04 && (best < 0 || c.area > comps[best].area)) best = i; });
  if (best < 0) return [xs, zs];
  const ox = [], oz = [];
  for (let i = 0; i < xs.length; i++) if (lab[cellOf[i]] === best) { ox.push(xs[i]); oz.push(zs[i]); }
  return [ox, oz];
}

/** Выпуклая оболочка (Эндрю) и наименьший прямоугольник вокруг неё: одна из его сторон лежит на ребре оболочки */
function minRect(xs, zs) {
  const idx = xs.map((_, i) => i).sort((i, j) => xs[i] - xs[j] || zs[i] - zs[j]);
  const cross = (o, a, b) => (xs[a] - xs[o]) * (zs[b] - zs[o]) - (zs[a] - zs[o]) * (xs[b] - xs[o]);
  const lo = [], hi = [];
  for (const i of idx) { while (lo.length > 1 && cross(lo[lo.length - 2], lo[lo.length - 1], i) <= 0) lo.pop(); lo.push(i); }
  for (let k = idx.length - 1; k >= 0; k--) { const i = idx[k]; while (hi.length > 1 && cross(hi[hi.length - 2], hi[hi.length - 1], i) <= 0) hi.pop(); hi.push(i); }
  const h = lo.slice(0, -1).concat(hi.slice(0, -1)).map((i) => [xs[i], zs[i]]);
  const fit = (th) => {
    const cs = Math.cos(th), sn = Math.sin(th);
    let a0 = Infinity, a1 = -Infinity, b0 = Infinity, b1 = -Infinity;
    for (const [x, z] of h) { const u = x * cs + z * sn, v = -x * sn + z * cs; a0 = Math.min(a0, u); a1 = Math.max(a1, u); b0 = Math.min(b0, v); b1 = Math.max(b1, v); }
    const uc = (a0 + a1) / 2, vc = (b0 + b1) / 2;
    return { th, area: (a1 - a0) * (b1 - b0), w: a1 - a0, d: b1 - b0, cx: uc * cs - vc * sn, cz: uc * sn + vc * cs };
  };
  if (h.length < 3) return fit(0);
  let best = null;
  for (let i = 0; i < h.length; i++) {
    const [x1, z1] = h[i], [x2, z2] = h[(i + 1) % h.length];
    if (x1 === x2 && z1 === z2) continue;
    const r = fit(Math.atan2(z2 - z1, x2 - x1));
    if (!best || r.area < best.area - 1e-9) best = r;
  }
  return best || fit(0);
}

/** Где дома в модели (квартал, дороги, кран…): самые высокие и «толстые» постройки, не земля и не кран */
function candidates(THREE, meshes, mBox) {
  const mSize = mBox.getSize(new THREE.Vector3()), mArea = Math.max(1e-6, mSize.x * mSize.z);
  const boxes = meshes.map((o) => new THREE.Box3().setFromObject(o)).filter((b) => !b.isEmpty());
  if (boxes.length < 2) return [mBox.clone()];
  const scored = [];
  boxes.forEach((b) => {
    const s = b.getSize(new THREE.Vector3()), area = s.x * s.z;
    if (area > mArea * 0.35 || s.y < mSize.y * 0.08 || Math.min(s.x, s.z) < s.y * 0.12) return;
    scored.push({ b, score: s.y * Math.sqrt(area) });
  });
  scored.sort((p, q) => q.score - p.score);
  const zones = [];
  for (const { b } of scored) {
    if (zones.some((z) => z.intersectsBox(b))) continue;
    const pad = b.getSize(new THREE.Vector3()).multiplyScalar(0.08), zone = b.clone().expandByVector(new THREE.Vector3(pad.x, 0, pad.z));
    const seedArea = (b.max.x - b.min.x) * (b.max.z - b.min.z), out = b.clone();
    boxes.forEach((o) => {
      const c = o.getCenter(new THREE.Vector3()), s = o.getSize(new THREE.Vector3());
      if (s.x * s.z <= seedArea * 1.5 && c.x >= zone.min.x && c.x <= zone.max.x && c.z >= zone.min.z && c.z <= zone.max.z) out.union(o);
    });
    // второй и следующие корпуса — только заметные дома, а не будка и дерево
    if (zones.length && out.getSize(new THREE.Vector3()).y < zones[0].getSize(new THREE.Vector3()).y * 0.4) continue;
    zones.push(out);
    if (zones.length >= 12) break;
  }
  return zones.length ? zones : [mBox.clone()];
}

/** Пятно дома сверху и высота: центр, поворот, длина, глубина, низ и верх; wallTop — где кончаются отвесные стены (дальше скаты крыши) */
function footprint(THREE, meshes, keep, nearR) {
  const pts = surface(THREE, meshes, keep);
  const n = pts.length / 4;
  if (n < 30) return null;
  const ys = new Float32Array(n);
  for (let i = 0; i < n; i++) ys[i] = pts[4 * i + 1];
  const sorted = Float32Array.from(ys).sort();
  const y0 = sorted[Math.floor(n * 0.01)], y1 = sorted[n - 1];
  const lo = y0 + (y1 - y0) * 0.1, hi = y0 + (y1 - y0) * 0.72;
  let xs = [], zs = [];
  for (let i = 0; i < n; i++) if (ys[i] >= lo && ys[i] <= hi) { xs.push(pts[4 * i]); zs.push(pts[4 * i + 2]); }
  if (xs.length < 40) { xs = []; zs = []; for (let i = 0; i < n; i++) { xs.push(pts[4 * i]); zs.push(pts[4 * i + 2]); } }
  [xs, zs] = mainBlob(xs, zs);
  const R = minRect(xs, zs);
  // рамка: её ось X идёт по длине дома; поворот r — как у рамки в locate3d.js (ось X рамки в мире = (cos r, 0, −sin r))
  let r = -R.th, L = R.w, D = R.d;
  if (nearR == null) {
    if (L < D) { r -= Math.PI / 2; [L, D] = [D, L]; }
    if (Math.cos(r) < 0) r += Math.PI;   // лицом к камере по умолчанию — номера читаются сразу
  } else {
    // «Прижать к дому»: поворот оставляем ближайшим к тому, что поставил человек
    let best = r, bd = Infinity;
    for (let k = 0; k < 4; k++) { const rr = r + k * Math.PI / 2, dd = Math.abs(wrap(rr - nearR)); if (dd < bd) { bd = dd; best = rr; } }
    if (Math.round((best - r) / (Math.PI / 2)) % 2) [L, D] = [D, L];
    r = best;
  }
  r = wrap(r);
  // площадь отвесных стен по высоте внутри пятна: под крышей она ровная, выше (скаты, мансарда, будки на крыше) — резко меньше
  const cs = Math.cos(r), sn = Math.sin(r), BINS = 160, prof = new Float32Array(BINS);
  for (let i = 0; i < n; i++) {
    if (!pts[4 * i + 3]) continue;
    const dx = pts[4 * i] - R.cx, dz = pts[4 * i + 2] - R.cz;
    if (Math.abs(dx * cs - dz * sn) > L * 0.55 || Math.abs(dx * sn + dz * cs) > D * 0.55) continue;
    prof[clamp(Math.floor(((ys[i] - y0) / (y1 - y0 || 1)) * BINS), 0, BINS - 1)]++;
  }
  let wallTop = null;
  const sm = prof.map((_, i) => (prof[Math.max(0, i - 1)] + prof[i] + prof[Math.min(BINS - 1, i + 1)]) / 3);
  const ref = median(sm.slice(Math.round(BINS * 0.1), Math.round(BINS * 0.55)));
  if (ref > 2) {
    let last = Math.round(BINS * 0.4);
    for (let i = last; i < BINS; i++) { if (sm[i] >= ref * 0.55) last = i; else if (i - last > 3) break; }
    wallTop = y0 + ((last + 1) / BINS) * (y1 - y0);
  }
  return { cx: R.cx, cz: R.cz, r, L, D, y0, y1, wallTop, prof: Array.from(prof) };
}
/** Фасад без перспективы — в картинку (через основной холст: те же свет, цвета и тонкомпрессия, что видит человек) */
function shoot(THREE, renderer, scene, F, view, px) {
  const ax = new THREE.Vector3(Math.cos(F.r), 0, -Math.sin(F.r)), az = new THREE.Vector3(Math.sin(F.r), 0, Math.cos(F.r));
  const side = view === 'S', W = (side ? F.D : F.L) * 1.1, H = (F.y1 - F.y0) * 1.06, depth = side ? F.L : F.D, m = 0.04 * Math.max(F.L, F.D) + 0.05;
  const dir = view === 'A' ? az : view === 'B' ? az.clone().negate() : ax;
  const cy = (F.y0 + F.y1) / 2, ctr = new THREE.Vector3(F.cx, cy, F.cz);
  const cam = new THREE.OrthographicCamera(-W / 2, W / 2, H / 2, -H / 2, 0.001, depth + 2 * m);
  cam.position.copy(ctr).addScaledVector(dir, depth / 2 + m); cam.up.set(0, 1, 0); cam.lookAt(ctr); cam.updateProjectionMatrix(); cam.updateMatrixWorld();
  const w = Math.max(16, Math.round(W * px)), h = Math.max(16, Math.round(H * px));
  const light = new THREE.DirectionalLight(0xffffff, 0.9); light.position.copy(cam.position).add(new THREE.Vector3(0, H * 0.3, 0)); light.target.position.copy(ctr);
  scene.add(light, light.target);
  const pr = renderer.getPixelRatio(), size = renderer.getSize(new THREE.Vector2());
  renderer.setPixelRatio(1); renderer.setSize(w, h, false);
  renderer.render(scene, cam);
  const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
  const g = cv.getContext('2d', { willReadFrequently: true });
  g.drawImage(renderer.domElement, 0, 0, w, h, 0, 0, w, h);
  renderer.setPixelRatio(pr); renderer.setSize(size.x, size.y, false);
  scene.remove(light, light.target);
  const data = g.getImageData(0, 0, w, h).data;
  // силуэт и яркость по строкам
  const rowW = new Float32Array(h), left = new Int32Array(h).fill(w), right = new Int32Array(h).fill(-1), lum = new Float32Array(w * h), on = new Uint8Array(w * h);
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = y * w + x, j = i * 4;
    lum[i] = 0.299 * data[j] + 0.587 * data[j + 1] + 0.114 * data[j + 2];
    if (data[j + 3] > 30) { on[i] = 1; rowW[y]++; if (x < left[y]) left[y] = x; right[y] = x; }
  }
  return { canvas: cv, w, h, W, H, yTop: cy + H / 2, rowW, left, right, lum, on, px: h / H };
}
const rowToY = (P, row) => P.yTop - ((row + 0.5) / P.h) * P.H;
const yToRow = (P, y) => clamp(Math.round(((P.yTop - y) / P.H) * P.h - 0.5), 0, P.h - 1);

/** Земля и верх стен по силуэтам фасада (A) и торца (S) */
function walls(A, S) {
  const h = A.h;
  let top = 0; while (top < h - 1 && !A.rowW[top]) top++;
  let bot = h - 1; while (bot > top && !A.rowW[bot]) bot--;
  // подложка во всю ширину картинки снизу — это земля, не дом
  let g = bot; while (g > top && A.rowW[g] >= A.w * 0.985) g--;
  if (bot - g > (bot - top) * 0.5) g = bot;
  const Hc = Math.max(1, g - top);
  const band = (arr) => { const v = []; for (let y = Math.round(g - 0.6 * Hc); y <= Math.round(g - 0.25 * Hc); y++) if (y >= 0 && y < h) v.push(arr[y]); return median(v); };
  const refA = band(A.rowW), refS = band(S.rowW);
  const ok = (y) => A.rowW[y] >= 0.85 * refA && S.rowW[y] >= 0.85 * refS;
  const tol = Math.max(2, Math.round(Hc * 0.025));
  let y = Math.round(g - 0.4 * Hc), last = y;
  for (; y >= top; y--) { if (ok(y)) last = y; else if (last - y > tol) break; }
  return { ground: g, wallTop: last, top };
}

/** Ряды окон между землёй (g) и верхом стен (t): период — высота этажа, фаза — где сами окна */
function windowRows(P, g, t, N) {
  const H = g - t;
  if (H < 24) return null;
  const xl = [], xr = [];
  for (let y = Math.round(g - 0.7 * H); y <= Math.round(g - 0.2 * H); y++) if (P.right[y] > P.left[y]) { xl.push(P.left[y]); xr.push(P.right[y]); }
  if (!xl.length) return null;
  const xa = median(xl), xb = median(xr), inset = Math.round((xb - xa) * 0.02), x0 = xa + inset, x1 = xb - inset;
  if (x1 - x0 < 8) return null;
  const q = new Float32Array(H + 1), w = P.w;
  for (let k = 0; k <= H; k++) {
    const y = g - k; let s = 0, n = 0;
    for (let x = x0; x < x1; x++) { const i = y * w + x; if (!P.on[i] || !P.on[i + 1]) continue; s += Math.abs(P.lum[i + 1] - P.lum[i]); n++; }
    q[k] = n ? s / n : 0;
  }
  const smooth = (a, r) => { const o = new Float32Array(a.length); for (let i = 0; i < a.length; i++) { let s = 0, n = 0; for (let j = Math.max(0, i - r); j <= Math.min(a.length - 1, i + r); j++) { s += a[j]; n++; } o[i] = s / n; } return o; };
  const qs = smooth(q, 1), base = smooth(qs, Math.max(3, Math.round(H / 10))), q2 = qs.map((v, i) => v - base[i]);
  const kmin = Math.max(4, Math.floor(H / 50)), kmax = Math.floor(H / 1.7);
  if (kmax <= kmin + 2) return null;
  const R = new Float32Array(kmax + 2);
  for (let k = kmin - 1; k <= kmax + 1; k++) {
    let s = 0, a2 = 0, b2 = 0;
    for (let i = 0; i + k <= H; i++) { s += q2[i] * q2[i + k]; a2 += q2[i] * q2[i]; b2 += q2[i + k] * q2[i + k]; }
    R[k] = a2 > 0 && b2 > 0 ? s / Math.sqrt(a2 * b2) : 0;
  }
  const peaks = [];
  for (let k = kmin; k <= kmax; k++) if (R[k] > 0.12 && R[k] > R[k - 1] && R[k] >= R[k + 1]) peaks.push(k);
  if (!peaks.length) return null;
  const Rmax = Math.max(...peaks.map((k) => R[k]));
  // этажей в объекте столько-то — если период рядом с ожидаемым есть и он сильный, берём его; иначе — основной (самый короткий сильный)
  const ke = N > 0 ? H / N : 0;
  let k = peaks.find((p) => ke && p >= ke * 0.82 && p <= ke * 1.22 && R[p] >= 0.55 * Rmax);
  if (k == null) k = peaks.find((p) => R[p] >= 0.72 * Rmax);
  const den = R[k - 1] - 2 * R[k] + R[k + 1], p = k + (den ? clamp(0.5 * (R[k - 1] - R[k + 1]) / den, -0.5, 0.5) : 0);
  // фаза: где центры рядов окон
  let phi = 0, best = -Infinity;
  for (let f = 0; f < p; f += 0.5) {
    let s = 0, n = 0;
    for (let c = f; c <= H; c += p) { s += qs[Math.round(c)]; n++; }
    if (n && s / n > best) { best = s / n; phi = f; }
  }
  const rows = [];
  for (let c = phi; c <= H - 0.25 * p; c += p) {
    let s = 0, n = 0;
    for (let i = Math.max(0, Math.round(c - p / 8)); i <= Math.min(H, Math.round(c + p / 8)); i++) { s += qs[i]; n++; }
    rows.push({ c, s: n ? s / n : 0 });
  }
  const med = median(rows.map((r) => r.s)), valid = rows.map((r) => r.s >= med * 0.45);
  const kL = valid.indexOf(true), kH = valid.lastIndexOf(true);
  if (kL < 0) return null;
  const bands = kH - kL + 1, low = rows[kL].c - p / 2;
  let floors = bands, gf = 0;
  if (low > 0.6 * p) { floors++; gf = low; }   // внизу этаж без ряда окон (вестибюль, магазины) — он выше остальных
  else { gf = rows[kL].c + p / 2; if (Math.abs(gf - p) < 0.18 * p) gf = 0; }
  const top = Math.min(H, rows[kH].c + p / 2);
  return { p, strength: R[k], floors, bands, gf, top, rows: rows.filter((_, i) => valid[i]).map((r) => r.c) };
}

/** Порог Оцу: делит значения на «стена» и «окно» */
function otsu(v) {
  let lo = Infinity, hi = -Infinity;
  for (const x of v) { lo = Math.min(lo, x); hi = Math.max(hi, x); }
  if (!(hi > lo)) return hi;
  const B = 64, hist = new Float64Array(B);
  for (const x of v) hist[Math.min(B - 1, Math.floor(((x - lo) / (hi - lo)) * B))]++;
  let sum = 0, total = 0;
  for (let i = 0; i < B; i++) { sum += i * hist[i]; total += hist[i]; }
  let sB = 0, wB = 0, best = 0, cut = 0;
  for (let i = 0; i < B; i++) {
    wB += hist[i]; if (!wB) continue;
    const wF = total - wB; if (!wF) break;
    sB += i * hist[i];
    const mB = sB / wB, mF = (sum - sB) / wF, between = wB * wF * (mB - mF) * (mB - mF);
    if (between > best) { best = between; cut = i; }
  }
  return lo + ((cut + 1) / B) * (hi - lo);
}

/**
 * Окна по горизонтали: в строках через ряды окон окно отличается по цвету от стены (медиана строки).
 * Середины окон — в долях длины рамки (0 — левый край картинки, 1 — правый). «3 окна — двухкомнатная» считает locate3d.js.
 */
function windowCols(P, g, t, L, wrow) {
  const H = g - t;
  if (H < 24) return [];
  const xl = [], xr = [];
  for (let y = Math.round(g - 0.7 * H); y <= Math.round(g - 0.2 * H); y++) if (P.right[y] > P.left[y]) { xl.push(P.left[y]); xr.push(P.right[y]); }
  if (!xl.length) return [];
  const xa = median(xl), xb = median(xr), w = P.w, span = xb - xa + 1;
  if (span < 16) return [];
  const p = wrow ? wrow.p : H / 6, half = Math.max(1, Math.round(p / 7));
  const centers = wrow && wrow.rows && wrow.rows.length ? wrow.rows : [0.3, 0.45, 0.6, 0.75].map((k) => k * H);
  const D = new Float32Array(span);
  let rowsUsed = 0;
  for (const c of centers) for (let dy = -half; dy <= half; dy++) {
    const y = Math.round(g - c + dy);
    if (y < 0 || y >= P.h) continue;
    const vals = [];
    for (let x = xa; x <= xb; x += 2) if (P.on[y * w + x]) vals.push(P.lum[y * w + x]);
    if (vals.length < 8) continue;
    const wall = median(vals);
    for (let x = xa; x <= xb; x++) { const i = y * w + x; if (P.on[i]) D[x - xa] += Math.abs(wall - P.lum[i]); }
    rowsUsed++;
  }
  if (!rowsUsed) return [];
  const sm = D.map((_, i) => (D[Math.max(0, i - 1)] + D[i] + D[Math.min(span - 1, i + 1)]) / 3);
  const thr = otsu(sm);
  let runs = [];
  for (let i = 0; i < span; i++) {
    if (sm[i] < thr) continue;
    const last = runs[runs.length - 1];
    if (last && i - last[1] <= 2) last[1] = i; else runs.push([i, i]);
  }
  const mw = median(runs.map(([a, b]) => b - a + 1));
  runs = runs.filter(([a, b]) => b - a + 1 >= Math.max(3, mw * 0.35));
  // окно с переплётом посередине распадается на две половинки — соседей намного ближе обычного шага склеиваем
  if (runs.length > 2) {
    const mid = ([a, b]) => (a + b) / 2, gaps = runs.slice(1).map((r, i) => mid(r) - mid(runs[i])).sort((p, q) => p - q);
    const step = gaps[Math.floor(gaps.length * 0.75)], out = [runs[0]];
    for (const r of runs.slice(1)) { const last = out[out.length - 1]; if (mid(r) - mid(last) < step * 0.35) last[1] = r[1]; else out.push(r); }
    runs = out;
  }
  if (runs.length < 2 || runs.length > 120) return [];
  // середина окна: доля длины рамки (картинка шире рамки на 10%, по 5% с краёв)
  return runs.map(([a, b]) => { const xi = (xa + (a + b) / 2 + 0.5) / w, lx = xi * P.W - P.W / 2; return +clamp((lx + L / 2) / L, 0, 1).toFixed(4); });
}

/** Картинка для ИИ: фасады A и B рядом, буквы над ними */
function composite(A, B) {
  const k = Math.min(1, 640 / Math.max(A.w, A.h)), pw = Math.round(A.w * k), ph = Math.round(A.h * k), pad = 24, lab = 40;
  const cv = document.createElement('canvas'); cv.width = pw * 2 + pad * 3; cv.height = ph + lab + pad;
  const g = cv.getContext('2d');
  g.fillStyle = '#ffffff'; g.fillRect(0, 0, cv.width, cv.height);
  [[A, 'A'], [B, 'B']].forEach(([P, n], i) => {
    const x = pad + i * (pw + pad);
    g.fillStyle = '#c9d3df'; g.fillRect(x, lab, pw, ph);
    g.drawImage(P.canvas, x, lab, pw, ph);
    g.strokeStyle = '#64748b'; g.lineWidth = 2; g.strokeRect(x - 1, lab - 1, pw + 2, ph + 2);
    g.fillStyle = '#111827'; g.font = 'bold 28px sans-serif'; g.fillText(n, x, 30);
  });
  return cv.toDataURL('image/jpeg', 0.86);
}

/**
 * Один корпус. region: null — искать дом самим; { keep, nearR } — в этой области (рамка, поставленная человеком).
 * block: { floors, entr }. ai(image) → ответ ИИ или null.
 * @returns {{ cal, report, F }}
 */
export async function fitBlock(o) {
  const { THREE, renderer, scene, meshes, keep, nearR, block, ai } = o;
  const F = footprint(THREE, meshes, keep, nearR);
  if (!F || !(F.L > 0) || !(F.y1 > F.y0)) return null;
  const N = Math.max(1, block.floors || 1), E = Math.max(1, (block.entr || []).length);
  const px = Math.min(2048 / (Math.max(F.L, F.D) * 1.1), Math.max(900 / Math.max(F.L * 1.1, (F.y1 - F.y0) * 1.06), 640 / ((F.y1 - F.y0) * 1.06)));
  const A = shoot(THREE, renderer, scene, F, 'A', px), B = shoot(THREE, renderer, scene, F, 'B', px), S = shoot(THREE, renderer, scene, F, 'S', px);
  const wl = walls(A, S);
  let yG = rowToY(A, wl.ground), yT = rowToY(A, wl.wallTop);
  // силуэт не видит мансарду и крышу во всю ширину — верх стен уточняем по отвесным поверхностям
  if (F.wallTop != null && F.wallTop < yT && F.wallTop > yG + 0.35 * (yT - yG)) yT = F.wallTop;
  const report = { by: 'geo', mf: null, doors: null, warn: [], ai: null };
  let front = 'A', a = null;
  if (ai) {
    a = await ai(composite(A, B), E).catch(() => null);
    if (a) {
      report.by = 'ai'; report.ai = a;
      if (a.is_building === false) report.warn.push('notBuilding');
      front = a.front === 'B' ? 'B' : 'A';
      const P = front === 'A' ? A : B;
      // верх стен и землю геометрия знает точнее (ИИ ошибается на пол-этажа); по ИИ — только если силуэт стен не дал
      if (yT - yG < 0.35 * (F.y1 - F.y0) && a.eave_y > 0 && a.ground_y > a.eave_y) {
        yG = P.yTop - (a.ground_y / 1000) * P.H; yT = P.yTop - (a.eave_y / 1000) * P.H;
      }
    }
  }
  // этажи по рядам окон — на лицевом и заднем фасаде, берём более чёткий
  const P = front === 'A' ? A : B, Q = front === 'A' ? B : A;
  const g = yToRow(P, yG), t = yToRow(P, yT);
  const wrP = windowRows(P, g, t, N), wrQ = windowRows(Q, g, t, N);
  const wr = [wrP, wrQ].filter(Boolean).sort((p, q) => q.strength - p.strength)[0] || null;
  let floors = N, gf = 0, top = yT;
  // не дом (ИИ так сказал) или «этажей» втрое больше, чем в объекте (узор земли, плитки) — это не ряды окон
  const notHouse = !!(a && a.is_building === false);
  const strong = !notHouse && wr && wr.strength >= 0.3 && wr.bands >= 2 && wr.floors <= N * 3 + 5;
  const mf = notHouse ? null : strong ? wr.floors : a && a.floors > 0 ? a.floors : null;
  report.mf = mf;
  if (strong) {
    const pW = wr.p / P.px;
    top = Math.min(yT, yG + wr.top / P.px);
    if (wr.floors === N) gf = wr.gf / P.px;
    else if (Math.abs(wr.floors - N) === 1) {
      const g2 = top - yG - (N - 1) * pW;
      gf = g2 > 1.18 * pW && g2 < 2.6 * pW ? g2 : 0;
    } else {
      // модель не этого дома: рамка — по её этажам, этаж объекта покажется на этаже модели той же доли высоты (cellOf)
      report.warn.push('floors'); floors = wr.floors; gf = wr.floors > 1 ? wr.gf / P.px : 0;
    }
  } else if (mf && Math.abs(mf - N) >= 2) { report.warn.push('floors'); floors = mf; }
  else if (a && a.floors === N && a.first_floor_top_y > 0) {
    const yf = P.yTop - (a.first_floor_top_y / 1000) * P.H, g1 = yf - yG, h1 = (top - yG) / N;
    if (g1 > 1.1 * h1 && g1 < 3 * h1) gf = g1;
  }
  // рамка: если лицевая сторона — B, разворачиваем рамку, чтобы её лицо (+Z) смотрело на входы
  const r = wrap(front === 'B' ? F.r + Math.PI : F.r);
  let ex = null;
  if (a && Array.isArray(a.doors) && a.doors.length) {
    report.doors = a.doors.length;
    if (E > 1 && a.doors.length === E) {
      // двери — середины подъездов; границы подъездов — посередине между дверями (x картинки: слева направо по лицу рамки)
      const xs = a.doors.map((x) => (x / 1000 - 0.5) * P.W).sort((p, q) => p - q);
      ex = [];
      for (let k = 0; k < E - 1; k++) ex.push(+clamp(((xs[k] + xs[k + 1]) / 2 + F.L / 2) / F.L, 0.04, 0.96).toFixed(4));
      for (let k = 1; k < ex.length; k++) if (ex[k] <= ex[k - 1] + 0.02) { ex = null; break; }
    } else if (E > 1 || a.doors.length > 1) report.warn.push('doors');
  }
  const H = Math.max(0.1, top - yG);
  if (gf > H * 0.6) gf = 0;
  const cal = {
    c: [+F.cx.toFixed(4), +((yG + top) / 2).toFixed(4), +F.cz.toFixed(4)], s: [+F.L.toFixed(4), +H.toFixed(4), +F.D.toFixed(4)], r: +r.toFixed(5),
    floors, gf: +gf.toFixed(2), rows: 2, back: 'rev', flipX: false, flipZ: false,
  };
  if (ex) cal.ex = ex;
  // окна на лицевой (+Z рамки: фасад P) и задней (−Z: Q, на картинке зеркально) сторонах — по ним квартиры встанут на свои окна
  const tP = yToRow(P, top), wf = windowCols(P, g, tP, F.L, wrP), wb = windowCols(Q, g, tP, F.L, wrQ).map((x) => +(1 - x).toFixed(4)).sort((p, q) => p - q);
  if (wf.length || wb.length) cal.win = { f: wf, b: wb };
  // для проверки (тесты, консоль): снимки фасадов и промежуточные числа
  const dbg = o.debug ? { F, wl, wr, a, yG, yT, top, rows: { A: Array.from(A.rowW), S: Array.from(S.rowW) }, img: { A: A.canvas.toDataURL(), B: B.canvas.toDataURL(), S: S.canvas.toDataURL() } } : undefined;
  return { cal, report, F, dbg };
}

export { candidates, footprint, shoot, walls, windowRows };
