/* Интерьеры квартир по планировкам — и по комнатам.
   У планировки: 3D-интерьер и чертёж — на всю квартиру; у каждой комнаты (гостиная, кухня, спальня…) — свои панорамы 360° и рендеры.
   Загрузить, переименовать, перенести к другой планировке или комнате, удалить — прямо там, где их смотрят:
   в привязке к 3D (шаг «Внутри») и на странице «Интерьеры» (её открывает «Дизайн квартиры» в программе).
   Файл одной планировки увидят у всех её квартир; у объектов без перечня помещений — по числу комнат. */

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
/** Ключ планировки квартиры: своя планировка из перечня, иначе число комнат */
export const layKey = (u) => (u.t ? 't:' + u.t : 'r:' + (u.r || 0));
/** Файлы квартиры — тем же правилом, что ObjectMedia::forUnit: своей планировки, иначе по числу комнат, иначе общие */
export const innerOf = (inner, u) => {
  const own = u.t ? inner.filter((m) => m.l === u.t) : [];
  return own.length ? own : inner.filter((m) => !m.l && (m.r == null || +m.r === +u.r));
};
/** Комната файла: вписанная; у старых панорам без комнаты — название */
export const roomOf = (m) => String(m.room || (m.k === 'pano' ? m.n : '') || '').trim();
const same = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase();

/**
 * box — куда рисовать карточки планировок. o: { T, ICON, inner (массив файлов, меняется на месте), units ([{k, r, a, t, tn}]),
 * urls: { up, file, patch, del } ({id} — номер файла), csrf, cvt, mcUrl, canEdit, prog, onView(unit, file, room), onChange() }
 */
