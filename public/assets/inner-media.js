/* Интерьеры квартир по планировкам: 3D-интерьер, панорамы 360° комнат, рендеры и план.
   Загрузить, переименовать, перенести к другой планировке, удалить — прямо там, где их смотрят:
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

/**
 * box — куда рисовать карточки планировок. o: { T, ICON, inner (массив файлов, меняется на месте), units ([{k, r, a, t, tn}]),
 * urls: { up, file, patch, del } ({id} — номер файла), csrf, cvt, mcUrl, canEdit, roomInput, prog, onView(unit, file), onChange() }
 */
export function innerManager(box, o) {
  const T = o.T, ICON = o.ICON, inner = o.inner;
  let cur = null, upFor = null;
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
  const thumb = (m) => {
    const img = m.k === 'photo' || (m.k === 'plan' && m.ext !== 'pdf');
    return `<div class="im-th" data-m="${m.id}" title="${esc(m.n)}"${img ? ` style="background-image:url('${esc(fileUrl(m))}')"` : ''}>
      ${img ? '' : ICON[m.k] || ''}<span>${esc(m.room || T.kinds[m.k] || m.k)}</span>
      ${o.canEdit ? `<i class="im-act"><button type="button" data-ed title="${esc(T.edit)}">${ICON.edit}</button><button type="button" data-del title="${esc(T.del)}">${ICON.del}</button></i>` : ''}</div>`;
  };
  function render() {
    const KINDS = [['interior', ICON.box], ['pano', ICON.compass], ['photo', ICON.image], ['plan', ICON.ruler]];
    box.innerHTML = layouts().map((l) => {
      const list = innerOf(inner, l.u);
      return `<div class="im-lay${cur === l.key ? ' cur' : ''}" data-lay="${esc(l.key)}">
        <div class="im-lay-h"><b>${esc(l.name)}</b><small>${l.n} ${esc(T.flats)}${l.u.a ? ' · ' + esc(l.u.a) + ' ' + esc(T.m2) : ''}</small></div>
        ${o.canEdit ? `<div class="im-up">${KINDS.map(([k, ic]) => { const c = list.filter((m) => m.k === k).length; return `<button type="button" data-up="${k}" class="${c ? 'has' : ''}" title="${esc(T.upTitle[k])}">${ic}<span>${esc(T.kindsShort[k])}${c ? ' · ' + c : ' +'}</span></button>`; }).join('')}</div>` : ''}
        ${list.length ? `<div class="im-list">${list.map(thumb).join('')}</div><button type="button" class="btn btn-s btn-p" data-view>${ICON.eye}${esc(T.look)}</button>`
          : `<div class="im-empty">${esc(o.canEdit ? T.noInnerLay : T.noInnerView)}</div>`}
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
  /** Загрузка частями по 5 МБ — тот же путь, что «3D, панорамы, планировки» на странице объекта */
  async function uploadFile(f, kind, u, extra = {}) {
    const CH = 5 * 1024 * 1024, total = Math.max(1, Math.ceil(f.size / CH)), id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
    let res = null;
    for (let i = 0; i < total; i++) {
      const fd = new FormData();
      fd.append('_token', o.csrf); fd.append('upload', id); fd.append('index', i); fd.append('total', total); fd.append('name', f.name); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'part');
      fd.append('kind', kind); fd.append('title', '');
      if (u.t) fd.append('layout', u.t); else fd.append('rooms', u.r || 0);
      if (kind === 'pano' && extra.room) fd.append('room', extra.room);
      if (extra.src) fd.append('src', extra.src);
      let r, tries = 0;
      do { r = await fetch(o.urls.up, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).catch(() => null); } while ((!r || r.status >= 500) && ++tries < 3);
      if (!r || !r.ok) { let m = T.upFail; try { const j = await r.json(); m = j.error || j.message || m; } catch (e) { /* без текста */ } throw new Error(m); }
      prog((i + 1) / total, `${T.upBusy} ${f.name}`);
      if (i === total - 1) res = await r.json().catch(() => null);
    }
    return res && res.id;
  }
  /** Загрузить файлы одного вида для планировки: 3D (не GLB — сначала перевод в GLB в браузере), 360° (проверка 2:1), фото, план */
  async function upKind(files, kind, lay) {
    const u = lay.u, items = [];
    cur = lay.key;
    try {
      // в «3D» выбрали только картинки — это не модель: рендеры загрузим как фото, снимки 2:1 — как панорамы 360°
      if (kind === 'interior' && files.every((f) => /\.(jpe?g|png|webp)$/i.test(f.name))) {
        if (!confirm(T.imgsNot3d)) return;
        for (const f of files) {
          const r = await ratioOf(f), pano = r >= 1.9 && r <= 2.1;
          items.push([f, pano ? 'pano' : 'photo', pano ? { room: f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim() } : {}]);
        }
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
        const typed = o.roomInput && o.roomInput.value.trim();
        items.push([f, k, { room: (typed && files.length === 1 ? typed : '') || f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim() }]);
      }
      for (const [f, k, extra] of items) {
        prog(0, `${T.upBusy} ${f.name}`);
        const id = await uploadFile(f, k, u, extra);
        if (id) inner.push({ id, k, l: u.t || undefined, r: u.r, n: f.name.replace(/\.[^.]+$/, ''), room: k === 'pano' ? extra.room : undefined, ext: (f.name.split('.').pop() || '').toLowerCase(), p: 1 });
      }
      prog(1, T.upDone);
      setTimeout(() => prog(false), 2500);
      if (o.roomInput) o.roomInput.value = '';
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
  /** Поправить файл: название, комната панорамы, планировка (перенести к другой) */
  function edit(m) {
    // варианты: планировки объекта, «для всех»; текущая привязка файла есть всегда — иначе сохранение молча перенесло бы его
    const curKey = m.l ? 't:' + m.l : m.r == null ? 'all' : 'r:' + m.r;
    const opts = layouts().map((l) => [l.key, l.name]);
    if (curKey !== 'all' && !opts.some(([k]) => k === curKey)) opts.push([curKey, m.l ? m.l : `${m.r}${T.rooms}`]);
    opts.push(['all', T.forAll]);
    const dlg = document.createElement('dialog'); dlg.className = 'im-dlg';
    dlg.innerHTML = `<form method="dialog"><h3>${esc(T.editFile)}</h3>
      <label class="field"><span>${esc(T.fileName)}</span><input name="title" maxlength="160" value="${esc(m.n || '')}" required></label>
      ${m.k === 'pano' ? `<label class="field"><span>${esc(T.roomName)}</span><input name="room" maxlength="60" value="${esc(m.room || '')}"></label>` : ''}
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
      if (v.lay.startsWith('t:')) body.layout = v.lay.slice(2);
      else { body.layout = ''; body.rooms = v.lay === 'all' ? null : +v.lay.slice(2) || 0; }
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
  box.addEventListener('click', (e) => {
    const card = e.target.closest('[data-lay]');
    if (!card) return;
    const lay = layouts().find((x) => x.key === card.dataset.lay);
    if (!lay) return;
    const th = e.target.closest('[data-m]'), m = th && inner.find((x) => String(x.id) === th.dataset.m);
    if (m && e.target.closest('[data-ed]')) return edit(m);
    if (m && e.target.closest('[data-del]')) return del(m);
    if (m) return o.onView && o.onView(lay.u, m);
    if (e.target.closest('[data-view]')) return o.onView && o.onView(lay.u);
    const up = e.target.closest('[data-up]');
    if (!up) return;
    upFor = { lay, kind: up.dataset.up };
    picker.accept = { interior: '.glb,.gltf,.bin,.fbx,.obj,.mtl,.dae,.3ds,.stl,.ply,.3mf,.ifc,.zip,.jpg,.jpeg,.png,.tga,.bmp', pano: '.jpg,.jpeg,.png,.webp', photo: '.jpg,.jpeg,.png,.webp', plan: '.pdf,.jpg,.jpeg,.png,.webp' }[upFor.kind];
    picker.multiple = upFor.kind !== 'plan';
    picker.click();
  });
  picker.addEventListener('change', () => { const files = [...picker.files]; picker.value = ''; if (files.length && upFor) upKind(files, upFor.kind, upFor.lay); });

  return { render, layouts, setCur(k) { cur = k; }, files: () => inner };
}
