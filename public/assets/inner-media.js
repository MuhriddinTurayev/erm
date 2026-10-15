/* Интерьеры квартир — по планировкам, по отдельным квартирам и по комнатам.
   У квартиры (или планировки): 3D-интерьер и чертёж — на всю квартиру; у каждой комнаты (гостиная, кухня, спальня…) — свои 360° и фото.
   Планировка: файл увидят все её квартиры (у объектов без перечня помещений — по числу комнат).
   Квартира: файл загружен прямо к ней — меняется только она; «Такие же квартиры» — отметить одинаковые, чтобы не загружать в каждую.
   Загрузить, переименовать, перенести, удалить — прямо там, где их смотрят: в привязке к 3D (шаг «Внутри»)
   и на странице «Интерьеры» (её открывает программа: «Дизайн квартиры», «Квартира для клиента»). */

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
/** Ключ планировки квартиры: своя планировка из перечня, иначе число комнат */
export const layKey = (u) => (u.t ? 't:' + u.t : 'r:' + (u.r || 0));
/** Квартиры, к которым файл загружен напрямую (пусто — файл планировки) */
export const unitsOfFile = (m) => (Array.isArray(m.u) ? m.u.map(String) : []);
const GROUPS = [['interior', 'pano'], ['photo'], ['plan']];
/** Файлы планировки — тем же правилом, что ObjectMedia::forUnitAll: своей планировки, иначе по числу комнат, иначе общие */
export function layoutFiles(inner, u) {
  const list = inner.filter((m) => !unitsOfFile(m).length), out = [];
  for (const kinds of GROUPS) {
    const g = list.filter((m) => kinds.includes(m.k)), own = u.t ? g.filter((m) => m.l === u.t) : [];
    out.push(...(own.length ? own : g.filter((m) => !m.l && (m.r == null || +m.r === +u.r))));
  }
  return out;
}
/** Файлы квартиры — как ObjectMedia::forApt: свои (её и одинаковых с ней) по каждому виду, чего своего нет — планировки */
export function innerOf(inner, u) {
  const own = u.id != null ? inner.filter((m) => unitsOfFile(m).includes(String(u.id))) : [], kinds = new Set(own.map((m) => m.k));
  return own.concat(layoutFiles(inner.filter((m) => !kinds.has(m.k)), u));
}
/** Комната файла: вписанная; у старых панорам без комнаты — название */
export const roomOf = (m) => String(m.room || (m.k === 'pano' ? m.n : '') || '').trim();
const same = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase();

/**
 * box — куда рисовать. o: { T, ICON, inner (массив файлов, меняется на месте), units ([{id, num, b, e, f, i, n, k, r, a, t, tn}]),
 * apt (квартира — её карточка первой, «свой» интерьер), urls: { up, file, patch, del, same } ({id} — номер файла), csrf, cvt, mcUrl,
 * canEdit, prog, onView(unit, file, room), onChange() }
 */