export function innerManager(box, o) {
  const T = o.T, ICON = o.ICON, inner = o.inner, R = T.roomNames;
  let cur = null, upFor = null;
  const extra = new Map();   // комнаты, добавленные кнопкой «+ Комната», пока в них ничего не загружено
  const fileUrl = (m) => o.urls.file.replace('{id}', m.id);
  const picker = document.createElement('input'); picker.type = 'file'; picker.hidden = true; document.body.append(picker);

  /** Планировки: из перечня помещений; если их нет — группы по числу комнат */
  function layouts() {
    const map = new Map();
    o.units.filter((u) => u.k === 'flat' || u.r > 0).forEach((u) => {
      const k = layKey(u);
      if (!map.has(k)) map.set(k, { key: k, u, n: 0, name: u.t ? (u.tn || u.t) : `${u.r}${T.rooms}` });
      map.get(k).n++;
    });
    return [...map.values()].sort((a, b) => (a.u.r || 0) - (b.u.r || 0) || (a.u.a || 0) - (b.u.a || 0));
  }
  /** Комнаты квартиры по числу комнат: 2-комнатная — гостиная, спальня, кухня, ванная, прихожая */
  function defRooms(r) {
    const list = (r || 0) <= 1 ? [R.room] : [R.living, R.bed];
    if (r >= 3) list.push(R.kids);
    if (r >= 4) list.push(R.study);
    for (let i = 5; i <= r; i++) list.push(`${R.bed} ${i - 3}`);
    return list.concat([R.kitchen, R.bath, R.hall]);
  }
  /** Комнаты карточки: обычные для такой квартиры, добавленные вручную и те, что уже есть у файлов; «без комнаты» — в конце */
  function roomsOf(l, list) {
    const names = [];
    const add = (n) => { if (n && !names.some((x) => same(x, n))) names.push(n); };
    defRooms(l.u.r).forEach(add);
    (extra.get(l.key) || []).forEach(add);
    list.filter((m) => m.k === 'pano' || m.k === 'photo').forEach((m) => add(roomOf(m)));
    const out = names.map((n) => ({ name: n, files: list.filter((m) => (m.k === 'pano' || m.k === 'photo') && roomOf(m) && same(roomOf(m), n)) }));
    const loose = list.filter((m) => (m.k === 'pano' || m.k === 'photo') && !roomOf(m));
    if (loose.length) out.push({ name: '', files: loose });
    return out;
  }
  const isImg = (m) => m.k === 'photo' || m.k === 'pano' || (m.k === 'plan' && m.ext !== 'pdf');
  const acts = () => (o.canEdit ? `<i class="im-act"><button type="button" data-ed title="${esc(T.edit)}">${ICON.edit}</button><button type="button" data-del title="${esc(T.del)}">${ICON.del}</button></i>` : '');
  const thumb = (m, label) => `<div class="im-th${m.k === 'pano' ? ' pano' : ''}" data-m="${m.id}" title="${esc(m.n)}"${isImg(m) ? ` style="background-image:url('${esc(fileUrl(m))}')"` : ''}>
      ${isImg(m) ? '' : ICON[m.k] || ''}${m.k === 'pano' ? '<em>360°</em>' : ''}${label ? `<span>${esc(label)}</span>` : ''}${acts()}</div>`;
  const addBtn = (kind, text) => (o.canEdit ? `<button type="button" class="im-add" data-up="${kind}" title="${esc(T.upTitle[kind])}">${text}</button>` : '');

  function render() {
    box.innerHTML = layouts().map((l) => {
      const list = innerOf(inner, l.u), ints = list.filter((m) => m.k === 'interior'), plans = list.filter((m) => m.k === 'plan');
      const rooms = roomsOf(l, list).filter((r) => o.canEdit || r.files.length);
      if (!o.canEdit && !list.length) return `<div class="im-lay" data-lay="${esc(l.key)}"><div class="im-lay-h"><b>${esc(l.name)}</b></div><div class="im-empty">${esc(T.noInnerView)}</div></div>`;
      return `<div class="im-lay${cur === l.key ? ' cur' : ''}" data-lay="${esc(l.key)}">
        <div class="im-lay-h"><b>${esc(l.name)}</b><small>${l.n} ${esc(T.flats)}${l.u.a ? ' · ' + esc(l.u.a) + ' ' + esc(T.m2) : ''}</small></div>
        <div class="im-whole">
          <div class="im-slot"><div class="im-slot-h">${ICON.box}<b>${esc(T.whole3d)}</b></div><div class="im-room-b">${ints.map((m) => thumb(m, m.n)).join('')}${addBtn('interior', '+ 3D')}</div></div>
          <div class="im-slot"><div class="im-slot-h">${ICON.ruler}<b>${esc(T.planH)}</b></div><div class="im-room-b">${plans.map((m) => thumb(m, m.ext === 'pdf' ? 'PDF' : '')).join('')}${addBtn('plan', '+ ' + T.kindsShort.plan)}</div></div>
        </div>
        <div class="im-rooms-h"><b>${esc(T.roomsH)}</b><small>${esc(T.roomsHint)}</small></div>
        ${rooms.map((r) => !r.files.length
          // пустая комната — одна строка: название и кнопки загрузки
          ? `<div class="im-room" data-room="${esc(r.name)}"><div class="im-room-b"><span class="im-room-n">${esc(r.name)}</span>${addBtn('pano', '+ 360°')}${addBtn('photo', '+ ' + T.kindsShort.photo)}</div></div>`
          : `<div class="im-room has" data-room="${esc(r.name)}">
          <div class="im-room-h"><b>${esc(r.name || T.noRoom)}</b><small>${[['pano', '360°'], ['photo', T.kindsShort.photo]].map(([k, t]) => { const c = r.files.filter((m) => m.k === k).length; return c ? `${t}: ${c}` : ''; }).filter(Boolean).join(' · ')}</small>
            <button type="button" class="im-ico" data-viewroom title="${esc(T.look)}">${ICON.eye}</button>${o.canEdit && r.name ? `<button type="button" class="im-ico" data-renroom title="${esc(T.renRoom)}">${ICON.edit}</button>` : ''}</div>
          <div class="im-room-b">${r.files.slice().sort((a, b) => (a.k === 'pano' ? 0 : 1) - (b.k === 'pano' ? 0 : 1)).map((m) => thumb(m, '')).join('')}${r.name ? addBtn('pano', '+ 360°') + addBtn('photo', '+ ' + T.kindsShort.photo) : ''}</div>
        </div>`).join('')}
        ${o.canEdit ? `<button type="button" class="im-addroom" data-addroom>+ ${esc(T.addRoom)}</button>` : ''}
        ${list.length ? `<button type="button" class="btn btn-s btn-p" data-view>${ICON.eye}${esc(T.look)}</button>` : ''}
      </div>`;
    }).join('') || `<div class="im-empty">${esc(T.noLayouts)}</div>`;
    const c = box.querySelector('.im-lay.cur');
    if (c) c.scrollIntoView({ block: 'nearest' });
  }
  const prog = o.prog || (() => {});
  /** Панорама 360° — картинка вдвое шире высоты */
  async function ratioOf(f) {
    try { const b = await createImageBitmap(f); const k = b.width / b.height; b.close && b.close(); return k; } catch (e) { return 0; }
  }
  const fromName = (f) => f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim();
  /** Загрузка частями по 5 МБ — тот же путь, что «3D, панорамы, планировки» на странице объекта */
  async function uploadFile(f, kind, u, extraF = {}) {
    const CH = 5 * 1024 * 1024, total = Math.max(1, Math.ceil(f.size / CH)), id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
    let res = null;
    for (let i = 0; i < total; i++) {
      const fd = new FormData();
      fd.append('_token', o.csrf); fd.append('upload', id); fd.append('index', i); fd.append('total', total); fd.append('name', f.name); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'part');
      fd.append('kind', kind); fd.append('title', extraF.title || '');
      if (u.t) fd.append('layout', u.t); else fd.append('rooms', u.r || 0);
      if (extraF.room) fd.append('room', extraF.room);
      if (extraF.src) fd.append('src', extraF.src);
      let r, tries = 0;
      do { r = await fetch(o.urls.up, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).catch(() => null); } while ((!r || r.status >= 500) && ++tries < 3);
      if (!r || !r.ok) { let m = T.upFail; try { const j = await r.json(); m = j.error || j.message || m; } catch (e) { /* без текста */ } throw new Error(m); }
      prog((i + 1) / total, `${T.upBusy} ${f.name}`);
      if (i === total - 1) res = await r.json().catch(() => null);
    }
    return res && res.id;
  }
  /** Загрузить файлы для планировки: 3D (не GLB — сначала перевод в GLB в браузере), чертёж; в комнату — 360° (проверка 2:1) и фото */
  async function upKind(files, kind, lay, room) {
    const u = lay.u, items = [];
    cur = lay.key;
    try {
      if (kind === 'interior' && files.every((f) => /\.(jpe?g|png|webp)$/i.test(f.name))) {
        // в «3D» выбрали только картинки — это не модель: снимки 2:1 — панорамы, остальное — фото; комната — из имени файла
        if (!confirm(T.imgsNot3d)) return;
        for (const f of files) { const r = await ratioOf(f), pano = r >= 1.9 && r <= 2.1; items.push([f, pano ? 'pano' : 'photo', { room: fromName(f) }]); }
      } else if (kind === 'interior') {
        if (files.length === 1 && /\.glb$/i.test(files[0].name)) items.push([files[0], 'interior', {}]);
        else {
          prog(0, o.cvt.p_read);
          const mod = await import(o.mcUrl);
          const res = await mod.convert(files, { kind: 'interior', units: 'auto', up: 'auto', tex: 2048, merge: false, T: o.cvt }, (t) => prog(null, t));
          items.push([res.file, 'interior', { src: res.info.source }]);
        }
      } else for (const f of files) {
        let k = kind;
        if (k === 'pano') {
          const r = await ratioOf(f);
          if (r && (r < 1.9 || r > 2.1)) { if (!confirm(T.notPano.replace(':f', f.name))) continue; k = 'photo'; }
        }
        items.push([f, k, k === 'plan' ? {} : { room: room || fromName(f) }]);
      }
      for (const [f, k, ex] of items) {
        prog(0, `${T.upBusy} ${f.name}`);
        const id = await uploadFile(f, k, u, ex);
        if (id) inner.push({ id, k, l: u.t || undefined, r: u.r, n: fromName(f), room: ex.room || undefined, ext: (f.name.split('.').pop() || '').toLowerCase(), p: 1 });
      }
      if (room) { const ex = extra.get(lay.key) || []; extra.set(lay.key, ex.filter((x) => !same(x, room))); }
      prog(1, T.upDone);
      setTimeout(() => prog(false), 2500);
    } catch (e) {
      prog(false); alert(e && e.message ? e.message : T.upFail);
    } finally { render(); o.onChange && o.onChange(); }
  }
  const post = async (url, body, method = 'POST') => {
    const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': o.csrf }, body: body ? JSON.stringify(body) : undefined }).catch(() => null);
    const j = r ? await r.json().catch(() => ({})) : {};
    if (!r || !r.ok) throw new Error(j.error || j.message || T.err);
    return j;
  };
  /** Поправить файл: название, комната (для 360° и фото), планировка (перенести к другой) */
  function edit(m, lay) {
    // варианты: планировки объекта, «для всех»; текущая привязка файла есть всегда — иначе сохранение молча перенесло бы его
    const curKey = m.l ? 't:' + m.l : m.r == null ? 'all' : 'r:' + m.r;
    const opts = layouts().map((l) => [l.key, l.name]);
    if (curKey !== 'all' && !opts.some(([k]) => k === curKey)) opts.push([curKey, m.l ? m.l : `${m.r}${T.rooms}`]);
    opts.push(['all', T.forAll]);
    const roomy = m.k === 'pano' || m.k === 'photo', names = lay ? roomsOf(lay, innerOf(inner, lay.u)).map((r) => r.name).filter(Boolean) : [];
    const dlg = document.createElement('dialog'); dlg.className = 'im-dlg';
    dlg.innerHTML = `<form method="dialog"><h3>${esc(T.editFile)}</h3>
      <label class="field"><span>${esc(T.fileName)}</span><input name="title" maxlength="160" value="${esc(m.n || '')}" required></label>
      ${roomy ? `<label class="field"><span>${esc(T.roomName)}</span><input name="room" maxlength="60" list="imRoomList" value="${esc(m.room || '')}"><datalist id="imRoomList">${names.map((n) => `<option value="${esc(n)}">`).join('')}</datalist></label>` : ''}
      <label class="field"><span>${esc(T.forLayout)}</span><select name="lay">${opts.map(([k, n]) => `<option value="${esc(k)}"${k === curKey ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>
      <div class="im-dlg-b"><button type="button" class="btn" value="x">${esc(T.cancel)}</button><button class="btn btn-p" value="ok">${esc(T.save)}</button></div></form>`;
    document.body.append(dlg);
    dlg.querySelector('[value=x]').onclick = () => dlg.close();
    dlg.addEventListener('close', async () => {
      const ok = dlg.returnValue === 'ok', f = dlg.querySelector('form');
      const E = f.elements, v = { title: E.title.value.trim(), room: E.room ? E.room.value.trim() : undefined, lay: E.lay.value };
      dlg.remove();
      if (!ok) return;
      const body = { title: v.title };
      if (v.room !== undefined) body.room = v.room;
      if (v.lay !== curKey) {
        if (v.lay.startsWith('t:')) body.layout = v.lay.slice(2);
        else { body.layout = ''; body.rooms = v.lay === 'all' ? null : +v.lay.slice(2) || 0; }
      }
      try {
        const j = await post(o.urls.patch.replace('{id}', m.id), body);
        Object.assign(m, { n: j.m.n, room: j.m.room || undefined, l: j.m.l || undefined, r: j.m.r ?? undefined });
        render(); o.onChange && o.onChange();
      } catch (e) { alert(e.message); }
    });
    dlg.showModal();
  }
  async function del(m) {
    if (!confirm(T.delFileQ.replace(':f', m.n || T.kinds[m.k]))) return;
    try {
      await post(o.urls.del.replace('{id}', m.id), null, 'DELETE');
      inner.splice(inner.indexOf(m), 1);
      render(); o.onChange && o.onChange();
    } catch (e) { alert(e.message); }
  }
  /** Переименовать комнату — у всех её 360° и фото этой планировки */
  async function renameRoom(lay, name) {
    const to = (prompt(T.renRoomQ, name) || '').trim();
    if (!to || to === name) return;
    const files = innerOf(inner, lay.u).filter((m) => (m.k === 'pano' || m.k === 'photo') && same(roomOf(m), name));
    try {
      for (const m of files) { const j = await post(o.urls.patch.replace('{id}', m.id), { room: to }); m.room = j.m.room || undefined; }
    } catch (e) { alert(e.message); }
    render(); o.onChange && o.onChange();
  }
  box.addEventListener('click', (e) => {
    const card = e.target.closest('[data-lay]');
    if (!card) return;
    const lay = layouts().find((x) => x.key === card.dataset.lay);
    if (!lay) return;
    const roomEl = e.target.closest('[data-room]'), room = roomEl ? roomEl.dataset.room : null;
    const th = e.target.closest('[data-m]'), m = th && inner.find((x) => String(x.id) === th.dataset.m);
    if (m && e.target.closest('[data-ed]')) return edit(m, lay);
    if (m && e.target.closest('[data-del]')) return del(m);
    if (m) return o.onView && o.onView(lay.u, m);
    if (e.target.closest('[data-viewroom]')) return o.onView && o.onView(lay.u, null, room);
    if (e.target.closest('[data-renroom]')) return renameRoom(lay, room);
    if (e.target.closest('[data-view]')) return o.onView && o.onView(lay.u);
    if (e.target.closest('[data-addroom]')) {
      const n = (prompt(T.addRoomQ, '') || '').trim();
      if (!n) return;
      extra.set(lay.key, (extra.get(lay.key) || []).concat([n]));
      cur = lay.key; render();
      return;
    }
    const up = e.target.closest('[data-up]');
    if (!up) return;
    upFor = { lay, kind: up.dataset.up, room };
    picker.accept = { interior: '.glb,.gltf,.bin,.fbx,.obj,.mtl,.dae,.3ds,.stl,.ply,.3mf,.ifc,.zip,.jpg,.jpeg,.png,.tga,.bmp', pano: '.jpg,.jpeg,.png,.webp', photo: '.jpg,.jpeg,.png,.webp', plan: '.pdf,.jpg,.jpeg,.png,.webp' }[upFor.kind];
    picker.multiple = upFor.kind !== 'plan';
    picker.click();
  });
  picker.addEventListener('change', () => { const files = [...picker.files]; picker.value = ''; if (files.length && upFor) upKind(files, upFor.kind, upFor.lay, upFor.room); });

  return { render, layouts, setCur(k) { cur = k; }, files: () => inner };
}
