/* Oftob — перечень помещений объекта: корпуса → подъезды → этажи → квартиры, планировки, цены, готовность к продаже.
   Данные приходят в window.INV (см. company/objects/units.blade.php), сохраняются целиком одним запросом.
   Сделки и оплаты здесь не меняются: квартиры с бронью или продажей нельзя удалить, их цена закрепляется. */
(() => {
  'use strict';
  const C = window.INV, T = C.T, app = document.getElementById('inv-app');
  if (!C || !app) return;

  /* ---------- состояние ---------- */
  let inv = C.data && C.data.units && C.data.units.length ? JSON.parse(JSON.stringify(C.data)) : null;
  let rev = C.rev, dirty = false, view = new URLSearchParams(location.search).get('v') || 'grid';
  let cur = inv ? (inv.blocks[0] || {}).id : null;
  let sel = new Set(), multi = false, anchor = null;
  const st = {};                      // «в продаже / не в продаже», изменённые здесь
  const status = Object.assign({}, C.status);
  const deals = C.deals || {};

  /* ---------- помощники ---------- */
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const rid = p => p + Math.random().toString(36).slice(2, 9);
  const num = (v, d = 0) => { const n = parseFloat(String(v ?? '').replace(',', '.').replace(/\s/g, '')); return Number.isFinite(n) ? n : d; };
  const fmt = (n, d = 0) => Number(n || 0).toLocaleString('ru-RU', { maximumFractionDigits: d, minimumFractionDigits: 0 });
  const money = n => fmt(Math.round(n)) + ' ' + C.cur;
  // коротко для клеток шахматки: 1,03 млрд · 668 млн · 85,5 млн
  const short = n => { n = +n || 0; const a = Math.abs(n);
    return a >= 1e9 ? fmt(n / 1e9, 2) + ' ' + T.bn : a >= 1e8 ? fmt(n / 1e6, 0) + ' ' + T.mn : a >= 1e6 ? fmt(n / 1e6, 1) + ' ' + T.mn : a >= 1e3 ? fmt(n / 1e3, 0) + ' ' + T.th : fmt(n); };
  const blockOf = id => inv.blocks.find(b => b.id === id);
  const typeOf = id => inv.types.find(t => t.id === id);
  const unitsOf = (b, e) => inv.units.filter(u => u.b === b && (e === undefined || u.e === e));
  const stOf = u => deals[u.id] ? deals[u.id].st : (st[u.id] || status[u.id] || 'free');
  const isFlat = u => (u.kind || 'flat') === 'flat';
  const kindShort = k => T['k_' + k] || k;
  const mark = () => { dirty = true; paintSave(); };
  const toast = (t, bad) => { let el = document.getElementById('inv-toast'); if (!el) { el = document.createElement('div'); el.id = 'inv-toast'; el.className = 'inv-toast'; document.body.append(el); }
    el.textContent = t; el.classList.toggle('bad', !!bad); el.classList.add('on'); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove('on'), 2600); };

  /* цена по правилу — так же, как на сервере (App\Services\Inventory::basePrice) */
  function rule() { const r = inv.price || {}; return { ppm2: num(r.ppm2), step: num(r.step), from: r.from ?? 2, commercial: num(r.commercial), storage: num(r.storage), parking: num(r.parking), round: num(r.round, 1000) || 1000 }; }
  function autoPrice(u) {
    const R = rule(), a = num(u.area), b = blockOf(u.b) || {};
    let v;
    switch (u.kind || 'flat') {
      case 'commercial': case 'office': v = a * R.commercial; break;
      case 'storage': v = a * R.storage; break;
      case 'parking': v = R.parking; break;
      default: v = a * (num(b.ppm2) || R.ppm2) * (1 + Math.max(0, (u.f ?? 1) - R.from) * R.step / 100);
    }
    return Math.round(v / R.round) * R.round;
  }
  const priceOf = u => deals[u.id] ? deals[u.id].price : num(u.price) > 0 ? num(u.price) : (C.over[u.id] || autoPrice(u));

  /* ---------- сохранение ---------- */
  async function post(url, body) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': C.csrf }, body: JSON.stringify(body || {}) }).catch(() => null);
    if (!r) throw new Error(T.net);
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.error || j.message || T.err);
    return j;
  }
  async function save() {
    if (!inv || !dirty) return;
    const b = document.getElementById('inv-save'); if (b) { b.disabled = true; b.textContent = T.saving; }
    try {
      const j = await post(C.urls.save, { inv, rev, st });
      rev = j.rev; dirty = false; Object.assign(status, st); for (const k in st) delete st[k];
      toast(T.saved);
      if (j.dups && j.dups.length) toast(T.dups + ': ' + j.dups.slice(0, 8).join(', '), true);
      C.undo = true;
    } catch (e) { await ui.alert(e.message); }
    render();
  }
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- шапка: статус, итоги, кнопки ---------- */
  function totals() {
    const by = { flat: 0, commercial: 0, office: 0, parking: 0, storage: 0 };
    let area = 0, sum = 0, free = 0;
    for (const u of inv.units) { by[u.kind || 'flat']++; area += num(u.area); sum += priceOf(u); if (stOf(u) === 'free') free++; }
    return { by, area, sum, free };
  }
  function head() {
    const t = totals(), ready = C.ready;
    const parts = Object.entries(t.by).filter(([, n]) => n).map(([k, n]) => `${C.kinds[k] || k}: <b>${n}</b>`).join(' · ');
    return `<div class="card inv-head">
      <div class="inv-head-l">
        <div class="inv-title">${ready ? `<span class="badge b-ok">${T.onSale} ${esc(String(ready).split('-').reverse().join('.'))}</span>` : `<span class="badge b-warn">${T.draft}</span>`}
          <span class="muted">${T.blocks}: <b>${inv.blocks.length}</b> · ${T.entrances}: <b>${inv.blocks.reduce((s, b) => s + b.entr.length, 0)}</b> · ${parts}</span></div>
        <div class="muted inv-sub">${T.area}: <b>${fmt(t.area, 1)} ${T.m2}</b> · ${T.free}: <b>${t.free}</b> · ${T.value}: <b>${short(t.sum)} ${C.cur}</b></div>
      </div>
      <div class="inv-head-r">
        <span id="inv-dirty" class="muted" ${dirty ? '' : 'hidden'}>${T.unsaved}</span>
        ${C.undo ? `<button class="btn btn-s btn-ghost" data-a="undo" title="${T.undoHint}">↶ ${T.undo}</button>` : ''}
        <button class="btn btn-s ${ready ? '' : 'btn-ghost'}" data-a="view" data-v="ready">${ready ? T.toDraft : T.checkReady}</button>
        <button class="btn btn-p" id="inv-save" data-a="save" ${dirty ? '' : 'disabled'}>${T.save}</button>
      </div>
    </div>
    <div class="tabs inv-tabs">${[['grid', T.tGrid], ['types', T.tTypes + ` <span class="badge plain">${inv.types.length}</span>`], ['prices', T.tPrices], ['ready', T.tReady]]
      .map(([k, n]) => `<a href="#" data-a="view" data-v="${k}" class="${view === k ? 'on' : ''}">${n}</a>`).join('')}</div>`;
  }
  function paintSave() { const b = document.getElementById('inv-save'); if (b) b.disabled = !dirty; const d = document.getElementById('inv-dirty'); if (d) d.hidden = !dirty; }

  /* ---------- пусто: завести перечень ---------- */
  function empty() {
    const g = C.generated;
    return `<div class="grid g2 inv-start">
      <div class="card"><div class="card-h"><h3>${T.fromBuild}</h3></div>
        <p class="muted">${T.fromBuildHint.replace(':n', `<b>${g}</b>`)}</p>
        ${C.genDeals ? `<p class="badge b-info plain">${T.dealsKept.replace(':n', C.genDeals)}</p>` : ''}
        <button class="btn btn-p" data-a="convert" ${g ? '' : 'disabled'}>${T.fromBuildBtn}</button></div>
      <div class="card"><div class="card-h"><h3>${T.fromScratch}</h3></div>
        <p class="muted">${T.fromScratchHint}</p>
        ${C.genDeals ? `<p class="badge b-warn plain">${T.scratchDeals}</p>` : ''}
        <button class="btn" data-a="wizard" ${C.genDeals ? 'disabled' : ''}>${T.wizardBtn}</button></div>
    </div>`;
  }

  /* ---------- шахматка ---------- */
  function floorsOf(b, e) {
    const us = unitsOf(b.id, e.id), fs = us.map(u => u.f);
    const top = Math.max(b.floors || 1, ...fs, 1), bottom = Math.min(1, ...fs);
    const out = []; for (let f = top; f >= bottom; f--) if (f !== 0 || fs.includes(0)) out.push(f);
    return out;
  }
  function cell(u) {
    const s = stOf(u), t = typeOf(u.type), on = sel.has(u.id);
    const lbl = isFlat(u) ? `${u.rooms || (t && t.rooms) || 1}${T.r} · ${fmt(u.area, 1)}` : `${kindShort(u.kind)} · ${fmt(u.area, 1)}`;
    const warn = isFlat(u) && !u.type ? ' notype' : '';
    return `<button type="button" class="inv-u st-${s}${on ? ' sel' : ''}${warn}${num(u.price) > 0 ? ' manual' : ''}" data-u="${u.id}" title="${esc(t ? t.name : '')}${deals[u.id] && deals[u.id].buyer ? ' · ' + esc(deals[u.id].buyer) : ''}">
      <b>${esc(u.num)}</b><small>${lbl}</small><i>${short(priceOf(u))}</i></button>`;
  }
  function grid() {
    if (!blockOf(cur)) cur = inv.blocks[0].id;
    const b = blockOf(cur);
    const chips = inv.blocks.map(x => `<a href="#" class="chip ${x.id === cur ? 'on' : ''}" data-a="block" data-b="${x.id}">${esc(x.name || T.block1)}<span class="n">${unitsOf(x.id).length}</span></a>`).join('');
    const ents = b.entr.map(e => {
      const fl = floorsOf(b, e), us = unitsOf(b.id, e.id);
      return `<div class="inv-ent">
        <div class="inv-ent-h"><a href="#" data-a="selEnt" data-e="${e.id}"><b>${T.entrance} ${esc(e.name)}</b></a><span class="muted">${us.filter(u => stOf(u) === 'free').length}/${us.length}</span></div>
        ${fl.map(f => { const row = us.filter(u => u.f === f);
          return `<div class="inv-floor"><a href="#" class="fl" data-a="selFloor" data-e="${e.id}" data-f="${f}" title="${T.selFloor}">${f}</a>${row.map(cell).join('')}<button type="button" class="inv-add" data-a="addUnit" data-e="${e.id}" data-f="${f}" title="${T.addUnit}">+</button></div>`; }).join('')}
      </div>`;
    }).join('');
    return `<div class="inv-grid">
      <div class="card inv-board">
        <div class="toolbar inv-bar"><div class="chips">${chips}<a href="#" class="chip" data-a="wizard">+ ${T.block}</a></div><span class="grow"></span>
          <button class="btn btn-s" data-a="blockEdit">${T.blockSet}</button>
          <button class="btn btn-s" data-a="wizard" data-b="${b.id}">${T.fill}</button>
          <button class="btn btn-s" data-a="renumBlock">${T.renumBlock}</button>
          ${inv.blocks.length > 1 ? `<button class="btn btn-s btn-ghost btn-d" data-a="blockDel" title="${T.blockDel}">✕</button>` : ''}
          <label class="switch" title="${T.multiHint}"><input type="checkbox" data-a="multi" ${multi ? 'checked' : ''}> ${T.multi}</label></div>
        <div class="chess-legend flex inv-legend"><span><i class="st-free"></i>${T.s_free}</span><span><i class="st-reserve"></i>${T.s_reserve}</span><span><i class="st-booked"></i>${T.s_booked}</span><span><i class="st-sold"></i>${T.s_sold}</span><span class="muted">${T.legendHint}</span></div>
        <div class="inv-ents">${ents}</div>
      </div>
      <div class="card inv-side" id="inv-side">${side()}</div>
    </div>`;
  }

  /* ---------- боковая панель: одна квартира или выбранные ---------- */
  const typeOpts = (v, kind) => `<option value="">— ${T.noType} —</option>` + inv.types.filter(t => !kind || (t.kind || 'flat') === kind).map(t => `<option value="${t.id}" ${t.id === v ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
  const kindOpts = v => Object.entries(C.kinds).map(([k, n]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${esc(n)}</option>`).join('');
  function side() {
    const list = inv.units.filter(u => sel.has(u.id));
    if (!list.length) return `<div class="card-h"><h3>${T.howTo}</h3></div><ol class="steps-list inv-help"><li>${T.help1}</li><li>${T.help2}</li><li>${T.help3}</li><li>${T.help4}</li></ol>`;
    if (list.length === 1) {
      const u = list[0], d = deals[u.id], s = stOf(u), auto = autoPrice(u);
      return `<div class="card-h"><h3>${isFlat(u) ? T.flat : C.kinds[u.kind]} № ${esc(u.num)}</h3><span class="badge st-b-${s}">${T['s_' + s]}</span></div>
      ${d ? `<div class="alert alert-info inv-deal">${T.dealLock.replace(':b', esc(d.buyer || '—'))}</div>` : ''}
      <div class="form inv-form">
        <label class="field"><span>${T.number}</span><input data-f="num" value="${esc(u.num)}" maxlength="12"></label>
        <label class="field"><span>${T.kind}</span><select data-f="kind">${kindOpts(u.kind || 'flat')}</select></label>
        <label class="field full"><span>${T.layout}</span><select data-f="type">${typeOpts(u.type, u.kind || 'flat')}</select></label>
        ${isFlat(u) ? `<label class="field"><span>${T.rooms}</span><input data-f="rooms" type="number" min="1" max="9" value="${u.rooms || 1}"></label>` : ''}
        <label class="field"><span>${T.area}, ${T.m2}</span><input data-f="area" type="number" step="0.01" min="0" value="${u.area}"></label>
        <label class="field"><span>${T.floor}</span><input data-f="f" type="number" min="-10" max="150" value="${u.f}"></label>
        <label class="field full"><span>${T.price}, ${C.cur} <small class="muted">${T.auto}: ${money(auto)}</small></span>
          <input data-f="price" type="number" min="0" step="1000" value="${num(u.price) > 0 ? u.price : ''}" placeholder="${fmt(auto)}" ${d ? 'disabled' : ''}></label>
        ${C.over[u.id] && !d ? `<small class="muted full">${T.overHint.replace(':p', money(C.over[u.id]))}</small>` : ''}
        <label class="field full"><span>${T.note}</span><input data-f="note" value="${esc(u.note || '')}" maxlength="120" placeholder="${T.notePh}"></label>
        <label class="switch full"><input type="checkbox" data-f="sale" ${s === 'reserve' ? '' : 'checked'} ${d ? 'disabled' : ''}> ${T.forSale}</label>
      </div>
      <div class="flex mt inv-acts"><button class="btn btn-s" data-a="move" data-d="-1">← ${T.moveL}</button><button class="btn btn-s" data-a="move" data-d="1">${T.moveR} →</button></div>
      <small class="muted">${T.moveHint}</small>
      <div class="flex mt inv-acts"><button class="btn btn-s" data-a="dup">${T.dup}</button><button class="btn btn-s btn-d" data-a="del" ${d ? 'disabled' : ''}>${T.del}</button></div>`;
    }
    const flats = list.filter(isFlat).length, withDeal = list.filter(u => deals[u.id]).length;
    return `<div class="card-h"><h3>${T.selected}: ${list.length}</h3><a href="#" data-a="clear" class="muted">${T.clear}</a></div>
      <p class="muted inv-sub">${T.flats}: ${flats} · ${T.other}: ${list.length - flats}${withDeal ? ` · <b>${T.withDeal}: ${withDeal}</b>` : ''}</p>
      <div class="form inv-bulk">
        <div class="field full"><span>${T.layout}</span><div class="flex"><select id="bk-type">${typeOpts('')}</select><button class="btn btn-s" data-a="bType">${T.apply}</button></div></div>
        <div class="field full"><span>${T.kind}</span><div class="flex"><select id="bk-kind">${kindOpts('flat')}</select><button class="btn btn-s" data-a="bKind">${T.apply}</button></div></div>
        <div class="field full"><span>${T.area}, ${T.m2}</span><div class="flex"><input id="bk-area" type="number" step="0.01" min="0"><button class="btn btn-s" data-a="bArea">${T.apply}</button></div></div>
        <div class="field full"><span>${T.ppm2Set}, ${C.cur}</span><div class="flex"><input id="bk-ppm2" type="number" min="0" step="1000"><button class="btn btn-s" data-a="bPpm2">${T.apply}</button></div>
          <a href="#" data-a="bAuto" class="muted" style="font-size:13px">${T.toAuto}</a></div>
        <div class="field full"><span>${T.renum}</span><div class="flex"><input id="bk-num" value="1" maxlength="10"><button class="btn btn-s" data-a="bNum">${T.apply}</button></div><small class="muted">${T.renumHint}</small></div>
        <div class="full flex"><button class="btn btn-s" data-a="bSale" data-on="1">${T.forSale}</button><button class="btn btn-s" data-a="bSale" data-on="0">${T.notForSale}</button></div>
        <div class="full"><button class="btn btn-s btn-d" data-a="bDel">${T.delSel}</button></div>
      </div>`;
  }

  /* ---------- планировки ---------- */
  function types() {
    const rows = inv.types.map(t => {
      const us = inv.units.filter(u => u.type === t.id), m = C.media[t.id] || {};
      const has = k => (m[k] || []).length;
      const link = (k, n) => `<a class="btn btn-s ${has(k) ? '' : 'btn-ghost'}" href="${C.urls.media}&kind=${k}&layout=${t.id}#upload-card" data-a="toMedia">${has(k) ? '✓ ' : '+ '}${n}${has(k) > 1 ? ' · ' + has(k) : ''}</a>`;
      return `<tr><td><b>${esc(t.name)}</b><br><small class="muted">${esc(C.kinds[t.kind || 'flat'])}${t.kind === 'flat' || !t.kind ? ` · ${t.rooms}${T.r}` : ''}${t.ceiling ? ` · ${T.ceil} ${t.ceiling} ${T.mm}` : ''}</small></td>
        <td class="n">${fmt(t.area, 2)}</td><td class="n">${t.kitchen ? fmt(t.kitchen, 1) : '—'}</td><td class="n">${t.living ? fmt(t.living, 1) : '—'}</td>
        <td class="n">${us.length}<br><small class="muted">${T.free}: ${us.filter(u => stOf(u) === 'free').length}</small></td>
        <td><div class="inv-media">${link('plan', T.plan)}${link('interior', '3D')}${link('pano', '360°')}</div></td>
        <td class="right nowrap"><button class="btn btn-s" data-a="typeEdit" data-t="${t.id}">${T.edit}</button> <button class="icon-btn btn-d" data-a="typeDel" data-t="${t.id}" title="${T.del}">✕</button></td></tr>`;
    }).join('');
    return `<div class="card"><div class="card-h"><h3>${T.tTypes}</h3><button class="btn btn-p btn-s" data-a="typeEdit">+ ${T.typeAdd}</button></div>
      <p class="muted inv-sub">${T.typesHint}</p>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>${T.name}</th><th class="n">${T.area}, ${T.m2}</th><th class="n">${T.kitchen}</th><th class="n">${T.living}</th><th class="n">${T.unitsN}</th><th>${T.files}</th><th></th></tr></thead>
      <tbody>${rows || `<tr><td colspan="7" class="muted">${T.noTypes}</td></tr>`}</tbody></table></div></div>`;
  }
  async function typeEdit(id) {
    const t = id ? typeOf(id) : { kind: 'flat', rooms: 2 };
    const v = await ui.form(id ? T.typeEdit : T.typeAdd, [
      { name: 'name', label: T.name, value: t.name || '', placeholder: T.typeNamePh, required: true },
      { name: 'kind', label: T.kind, type: 'select', value: t.kind || 'flat', options: Object.entries(C.kinds) },
      { name: 'rooms', label: T.rooms, type: 'number', value: t.rooms || 2, min: 1, max: 9, hint: T.roomsHint },
      { name: 'area', label: `${T.area}, ${T.m2}`, type: 'number', value: t.area || '', required: true },
      { name: 'kitchen', label: `${T.kitchen}, ${T.m2}`, type: 'number', value: t.kitchen || '' },
      { name: 'living', label: `${T.living}, ${T.m2}`, type: 'number', value: t.living || '' },
      { name: 'ceiling', label: `${T.ceil}, ${T.mm}`, type: 'number', value: t.ceiling || '' },
      { name: 'note', label: T.typeNote, type: 'textarea', value: t.note || '', placeholder: T.typeNotePh },
    ], { ok: T.ok });
    if (!v) return;
    const area = num(v.area);
    if (area <= 0) return ui.alert(T.needArea);
    const nt = { id: t.id || rid('t'), name: v.name.trim(), kind: v.kind, rooms: v.kind === 'flat' ? Math.max(1, Math.min(9, Math.round(num(v.rooms, 1)))) : 0, area,
      kitchen: num(v.kitchen) || undefined, living: num(v.living) || undefined, ceiling: num(v.ceiling) || undefined, note: v.note.trim() || undefined };
    if (id) {
      Object.assign(t, nt);
      const us = inv.units.filter(u => u.type === id);
      if (us.length && await ui.confirm(T.typeApply.replace(':n', us.length))) us.forEach(u => { if (!deals[u.id]) { u.area = nt.area; u.rooms = nt.rooms; u.kind = nt.kind; } });
    } else inv.types.push(nt);
    mark(); render();
  }
  async function typeDel(id) {
    const n = inv.units.filter(u => u.type === id).length;
    if (!await ui.confirm(n ? T.typeDelUsed.replace(':n', n) : T.typeDelQ)) return;
    inv.types = inv.types.filter(t => t.id !== id); inv.units.forEach(u => { if (u.type === id) delete u.type; });
    mark(); render();
  }

  /* ---------- цены ---------- */
  function prices() {
    const R = inv.price || (inv.price = {});
    const byType = inv.types.map(t => { const us = inv.units.filter(u => u.type === t.id); if (!us.length) return '';
      const ps = us.map(priceOf), ar = us.reduce((s, u) => s + num(u.area), 0), sum = ps.reduce((a, b) => a + b, 0);
      return `<tr><td>${esc(t.name)}</td><td class="n">${us.length}</td><td class="n">${short(Math.min(...ps))}</td><td class="n">${short(Math.max(...ps))}</td><td class="n">${money(ar ? sum / ar : 0)}</td></tr>`; }).join('');
    const f = (k, l, h, step = 1000) => `<label class="field"><span>${l}</span><input type="number" step="${step}" data-p="${k}" value="${R[k] ?? ''}">${h ? `<small>${h}</small>` : ''}</label>`;
    return `<div class="grid g2">
      <div class="card"><div class="card-h"><h3>${T.priceRule}</h3></div>
        <div class="form" style="grid-template-columns:1fr 1fr">
          ${f('ppm2', `${T.ppm2Flat}, ${C.cur}`)}${f('step', T.step, T.stepHint, 0.1)}${f('from', T.stepFrom, '', 1)}
          ${f('commercial', `${T.ppm2Com}, ${C.cur}`)}${f('storage', `${T.ppm2Sto}, ${C.cur}`)}${f('parking', `${T.parkPrice}, ${C.cur}`)}
          <label class="field"><span>${T.round}</span><select data-p="round">${[1, 1000, 10000, 100000, 1000000].map(x => `<option value="${x}" ${num(R.round, 1000) === x ? 'selected' : ''}>${fmt(x)}</option>`).join('')}</select></label>
        </div>
        <div class="field mt"><span>${T.blockPpm2}</span>${inv.blocks.map(b => `<div class="flex" style="margin-top:6px"><span style="min-width:120px">${esc(b.name || T.block1)}</span><input type="number" step="1000" data-bp="${b.id}" value="${b.ppm2 || ''}" placeholder="${T.asCommon}"></div>`).join('')}</div>
        <div class="flex mt"><input type="number" id="pr-pct" step="0.5" placeholder="+5" style="width:110px"><span class="muted">%</span>
          <label class="check"><input type="checkbox" id="pr-man"> ${T.alsoManual}</label><button class="btn btn-s" data-a="pct">${T.pctApply}</button></div>
        <small class="muted">${T.priceHint}</small></div>
      <div class="card"><div class="card-h"><h3>${T.byLayout}</h3></div>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>${T.layout}</th><th class="n">${T.unitsN}</th><th class="n">${T.from}</th><th class="n">${T.to}</th><th class="n">${T.avgM2}</th></tr></thead><tbody>${byType || `<tr><td colspan="5" class="muted">${T.noTypes}</td></tr>`}</tbody></table></div>
        <p class="muted inv-sub mt">${T.total}: <b>${money(totals().sum)}</b></p></div>
    </div>`;
  }

  /* ---------- готовность к продаже ---------- */
  function checks() {
    const flats = inv.units.filter(isFlat), noType = flats.filter(u => !u.type), zero = inv.units.filter(u => priceOf(u) <= 0);
    const used = [...new Set(inv.units.map(u => u.type).filter(Boolean))].map(typeOf).filter(Boolean);
    const noPlan = used.filter(t => !((C.media[t.id] || {}).plan || []).length), noVis = used.filter(t => !((C.media[t.id] || {}).interior || []).length && !((C.media[t.id] || {}).pano || []).length);
    return [
      { ok: inv.units.length > 0, req: true, t: T.c1.replace(':n', inv.units.length) },
      { ok: !noType.length, req: true, t: noType.length ? T.c2bad.replace(':n', noType.length) : T.c2, act: noType.length ? ['selIds', noType.map(u => u.id).join(',')] : null },
      { ok: !zero.length, req: true, t: zero.length ? T.c3bad.replace(':n', zero.length) : T.c3, act: zero.length ? ['selIds', zero.map(u => u.id).join(',')] : null },
      { ok: !noPlan.length, req: true, t: noPlan.length ? T.c4bad.replace(':l', noPlan.map(t => esc(t.name)).join(', ')) : T.c4, act: noPlan.length ? ['view', 'types'] : null },
      { ok: C.exterior > 0, req: false, t: C.exterior > 0 ? T.c5 : T.c5bad, link: C.urls.media + '&kind=exterior#upload-card' },
      { ok: !noVis.length, req: false, t: noVis.length ? T.c6bad.replace(':l', noVis.map(t => esc(t.name)).join(', ')) : T.c6, act: noVis.length ? ['view', 'types'] : null },
      { ok: C.public, req: false, t: C.public ? T.c7 : T.c7bad },
    ];
  }
  function ready() {
    const cs = checks(), blockers = cs.filter(c => c.req && !c.ok).length;
    return `<div class="card"><div class="card-h"><h3>${T.tReady}</h3>${C.ready ? `<span class="badge b-ok">${T.onSale} ${esc(String(C.ready).split('-').reverse().join('.'))}</span>` : `<span class="badge b-warn">${T.draft}</span>`}</div>
      <p class="muted inv-sub">${T.readyHint}</p>
      <div class="inv-checks">${cs.map(c => `<div class="inv-check ${c.ok ? 'ok' : c.req ? 'bad' : 'warn'}"><span class="ic-dot">${c.ok ? '✓' : c.req ? '✕' : '!'}</span><div>${c.t}${c.req ? '' : ` <small class="muted">(${T.recommended})</small>`}</div>
        ${c.act ? `<a href="#" class="btn btn-s" data-a="${c.act[0]}" data-v="${c.act[1]}" data-ids="${c.act[1]}">${T.fix}</a>` : c.link && !c.ok ? `<a class="btn btn-s" href="${c.link}">${T.upload}</a>` : ''}</div>`).join('')}</div>
      <div class="flex mt">${C.ready ? `<button class="btn" data-a="ready" data-on="0">${T.toDraftBtn}</button>`
        : `<button class="btn btn-p btn-l" data-a="ready" data-on="1" ${blockers ? 'disabled' : ''}>${T.goSale}</button>${blockers ? `<span class="muted">${T.fixFirst}</span>` : ''}`}</div></div>`;
  }

  /* ---------- мастер: заполнить корпус ---------- */
  function wizard(blockId) {
    const b = blockId ? blockOf(blockId) : null;
    const flatTypes = (inv ? inv.types : []).filter(t => (t.kind || 'flat') === 'flat');
    const dlg = document.createElement('dialog'); dlg.className = 'modal wide inv-wiz';
    const typeSel = v => `<select class="wz-t">${flatTypes.map(t => `<option value="${t.id}" ${t.id === v ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}<option value="+">+ ${T.newType}</option></select>`;
    const startTypes = flatTypes.length ? flatTypes.slice(0, 4).map(t => t.id) : [];
    dlg.innerHTML = `<div class="modal-h"><h3>${b ? T.fillBlock + ' «' + esc(b.name || T.block1) + '»' : T.newBlock}</h3><button type="button" class="icon-btn" data-x>✕</button></div>
    <div class="modal-b"><div class="form" style="grid-template-columns:1fr 1fr 1fr">
      ${b ? '' : `<label class="field"><span>${T.blockName}</span><input id="wz-name" value="${inv ? String.fromCharCode(65 + inv.blocks.length) : 'A'}" maxlength="40"><small>${T.blockNameHint}</small></label>`}
      <label class="field"><span>${T.entrN}</span><input id="wz-ent" type="number" min="1" max="40" value="${b ? b.entr.length : C.build.sections || 1}" ${b ? 'disabled' : ''}></label>
      <label class="field"><span>${T.floorsFrom}</span><input id="wz-f0" type="number" min="1" max="150" value="${C.build.commercialFirst ? 2 : 1}"></label>
      <label class="field"><span>${T.floorsTo}</span><input id="wz-f1" type="number" min="1" max="150" value="${b ? b.floors : C.build.floors || 9}"></label>
      <div class="field full"><span>${T.perFloor}</span><div id="wz-types" class="wz-types"></div>
        <div class="flex"><button type="button" class="btn btn-s" id="wz-addt">+ ${T.addPos}</button><small class="muted">${T.perFloorHint}</small></div>
        <div id="wz-quick" class="wz-quick" ${flatTypes.length ? 'hidden' : ''}><b>${T.quickTypes}</b><small class="muted">${T.quickTypesHint}</small>
          <div class="flex">${[1, 2, 3, 4].map(r => `<label class="field"><span>${r}${T.r}, ${T.m2}</span><input type="number" step="0.1" data-qt="${r}" value="${[0, 42, 62, 84, 105][r]}"></label>`).join('')}</div></div></div>
      <label class="field"><span>${T.firstFloor}</span><select id="wz-ff"><option value="com">${T.ffCom}</option><option value="flat">${T.ffFlat}</option><option value="none">${T.ffNone}</option></select></label>
      <label class="field"><span>${T.comN}</span><input id="wz-cn" type="number" min="0" max="20" value="1"></label>
      <label class="field"><span>${T.comArea}</span><input id="wz-ca" type="number" min="0" step="0.1" value="120"></label>
      <label class="field"><span>${T.parkN}</span><input id="wz-pn" type="number" min="0" max="400" value="0"></label>
      <label class="field"><span>${T.storN}</span><input id="wz-sn" type="number" min="0" max="400" value="0"></label>
      <label class="field"><span>${T.storArea}</span><input id="wz-sa" type="number" min="0" step="0.1" value="4"></label>
      <label class="field"><span>${T.numFrom}</span><input id="wz-n0" type="number" min="1" value="1"></label>
      <label class="field"><span>${T.numMode}</span><select id="wz-nm"><option value="block">${T.numBlock}</option><option value="ent">${T.numEnt}</option><option value="floor">${T.numFloor}</option></select></label>
      ${b && unitsOf(b.id).length ? `<label class="field"><span>${T.existing}</span><select id="wz-mode"><option value="replace">${T.modeReplace}</option><option value="add">${T.modeAdd}</option></select></label>` : ''}
    </div>
    <div class="alert alert-info mt" id="wz-sum"></div>
    <div class="flex mt"><button class="btn btn-p" id="wz-go">${T.create}</button><button class="btn" data-x>${T.cancel}</button></div></div>`;
    document.body.append(dlg);
    const box = dlg.querySelector('#wz-types');
    const addPos = v => { const w = document.createElement('span'); w.className = 'wz-pos'; w.innerHTML = typeSel(v) + `<button type="button" class="icon-btn" title="${T.del}">✕</button>`; box.append(w); };
    (startTypes.length ? startTypes : ['+', '+', '+', '+']).forEach(addPos);
    if (!flatTypes.length) box.querySelectorAll('.wz-t').forEach((s, i) => { s.dataset.quick = [1, 2, 2, 3][i]; });
    const sum = () => {
      const ent = b ? b.entr.length : Math.max(1, num(dlg.querySelector('#wz-ent').value, 1)), f0 = num(dlg.querySelector('#wz-f0').value, 1), f1 = num(dlg.querySelector('#wz-f1').value, f0);
      const per = box.children.length, ff = dlg.querySelector('#wz-ff').value;
      const flats = ent * Math.max(0, f1 - f0 + 1) * per + (ff === 'flat' && f0 > 1 ? ent * per : 0);
      const com = ff === 'com' ? ent * num(dlg.querySelector('#wz-cn').value) : 0;
      const mode = dlg.querySelector('#wz-mode'), had = b ? unitsOf(b.id).filter(u => !deals[u.id]).length : 0;
      const note = mode ? `<div class="mt">${(mode.value === 'replace' ? T.wzReplace : T.wzAdd).replace(':n', `<b>${had}</b>`)}</div>` : '';
      dlg.querySelector('#wz-sum').innerHTML = note + '<div>' + T.wzSum.replace(':f', `<b>${flats}</b>`).replace(':c', `<b>${com}</b>`).replace(':p', `<b>${ent * num(dlg.querySelector('#wz-pn').value)}</b>`).replace(':s', `<b>${ent * num(dlg.querySelector('#wz-sn').value)}</b>`) + '</div>';
    };
    dlg.addEventListener('input', sum); dlg.addEventListener('change', sum);
    // добавляем к имеющимся — номера продолжают их; заменяем — с 1
    dlg.querySelector('#wz-mode')?.addEventListener('change', e => { dlg.querySelector('#wz-n0').value = e.target.value === 'add' ? Math.max(0, ...unitsOf(b.id).filter(isFlat).map(u => parseInt(u.num) || 0)) + 1 : 1; });
    dlg.querySelector('#wz-addt').onclick = () => { addPos(box.lastElementChild ? box.lastElementChild.querySelector('select').value : '+'); sum(); };
    box.addEventListener('click', e => { if (e.target.closest('.icon-btn') && box.children.length > 1) { e.target.closest('.wz-pos').remove(); sum(); } });
    box.addEventListener('change', e => { if (e.target.value === '+') { dlg.querySelector('#wz-quick').hidden = false; e.target.dataset.quick = '2'; } });
    dlg.addEventListener('click', e => { if (e.target.closest('[data-x]') || e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', () => dlg.remove());
    dlg.querySelector('#wz-go').onclick = () => { if (buildBlock(dlg, b)) dlg.close(); };
    sum(); dlg.showModal();
  }
  function buildBlock(dlg, b) {
    const q = s => dlg.querySelector(s), v = s => q(s) ? q(s).value : '';
    if (!inv) inv = { v: 1, blocks: [], types: [], units: [], price: { ppm2: C.build.pricePerM2 || 0, step: C.build.floorStep || 0, from: C.build.commercialFirst ? 2 : 1, commercial: C.build.commercialPrice || 0, storage: 0, parking: 0, round: 1000 }, ready: false };
    // быстрые планировки (если их ещё нет): 1-, 2-, 3-, 4-комнатные с указанной площадью
    const quick = {};
    const pos = [...dlg.querySelectorAll('.wz-t')].map(s => {
      if (s.value !== '+') return s.value;
      const r = Math.max(1, Math.min(4, num(s.dataset.quick, 2)));
      if (!quick[r]) { const area = num(q(`[data-qt="${r}"]`).value, [0, 42, 62, 84, 105][r]);
        const ex = inv.types.find(t => (t.kind || 'flat') === 'flat' && t.rooms === r && Math.abs(num(t.area) - area) < 0.05);
        quick[r] = ex ? ex.id : (inv.types.push({ id: rid('t'), name: `${r}${T.r} ${fmt(area, 1)} ${T.m2}`, kind: 'flat', rooms: r, area }), inv.types[inv.types.length - 1].id); }
      return quick[r];
    });
    if (!pos.length) { ui.alert(T.needPos); return false; }
    const f0 = Math.max(1, Math.round(num(v('#wz-f0'), 1))), f1 = Math.max(f0, Math.round(num(v('#wz-f1'), f0)));
    if (!b) {
      const ent = Math.max(1, Math.min(40, Math.round(num(v('#wz-ent'), 1))));
      b = { id: rid('b'), name: v('#wz-name').trim(), floors: f1, entr: Array.from({ length: ent }, (_, i) => ({ id: rid('e'), name: String(i + 1) })) };
      inv.blocks.push(b);
    } else b.floors = Math.max(b.floors || 1, f1);
    if (v('#wz-mode') === 'replace') {
      const keep = unitsOf(b.id).filter(u => deals[u.id]);
      inv.units = inv.units.filter(u => u.b !== b.id || deals[u.id]);
      if (keep.length) toast(T.keptDeals.replace(':n', keep.length));
    }
    const ff = v('#wz-ff'), mode = v('#wz-nm'), cn = Math.round(num(v('#wz-cn'))), ca = num(v('#wz-ca'), 100), pn = Math.round(num(v('#wz-pn'))), sn = Math.round(num(v('#wz-sn'))), sa = num(v('#wz-sa'), 4);
    let n = Math.max(1, Math.round(num(v('#wz-n0'), 1))), cnum = 1, pnum = 1, snum = 1;
    const taken = new Set(unitsOf(b.id).map(u => u.num));
    const add = u => { inv.units.push(Object.assign({ id: rid('u'), b: b.id }, u)); };
    b.entr.forEach((e, ei) => {
      if (mode === 'ent') n = Math.max(1, Math.round(num(v('#wz-n0'), 1)));
      for (let i = 0; i < pn; i++) add({ e: e.id, f: -1, num: 'P' + pnum++, kind: 'parking', area: 13.25 });
      for (let i = 0; i < sn; i++) add({ e: e.id, f: -1, num: 'S' + snum++, kind: 'storage', area: sa });
      if (ff === 'com' && f0 > 1) for (let i = 0; i < cn; i++) add({ e: e.id, f: 1, num: 'N' + cnum++, kind: 'commercial', area: ca });
      const from = ff === 'flat' ? Math.min(1, f0) : f0;
      for (let f = from; f <= f1; f++) {
        if (mode === 'floor') n = 1;
        pos.forEach((tid, i) => { const t = typeOf(tid) || {};
          let label = mode === 'floor' ? `${f}${String(i + 1).padStart(2, '0')}` : String(n++);
          while (taken.has(label) && mode !== 'floor') label = String(n++);
          taken.add(label);
          add({ e: e.id, f, num: label, kind: 'flat', type: tid, rooms: t.rooms || 1, area: num(t.area, 50) });
        });
      }
    });
    cur = b.id; sel.clear(); view = 'grid'; mark(); render();
    toast(T.created);
    return true;
  }

  /* ---------- корпус: название, этажность, подъезды, цена ---------- */
  async function blockEdit() {
    const b = blockOf(cur);
    const v = await ui.form(T.blockSet, [
      { name: 'name', label: T.blockName, value: b.name || '', placeholder: 'A' },
      { name: 'floors', label: T.floorsN, type: 'number', value: b.floors || 1, min: 1, max: 150 },
      { name: 'entr', label: T.entrNames, value: b.entr.map(e => e.name).join(', '), hint: T.entrNamesHint, required: true },
    ], { ok: T.ok, text: T.blockSetHint });
    if (!v) return;
    const names = v.entr.split(',').map(s => s.trim()).filter(Boolean);
    if (!names.length) return;
    // лишние подъезды убираем с конца — только если в них нет квартир со сделкой
    const drop = b.entr.slice(names.length), lost = inv.units.filter(u => u.b === b.id && drop.some(e => e.id === u.e));
    if (lost.some(u => deals[u.id])) return ui.alert(T.entrDeals);
    if (lost.length && !await ui.confirm(T.entrDrop.replace(':n', lost.length))) return;
    inv.units = inv.units.filter(u => !lost.includes(u));
    b.entr = names.map((n, i) => b.entr[i] ? Object.assign(b.entr[i], { name: n }) : { id: rid('e'), name: n });
    b.name = v.name.trim(); b.floors = Math.max(1, Math.min(150, Math.round(num(v.floors, 1))));
    mark(); render();
  }
  async function blockDel() {
    const b = blockOf(cur), us = unitsOf(b.id);
    if (us.some(u => deals[u.id])) return ui.alert(T.blockDeals);
    if (inv.blocks.length === 1) return ui.alert(T.lastBlock);
    if (!await ui.confirm(T.blockDelQ.replace(':n', us.length))) return;
    inv.units = inv.units.filter(u => u.b !== b.id); inv.blocks = inv.blocks.filter(x => x !== b); cur = inv.blocks[0].id; mark(); render();
  }

  /* ---------- изменения квартир ---------- */
  function setField(u, f, val) {
    if (deals[u.id] && ['price', 'sale'].includes(f)) return;
    switch (f) {
      case 'num': u.num = val.trim().slice(0, 12) || u.num; break;
      case 'kind': u.kind = val; if (val !== 'flat') { u.rooms = 0; const t = typeOf(u.type); if (t && (t.kind || 'flat') !== val) delete u.type; } else u.rooms = u.rooms || 1; break;
      case 'type': if (!val) delete u.type; else { const t = typeOf(val); u.type = val; if (t) { u.area = t.area; u.rooms = t.rooms; u.kind = t.kind || 'flat'; } } break;
      case 'rooms': u.rooms = Math.max(1, Math.min(9, Math.round(num(val, 1)))); break;
      case 'area': u.area = Math.max(0, Math.round(num(val) * 100) / 100); break;
      case 'f': u.f = Math.max(-10, Math.min(150, Math.round(num(val, 1)))); break;
      case 'price': if (num(val) > 0) u.price = Math.round(num(val)); else delete u.price; break;
      case 'note': if (val.trim()) u.note = val.trim().slice(0, 120); else delete u.note; break;
      case 'sale': st[u.id] = val ? 'free' : 'reserve'; break;
    }
  }
  function addUnit(e, f) {
    const b = blockOf(cur), row = unitsOf(b.id, e).filter(u => u.f === f), near = row[row.length - 1] || unitsOf(b.id, e).filter(u => u.kind === 'flat' || !u.kind).pop();
    const nums = unitsOf(b.id).map(u => parseInt(u.num)).filter(Number.isFinite);
    const u = { id: rid('u'), b: b.id, e, f, num: String((nums.length ? Math.max(...nums) : 0) + 1), kind: near ? near.kind || 'flat' : 'flat', area: near ? near.area : 50, rooms: near ? near.rooms : 2 };
    if (near && near.type) u.type = near.type;
    if (u.kind !== 'flat') u.num = (near && near.num ? near.num.replace(/\d+$/, '') : '') + u.num;
    const at = near ? inv.units.indexOf(near) + 1 : inv.units.length;
    inv.units.splice(at, 0, u); sel = new Set([u.id]); mark(); render();
  }
  async function delUnits(ids) {
    const list = inv.units.filter(u => ids.has(u.id)), locked = list.filter(u => deals[u.id]);
    if (locked.length) toast(T.cantDelDeals.replace(':n', locked.length), true);
    const go = list.filter(u => !deals[u.id]);
    if (!go.length || !await ui.confirm(T.delQ.replace(':n', go.length))) return;
    inv.units = inv.units.filter(u => !go.includes(u)); go.forEach(u => sel.delete(u.id)); mark(); render();
  }
  function renumber(start) {
    const list = inv.units.filter(u => sel.has(u.id));
    const ordB = id => inv.blocks.findIndex(b => b.id === id), ordE = (b, e) => blockOf(b).entr.findIndex(x => x.id === e);
    list.sort((x, y) => ordB(x.b) - ordB(y.b) || ordE(x.b, x.e) - ordE(y.b, y.e) || x.f - y.f || inv.units.indexOf(x) - inv.units.indexOf(y));
    const m = String(start).match(/^(.*?)(\d+)$/), pre = m ? m[1] : String(start), n0 = m ? parseInt(m[2]) : 1;
    list.forEach((u, i) => { u.num = pre + (n0 + i); });
    mark(); render();
  }

  /* ---------- нумерация всего корпуса: подъезд → этаж снизу вверх → слева направо; номера квартир со сделкой не трогаем ---------- */
  async function renumBlock() {
    const b = blockOf(cur);
    const v = await ui.form(T.renumBlockQ, [
      { name: 'start', label: T.numFrom, type: 'number', value: 1, min: 1 },
      { name: 'mode', label: T.numMode, type: 'select', value: 'block', options: [['block', T.numBlock], ['ent', T.numEnt], ['floor', T.numFloor]] },
    ], { ok: T.ok, text: T.renumBlockHint });
    if (!v) return;
    const start = Math.max(1, Math.round(num(v.start, 1))), taken = new Set(unitsOf(b.id).filter(u => deals[u.id]).map(u => u.num));
    let n = start, done = 0;
    b.entr.forEach(e => {
      if (v.mode === 'ent') n = start;
      const us = unitsOf(b.id, e.id).filter(isFlat), fs = [...new Set(us.map(u => u.f))].sort((x, y) => x - y);
      fs.forEach(f => us.filter(u => u.f === f).forEach((u, i) => {
        if (deals[u.id]) return;
        let label = v.mode === 'floor' ? `${f}${String(i + 1).padStart(2, '0')}` : String(n++);
        while (taken.has(label) && v.mode !== 'floor') label = String(n++);
        u.num = label; taken.add(label); done++;
      }));
    });
    mark(); render(); toast(T.renumDone.replace(':n', done));
  }
  /** Квартиру — левее или правее на её этаже (порядок слева направо = окна на фасаде и место на 3D-модели) */
  function moveUnit(u, d) {
    if (!u) return;
    const row = inv.units.filter(x => x.b === u.b && x.e === u.e && x.f === u.f), k = row.indexOf(u), other = row[k + d];
    if (!other) return;
    const i = inv.units.indexOf(u), j = inv.units.indexOf(other);
    [inv.units[i], inv.units[j]] = [inv.units[j], inv.units[i]];
    mark(); render();
  }

  /* ---------- отрисовка ---------- */
  function render() {
    if (!inv) { app.innerHTML = empty(); return; }
    const body = view === 'types' ? types() : view === 'prices' ? prices() : view === 'ready' ? ready() : grid();
    app.innerHTML = head() + body;
  }
  const rerenderSide = () => { const s = document.getElementById('inv-side'); if (s) s.innerHTML = side(); };
  const repaintCells = () => { const board = app.querySelector('.inv-ents'); if (!board) return;
    board.querySelectorAll('.inv-u').forEach(btn => { const u = inv.units.find(x => x.id === btn.dataset.u); if (u) btn.outerHTML = cell(u); });
    const h = app.querySelector('.inv-head'); if (h) { const tmp = document.createElement('div'); tmp.innerHTML = head(); h.replaceWith(tmp.firstElementChild); } };

  /* ---------- события ---------- */
  app.addEventListener('click', async e => {
    const c = e.target.closest('.inv-u');
    if (c) {
      const id = c.dataset.u;
      if (e.shiftKey && anchor) {
        const order = [...app.querySelectorAll('.inv-u')].map(x => x.dataset.u), a = order.indexOf(anchor), z = order.indexOf(id);
        order.slice(Math.min(a, z), Math.max(a, z) + 1).forEach(x => sel.add(x));
      } else if (multi || e.ctrlKey || e.metaKey) { sel.has(id) ? sel.delete(id) : sel.add(id); anchor = id; }
      else { sel = new Set([id]); anchor = id; }
      app.querySelectorAll('.inv-u').forEach(x => x.classList.toggle('sel', sel.has(x.dataset.u)));
      rerenderSide(); return;
    }
    const a = e.target.closest('[data-a]'); if (!a) return;
    const act = a.dataset.a;
    if (act === 'multi' || act === 'toMedia' && !dirty) return;
    if (a.tagName === 'A' && act !== 'toMedia') e.preventDefault();
    const list = () => inv.units.filter(u => sel.has(u.id));
    switch (act) {
      case 'toMedia': if (dirty) { e.preventDefault(); if (await ui.confirm(T.saveFirst)) { await save(); if (!dirty) location.href = a.href; } } return;
      case 'save': return save();
      case 'view': view = a.dataset.v; history.replaceState(null, '', '?tab=units&v=' + view); sel.clear(); return render();
      case 'block': cur = a.dataset.b; sel.clear(); return render();
      case 'blockEdit': return blockEdit();
      case 'wizard': return wizard(a.dataset.b || null);
      case 'convert':
        if (!await ui.confirm(T.convertQ)) return;
        try { await post(C.urls.convert); location.href = '?tab=units'; } catch (err) { ui.alert(err.message); } return;
      case 'undo':
        if (!await ui.confirm(T.undoQ)) return;
        try { dirty = false; await post(C.urls.undo); location.reload(); } catch (err) { ui.alert(err.message); } return;
      case 'ready': {
        const on = a.dataset.on === '1';
        if (dirty && !await ui.confirm(T.saveFirst)) return;
        if (dirty) { await save(); if (dirty) return; }
        if (!await ui.confirm(on ? T.goSaleQ : T.toDraftQ)) return;
        try { await post(C.urls.ready, { on }); C.ready = on ? new Date().toISOString().slice(0, 10) : false; toast(on ? T.nowOnSale : T.nowDraft); render(); } catch (err) { ui.alert(err.message); } return;
      }
      case 'selEnt': sel = new Set(unitsOf(cur, a.dataset.e).map(u => u.id)); return render();
      case 'selFloor': sel = new Set(unitsOf(cur, a.dataset.e).filter(u => u.f === +a.dataset.f).map(u => u.id)); return render();
      case 'selIds': { const ids = a.dataset.ids.split(','); sel = new Set(ids); const u = inv.units.find(x => x.id === ids[0]); if (u) cur = u.b; view = 'grid'; return render(); }
      case 'clear': sel.clear(); return render();
      case 'addUnit': return addUnit(a.dataset.e, +a.dataset.f);
      case 'dup': { const u = list()[0]; const copy = Object.assign({}, u, { id: rid('u') }); delete copy.price; const nums = unitsOf(u.b).map(x => parseInt(x.num)).filter(Number.isFinite);
        copy.num = String(Math.max(0, ...nums) + 1); inv.units.splice(inv.units.indexOf(u) + 1, 0, copy); sel = new Set([copy.id]); mark(); return render(); }
      case 'del': return delUnits(new Set(sel));
      case 'bDel': return delUnits(new Set(sel));
      case 'bType': { const t = document.getElementById('bk-type').value; list().forEach(u => setField(u, 'type', t)); mark(); return render(); }
      case 'bKind': { const k = document.getElementById('bk-kind').value; list().forEach(u => setField(u, 'kind', k)); mark(); return render(); }
      case 'bArea': { const v = num(document.getElementById('bk-area').value); if (v <= 0) return; list().forEach(u => setField(u, 'area', v)); mark(); return render(); }
      case 'bPpm2': { const v = num(document.getElementById('bk-ppm2').value), R = rule(); if (v <= 0) return; list().filter(u => !deals[u.id]).forEach(u => { u.price = Math.round(num(u.area) * v / R.round) * R.round; }); mark(); return render(); }
      case 'bAuto': list().forEach(u => delete u.price); mark(); return render();
      case 'bNum': return renumber(document.getElementById('bk-num').value.trim() || '1');
      case 'renumBlock': return renumBlock();
      case 'move': return moveUnit(list()[0], +a.dataset.d);
      case 'bSale': list().forEach(u => setField(u, 'sale', a.dataset.on === '1')); mark(); return render();
      case 'typeEdit': return typeEdit(a.dataset.t || null);
      case 'typeDel': return typeDel(a.dataset.t);
      case 'blockDel': return blockDel();
      case 'pct': {
        const k = num(document.getElementById('pr-pct').value); if (!k) return;
        const R = inv.price, f = 1 + k / 100; ['ppm2', 'commercial', 'storage', 'parking'].forEach(x => { if (num(R[x]) > 0) R[x] = Math.round(num(R[x]) * f); });
        inv.blocks.forEach(b => { if (num(b.ppm2) > 0) b.ppm2 = Math.round(num(b.ppm2) * f); });
        if (document.getElementById('pr-man').checked) inv.units.forEach(u => { if (num(u.price) > 0 && !deals[u.id]) u.price = Math.round(num(u.price) * f / rule().round) * rule().round; });
        mark(); return render();
      }
    }
  });
  app.addEventListener('change', e => {
    const t = e.target;
    if (t.dataset.a === 'multi') { multi = t.checked; return; }
    if (t.dataset.f) {   // поле одной квартиры
      const u = inv.units.find(x => sel.has(x.id)); if (!u) return;
      setField(u, t.dataset.f, t.type === 'checkbox' ? t.checked : t.value); mark();
      if (['kind', 'type', 'f'].includes(t.dataset.f)) render(); else { repaintCells(); rerenderSide(); }
      return;
    }
    if (t.dataset.p) { inv.price[t.dataset.p] = t.tagName === 'SELECT' ? +t.value : num(t.value); mark(); render(); return; }
    if (t.dataset.bp) { const b = blockOf(t.dataset.bp); if (num(t.value) > 0) b.ppm2 = num(t.value); else delete b.ppm2; mark(); render(); }
  });
  // удобство: Delete — удалить выбранные, Esc — снять выбор, Ctrl+S — сохранить
  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && dirty) { e.preventDefault(); save(); return; }
    if (/INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName) || document.querySelector('dialog[open],.ui-dlg')) return;
    if (e.key === 'Escape' && sel.size) { sel.clear(); render(); }
    if (e.key === 'Delete' && sel.size && view === 'grid') delUnits(new Set(sel));
  });
  render();
})();