export function innerManager(box, o) {
  const T = o.T, ICON = o.ICON, inner = o.inner, R = T.roomNames;
  let cur = null, upFor = null, apt = o.apt || null;
  const extra = new Map();   // комнаты, добавленные кнопкой «+ Комната», пока в них ничего не загружено
  const fileUrl = (m) => o.urls.file.replace('{id}', m.id);
  const picker = document.createElement('input'); picker.type = 'file'; picker.hidden = true; document.body.append(picker);
  const flats = () => o.units.filter((u) => u.k === 'flat' || u.r > 0);

  /** Планировки: из перечня помещений; если их нет — группы по числу комнат */
  function layouts() {
    const map = new Map();
    flats().forEach((u) => {
      const k = layKey(u);
      if (!map.has(k)) map.set(k, { key: k, u, n: 0, name: u.t ? (u.tn || u.t) : `${u.r}${T.rooms}` });
      map.get(k).n++;
    });
    return [...map.values()].sort((a, b) => (a.u.r || 0) - (b.u.r || 0) || (a.u.a || 0) - (b.u.a || 0));
  }
  /** Квартиры с тем же интерьером, что у apt (по её собственным файлам) */
  const groupOf = (a) => { const s = new Set([String(a.id)]); inner.forEach((m) => { const us = unitsOfFile(m); if (us.includes(String(a.id))) us.forEach((x) => s.add(x)); }); return [...s]; };
  const aptName = (a) => `${T.kv} ${a.num}`;
  /** Карточки: квартира (если выбрана) и планировки */
  function cards() {
    const out = [];
    if (apt) {
      const list = innerOf(inner, apt), own = new Set(list.filter((m) => unitsOfFile(m).includes(String(apt.id))).map((m) => m.id));
      out.push({ key: 'apt:' + apt.id, u: apt, apt, list, own, group: groupOf(apt) });
    }
    layouts().forEach((l) => { const list = layoutFiles(inner, l.u); out.push(Object.assign({ list, own: new Set(list.map((m) => m.id)) }, l)); });
    return out;
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
  function roomsOf(c) {
    const names = [], list = c.list;
    const add = (n) => { if (n && !names.some((x) => same(x, n))) names.push(n); };
    defRooms(c.u.r).forEach(add);
    (extra.get(c.key) || []).forEach(add);
    list.filter((m) => m.k === 'pano' || m.k === 'photo').forEach((m) => add(roomOf(m)));
    const out = names.map((n) => ({ name: n, files: list.filter((m) => (m.k === 'pano' || m.k === 'photo') && roomOf(m) && same(roomOf(m), n)) }));
    const loose = list.filter((m) => (m.k === 'pano' || m.k === 'photo') && !roomOf(m));
    if (loose.length) out.push({ name: '', files: loose });
    return out;
  }
  const isImg = (m) => m.k === 'photo' || m.k === 'pano' || (m.k === 'plan' && m.ext !== 'pdf');
  const acts = () => (o.canEdit ? `<i class="im-act"><button type="button" data-ed title="${esc(T.edit)}">${ICON.edit}</button><button type="button" data-del title="${esc(T.del)}">${ICON.del}</button></i>` : '');
  // файл планировки в карточке квартиры: виден, но правится в карточке планировки (иначе изменится у всех её квартир)
  const thumb = (c, m, label) => { const mine = c.own.has(m.id); return `<div class="im-th${m.k === 'pano' ? ' pano' : ''}${mine ? '' : ' shared'}" data-m="${m.id}" title="${esc(mine ? m.n : T.sharedTip.replace(':n', m.n || ''))}"${isImg(m) ? ` style="background-image:url('${esc(fileUrl(m))}')"` : ''}>
      ${isImg(m) ? '' : ICON[m.k] || ''}${m.k === 'pano' ? '<em>360°</em>' : ''}${label ? `<span>${esc(label)}</span>` : ''}${mine ? acts() : `<i class="im-sh">${esc(T.sharedMark)}</i>`}</div>`; };
  const addBtn = (kind, text) => (o.canEdit ? `<button type="button" class="im-add" data-up="${kind}" title="${esc(T.upTitle[kind])}">${text}</button>` : '');

  function cardHtml(c) {
    const ints = c.list.filter((m) => m.k === 'interior'), plans = c.list.filter((m) => m.k === 'plan');
    const rooms = roomsOf(c).filter((r) => o.canEdit || r.files.length);
    const head = c.apt
      ? `<div class="im-lay-h"><b>${esc(aptName(c.apt))}${c.apt.r ? ' · ' + c.apt.r + esc(T.rooms) : ''}</b><small>${c.apt.a ? esc(c.apt.a) + ' ' + esc(T.m2) : ''}${c.apt.f ? ' · ' + c.apt.f + '-' + esc(T.fl) : ''}</small></div>
        <div class="im-apt-st">${c.own.size
          ? `<span>${esc(T.aptOwn)}</span>${c.group.length > 1 ? `<span class="im-same-list">${esc(T.sameAs)} ${c.group.filter((x) => x !== String(c.apt.id)).map((x) => { const u = o.units.find((y) => String(y.id) === x); return esc(u ? u.num : x); }).join(', ')}</span>` : ''}
            ${o.canEdit ? `<button type="button" class="btn btn-s" data-same>${ICON.copy || ''}${esc(T.sameBtn)}</button>` : ''}`
          : `<span>${esc(T.aptShared)}</span>`}</div>`
      : `<div class="im-lay-h"><b>${esc(c.name)}</b><small>${c.n} ${esc(T.flats)}${c.u.a ? ' · ' + esc(c.u.a) + ' ' + esc(T.m2) : ''}</small></div>`;
    if (!o.canEdit && !c.list.length) return `<div class="im-lay" data-lay="${esc(c.key)}">${head}<div class="im-empty">${esc(T.noInnerView)}</div></div>`;
    return `<div class="im-lay${c.apt ? ' im-apt' : ''}${cur === c.key ? ' cur' : ''}" data-lay="${esc(c.key)}">${head}
      <div class="im-whole">
        <div class="im-slot"><div class="im-slot-h">${ICON.box}<b>${esc(T.whole3d)}</b></div><div class="im-room-b">${ints.map((m) => thumb(c, m, m.n)).join('')}${addBtn('interior', '+ 3D')}</div></div>
        <div class="im-slot"><div class="im-slot-h">${ICON.ruler}<b>${esc(T.planH)}</b></div><div class="im-room-b">${plans.map((m) => thumb(c, m, m.ext === 'pdf' ? 'PDF' : '')).join('')}${addBtn('plan', '+ ' + T.kindsShort.plan)}</div></div>
      </div>
      <div class="im-rooms-h"><b>${esc(T.roomsH)}</b><small>${esc(T.roomsHint)}</small></div>
      ${rooms.map((r) => !r.files.length
        // пустая комната — одна строка: название и кнопки загрузки
        ? `<div class="im-room" data-room="${esc(r.name)}"><div class="im-room-b"><span class="im-room-n">${esc(r.name)}</span>${addBtn('pano', '+ 360°')}${addBtn('photo', '+ ' + T.kindsShort.photo)}</div></div>`
        : `<div class="im-room has" data-room="${esc(r.name)}">
        <div class="im-room-h"><b>${esc(r.name || T.noRoom)}</b><small>${[['pano', '360°'], ['photo', T.kindsShort.photo]].map(([k, t]) => { const n = r.files.filter((m) => m.k === k).length; return n ? `${t}: ${n}` : ''; }).filter(Boolean).join(' · ')}</small>
          <button type="button" class="im-ico" data-viewroom title="${esc(T.look)}">${ICON.eye}</button>${o.canEdit && r.name && r.files.some((m) => c.own.has(m.id)) ? `<button type="button" class="im-ico" data-renroom title="${esc(T.renRoom)}">${ICON.edit}</button>` : ''}</div>
        <div class="im-room-b">${r.files.slice().sort((a, b) => (a.k === 'pano' ? 0 : 1) - (b.k === 'pano' ? 0 : 1)).map((m) => thumb(c, m, '')).join('')}${r.name ? addBtn('pano', '+ 360°') + addBtn('photo', '+ ' + T.kindsShort.photo) : ''}</div>
      </div>`).join('')}
      ${o.canEdit ? `<button type="button" class="im-addroom" data-addroom>+ ${esc(T.addRoom)}</button>` : ''}
      ${c.list.length ? `<button type="button" class="btn btn-s btn-p" data-view>${ICON.eye}${esc(T.look)}</button>` : ''}
    </div>`;
  }
  function render() {
    const cs = cards();
    box.innerHTML = cs.map((c, i) => (c.apt ? cardHtml(c) + (cs.length > 1 ? `<div class="im-sec-h"><b>${esc(T.layoutsH)}</b><small>${esc(T.layoutsHint)}</small></div>` : '') : cardHtml(c))).join('') || `<div class="im-empty">${esc(T.noLayouts)}</div>`;
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
  async function uploadFile(f, kind, c, extraF = {}) {
    const CH = 5 * 1024 * 1024, total = Math.max(1, Math.ceil(f.size / CH)), id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
    const u = c.u;
    let res = null;
    for (let i = 0; i < total; i++) {
      const fd = new FormData();
      fd.append('_token', o.csrf); fd.append('upload', id); fd.append('index', i); fd.append('total', total); fd.append('name', f.name); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'part');
      fd.append('kind', kind); fd.append('title', extraF.title || '');
      if (u.t) fd.append('layout', u.t); else fd.append('rooms', u.r || 0);
      if (c.apt) fd.append('units', c.group.join(','));   // только эта квартира (и отмеченные такими же)
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
  /** Загрузить файлы: 3D (не GLB — сначала перевод в GLB в браузере), чертёж; в комнату — 360° (проверка 2:1) и фото */
  async function upKind(files, kind, c, room) {
    const u = c.u, items = [];
    cur = c.key;
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
        const id = await uploadFile(f, k, c, ex);
        if (id) inner.push({ id, k, l: u.t || undefined, r: u.r, n: fromName(f), room: ex.room || undefined, ext: (f.name.split('.').pop() || '').toLowerCase(), u: c.apt ? c.group.slice() : undefined, p: 1 });
      }
      if (room) { const ex = extra.get(c.key) || []; extra.set(c.key, ex.filter((x) => !same(x, room))); }
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
  /** Открыть окно с формой: onOk(form) — по «Сохранить»; «Отмена», Esc и щелчок мимо — просто закрыть */
  function dialog(cls, html, onOk) {
    const dlg = document.createElement('dialog'); dlg.className = cls;
    dlg.innerHTML = html;
    document.body.append(dlg);
    let done = false;
    const finish = (ok) => {
      if (done) return;
      done = true;
      const form = dlg.querySelector('form');
      if (dlg.open) dlg.close();
      dlg.remove();
      if (ok) onOk(form);
    };
    dlg.querySelector('form').addEventListener('submit', (e) => { e.preventDefault(); finish(true); });
    dlg.querySelector('[value=x]').addEventListener('click', () => finish(false));
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); finish(false); });
    dlg.addEventListener('close', () => finish(false));
    dlg.showModal();
    return dlg;
  }
  /** Поправить файл: название, комната (для 360° и фото), для кого он: эта квартира — или планировка (перенести к другой) */
  function edit(m, c) {
    // варианты: квартира (если файл её), планировки, «для всех»; текущая привязка есть всегда — иначе сохранение молча перенесло бы файл
    const mineApt = c.apt && unitsOfFile(m).includes(String(c.apt.id));
    const curKey = mineApt ? 'apt' : m.l ? 't:' + m.l : m.r == null ? 'all' : 'r:' + m.r;
    const opts = (mineApt ? [['apt', aptName(c.apt) + (c.group.length > 1 ? ' + ' + (c.group.length - 1) : '') + ' — ' + T.onlyApt]] : []).concat(layouts().map((l) => [l.key, l.name]));
    if (!mineApt && curKey !== 'all' && !opts.some(([k]) => k === curKey)) opts.push([curKey, m.l ? m.l : `${m.r}${T.rooms}`]);
    opts.push(['all', T.forAll]);
    const roomy = m.k === 'pano' || m.k === 'photo', names = roomsOf(c).map((r) => r.name).filter(Boolean);
    dialog('im-dlg', `<form method="dialog"><h3>${esc(T.editFile)}</h3>
      <label class="field"><span>${esc(T.fileName)}</span><input name="title" maxlength="160" value="${esc(m.n || '')}" required></label>
      ${roomy ? `<label class="field"><span>${esc(T.roomName)}</span><input name="room" maxlength="60" list="imRoomList" value="${esc(m.room || '')}"><datalist id="imRoomList">${names.map((n) => `<option value="${esc(n)}">`).join('')}</datalist></label>` : ''}
      <label class="field"><span>${esc(T.forLayout)}</span><select name="lay">${opts.map(([k, n]) => `<option value="${esc(k)}"${k === curKey ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>
      <div class="im-dlg-b"><button type="button" class="btn" value="x">${esc(T.cancel)}</button><button class="btn btn-p" value="ok">${esc(T.save)}</button></div></form>`, async (f) => {
      const E = f.elements, v = { title: E.title.value.trim(), room: E.room ? E.room.value.trim() : undefined, lay: E.lay.value };
      const body = { title: v.title };
      if (v.room !== undefined) body.room = v.room;
      if (v.lay !== curKey) {
        if (mineApt) body.units = [];   // из квартиры — к планировке: снова у всех её квартир
        if (v.lay.startsWith('t:')) body.layout = v.lay.slice(2);
        else { body.layout = ''; body.rooms = v.lay === 'all' ? null : +v.lay.slice(2) || 0; }
      }
      try {
        const j = await post(o.urls.patch.replace('{id}', m.id), body);
        Object.assign(m, { n: j.m.n, room: j.m.room || undefined, l: j.m.l || undefined, r: j.m.r ?? undefined, u: j.m.u || undefined });
        render(); o.onChange && o.onChange();
      } catch (e) { alert(e.message); }
    });
  }
  async function del(m) {
    if (!confirm(T.delFileQ.replace(':f', m.n || T.kinds[m.k]))) return;
    try {
      await post(o.urls.del.replace('{id}', m.id), null, 'DELETE');
      inner.splice(inner.indexOf(m), 1);
      render(); o.onChange && o.onChange();
    } catch (e) { alert(e.message); }
  }
  /** Переименовать комнату — у её 360° и фото в этой карточке (у квартиры — только её собственные) */
  async function renameRoom(c, name) {
    const to = (prompt(T.renRoomQ, name) || '').trim();
    if (!to || to === name) return;
    const files = c.list.filter((m) => c.own.has(m.id) && (m.k === 'pano' || m.k === 'photo') && same(roomOf(m), name));
    try {
      for (const m of files) { const j = await post(o.urls.patch.replace('{id}', m.id), { room: to }); m.room = j.m.room || undefined; }
    } catch (e) { alert(e.message); }
    render(); o.onChange && o.onChange();
  }
  /** «Такие же квартиры»: отметить одинаковые — интерьер этой квартиры покажется и у них */
  function pickSame(c) {
    const a = c.apt, chosen = new Set(c.group.filter((x) => x !== String(a.id)));
    const all = flats().filter((u) => String(u.id) !== String(a.id));
    const stack = all.filter((u) => u.b === a.b && u.e === a.e && u.i === a.i && u.n === a.n);   // над и под ней: то же место на этаже
    const typed = all.filter((u) => (a.t ? u.t === a.t : +u.r === +a.r && Math.abs((+u.a || 0) - (+a.a || 0)) < 0.6));
    // шахматка: подъезд → этажи сверху вниз → квартиры слева направо
    const groups = new Map();
    all.concat([a]).forEach((u) => { const k = `${u.b}|${u.e}`; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(u); });
    const grid = [...groups.values()].map((list) => {
      const floors = [...new Set(list.map((u) => u.f))].sort((x, y) => y - x);
      return `<div class="im-ss">${floors.map((f) => `<div class="im-sr"><i>${f}</i>${list.filter((u) => u.f === f).sort((x, y) => x.i - y.i).map((u) => String(u.id) === String(a.id)
        ? `<span class="im-sc me" title="${esc(T.thisApt)}">${esc(u.num)}</span>`
        : `<button type="button" class="im-sc${chosen.has(String(u.id)) ? ' on' : ''}${(a.t ? u.t === a.t : +u.r === +a.r) ? '' : ' other'}" data-u="${esc(u.id)}" title="${esc(u.r ? u.r + T.rooms : '')}${u.a ? ' · ' + esc(u.a) + ' ' + esc(T.m2) : ''}">${esc(u.num)}</button>`).join('')}</div>`).join('')}</div>`;
    }).join('');
    const dlg = dialog('im-dlg im-same', `<form method="dialog"><h3>${esc(T.sameTitle.replace(':a', aptName(a)))}</h3>
      <p class="im-same-hint">${esc(T.sameHint)}</p>
      <div class="im-same-q"><button type="button" class="btn btn-s" data-q="stack">${esc(T.sameStack)} (${stack.length})</button><button type="button" class="btn btn-s" data-q="type">${esc(T.sameType)} (${typed.length})</button><button type="button" class="btn btn-s" data-q="none">${esc(T.sameNone)}</button></div>
      <div class="im-same-grid">${grid}</div>
      <div class="im-dlg-b"><small class="im-same-n"></small><button type="button" class="btn" value="x">${esc(T.cancel)}</button><button class="btn btn-p" value="ok">${esc(T.save)}</button></div></form>`, async () => {
      try {
        const j = await post(o.urls.same, { apt: a.id, same: [...chosen] });
        inner.forEach((m) => { if (j.files.map(String).includes(String(m.id))) m.u = j.units.slice(); });
        render(); o.onChange && o.onChange();
      } catch (e) { alert(e.message); }
    });
    const sync = () => { dlg.querySelectorAll('[data-u]').forEach((b) => b.classList.toggle('on', chosen.has(b.dataset.u))); dlg.querySelector('.im-same-n').textContent = T.sameN.replace(':n', chosen.size); };
    dlg.addEventListener('click', (e) => {
      const b = e.target.closest('[data-u]');
      if (b) { chosen.has(b.dataset.u) ? chosen.delete(b.dataset.u) : chosen.add(b.dataset.u); sync(); return; }
      const q = e.target.closest('[data-q]');
      if (!q) return;
      if (q.dataset.q === 'none') chosen.clear();
      else (q.dataset.q === 'stack' ? stack : typed).forEach((u) => chosen.add(String(u.id)));
      sync();
    });
    sync();
  }
  box.addEventListener('click', (e) => {
    const el = e.target.closest('[data-lay]');
    if (!el) return;
    const c = cards().find((x) => x.key === el.dataset.lay);
    if (!c) return;
    const roomEl = e.target.closest('[data-room]'), room = roomEl ? roomEl.dataset.room : null;
    const th = e.target.closest('[data-m]'), m = th && inner.find((x) => String(x.id) === th.dataset.m);
    if (m && e.target.closest('[data-ed]')) return edit(m, c);
    if (m && e.target.closest('[data-del]')) return del(m);
    if (m) return o.onView && o.onView(c.u, m);
    if (e.target.closest('[data-same]')) return pickSame(c);
    if (e.target.closest('[data-viewroom]')) return o.onView && o.onView(c.u, null, room);
    if (e.target.closest('[data-renroom]')) return renameRoom(c, room);
    if (e.target.closest('[data-view]')) return o.onView && o.onView(c.u);
    if (e.target.closest('[data-addroom]')) {
      const n = (prompt(T.addRoomQ, '') || '').trim();
      if (!n) return;
      extra.set(c.key, (extra.get(c.key) || []).concat([n]));
      cur = c.key; render();
      return;
    }
    const up = e.target.closest('[data-up]');
    if (!up) return;
    upFor = { c, kind: up.dataset.up, room };
    picker.accept = { interior: '.glb,.gltf,.bin,.fbx,.obj,.mtl,.dae,.3ds,.stl,.ply,.3mf,.ifc,.zip,.jpg,.jpeg,.png,.tga,.bmp', pano: '.jpg,.jpeg,.png,.webp', photo: '.jpg,.jpeg,.png,.webp', plan: '.pdf,.jpg,.jpeg,.png,.webp' }[upFor.kind];
    picker.multiple = upFor.kind !== 'plan';
    picker.click();
  });
  picker.addEventListener('change', () => { const files = [...picker.files]; picker.value = ''; if (files.length && upFor) upKind(files, upFor.kind, upFor.c, upFor.room); });

  return {
    render, layouts, files: () => inner,
    setCur(k) { cur = k; },
    /** Выбрать квартиру: её карточка — первой, загрузка в неё идёт только ей (и отмеченным такими же) */
    setApt(u) { apt = u || null; cur = u ? 'apt:' + u.id : cur; },
  };
}
