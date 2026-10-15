'use strict';
/* ===== Базовые утилиты, хранилище, UI-хелперы ===== */
const STORE_KEY = window.OFTOB ? 'stroycrm_ws_' + window.OFTOB.ws : 'stroycrm_v1';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const uid = () => Math.random().toString(36).slice(2, 10);
const num = v => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
const r1 = v => Math.round(v * 10) / 10;
const sum = (arr, f = x => x) => arr.reduce((s, x) => s + (f(x) || 0), 0);
const fmt = (n, d = 0) => (isFinite(n) ? +n : 0).toLocaleString('ru-RU', { maximumFractionDigits: d });
const cur = () => DB.settings.currency;
const money = n => fmt(Math.round(n || 0)) + ' ' + cur();
function short(n) {
  n = n || 0; const a = Math.abs(n);
  if (a >= 1e12) return fmt(n / 1e12, 2) + ' трлн';
  if (a >= 1e9) return fmt(n / 1e9, 2) + ' млрд';
  if (a >= 1e6) return fmt(n / 1e6, 1) + ' млн';
  if (a >= 1e4) return fmt(n / 1e3, 0) + ' тыс';
  return fmt(n);
}
const moneyS = n => short(n) + ' ' + cur();
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function isoDate(d) { const z = new Date(d); z.setMinutes(z.getMinutes() - z.getTimezoneOffset()); return z.toISOString().slice(0, 10); }
const today = () => isoDate(new Date());
function addDays(s, n) { const d = new Date(s + 'T00:00:00'); d.setDate(d.getDate() + Math.round(n)); return isoDate(d); }
function daysBetween(a, b) { return Math.round((new Date(b + 'T00:00:00') - new Date(a + 'T00:00:00')) / 864e5); }
const fdate = s => s ? s.split('-').reverse().join('.') : '';
const monthNow = () => today().slice(0, 7);
const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const MONTHS_S = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const monthName = m => { const [y, mm] = m.split('-'); return MONTHS[+mm - 1] + ' ' + y; };

function getPath(o, p) { return p.split('.').reduce((a, k) => a == null ? undefined : a[k], o); }
function setPath(o, p, v) {
  const ks = p.split('.'); let a = o;
  for (let i = 0; i < ks.length - 1; i++) { if (a[ks[i]] == null || typeof a[ks[i]] !== 'object') a[ks[i]] = {}; a = a[ks[i]]; }
  if (v === undefined) delete a[ks[ks.length - 1]]; else a[ks[ks.length - 1]] = v;
}

/* ===== Хранилище ===== */
let DB;
function P() { return DB.projects.find(p => p.id === DB.currentId) || DB.projects[0]; }
let saveT;
function save() {
  clearTimeout(saveT);
  saveT = setTimeout(() => {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(DB)); const s = $('#saveState'); if (s) { s.textContent = '✓ сохранено'; clearTimeout(s._t); s._t = setTimeout(() => s.textContent = '', 1500); } }
    catch (e) { toast('Ошибка сохранения: ' + e.message); }
  }, 250);
}

/* ===== UI-хелперы ===== */
function srcOf(path) { return path.startsWith('S:') ? [DB.settings, path.slice(2)] : [P(), path]; }
function field(path, label, o = {}) {
  const [src, key] = srcOf(path); const v = getPath(src, key);
  const re = o.re ? ' data-re' : '';
  if (o.options) {
    return `<label class="fld"><span>${label}</span><select data-bind="${path}"${re}>${o.options.map(op => {
      const [val, t] = Array.isArray(op) ? op : [op, op];
      return `<option value="${esc(val)}"${String(val) === String(v) ? ' selected' : ''}>${esc(t)}</option>`;
    }).join('')}</select>${o.hint ? `<small>${o.hint}</small>` : ''}</label>`;
  }
  if (o.type === 'checkbox') return `<label class="fld chk"><input type="checkbox" data-bind="${path}"${v ? ' checked' : ''}${re}> <span>${label}</span></label>`;
  const t = o.type || 'number';
  return `<label class="fld"><span>${label}</span><div class="inp"><input type="${t}"${t === 'number' ? ` step="${o.step || 'any'}"` : ''} data-bind="${path}" value="${esc(v ?? '')}"${re}${o.ph ? ` placeholder="${esc(o.ph)}"` : ''}>${o.unit ? `<em>${o.unit}</em>` : ''}</div>${o.hint ? `<small>${o.hint}</small>` : ''}</label>`;
}
function cell(path, v, o = {}) {
  const t = o.type || 'number';
  if (v === undefined) v = getPath(P(), path);
  return `<input class="cell${t === 'number' ? ' n' : ''}" type="${t}"${t === 'number' ? ' step="any"' : ''} data-bind="${path}" value="${esc(v ?? '')}"${o.re ? ' data-re' : ''} ${o.attr || ''}>`;
}
function cellSel(path, v, options) {
  return `<select class="cell" data-bind="${path}">${options.map(op => { const [val, t] = Array.isArray(op) ? op : [op, op]; return `<option value="${esc(val)}"${String(val) === String(v) ? ' selected' : ''}>${esc(t)}</option>`; }).join('')}</select>`;
}
const opts = (list, sel) => list.map(op => { const [v, t] = Array.isArray(op) ? op : [op, op]; return `<option value="${esc(v)}"${String(v) === String(sel) ? ' selected' : ''}>${esc(t)}</option>`; }).join('');
function applyBind(el) {
  const path = el.dataset.bind; const [src, key] = srcOf(path); let v;
  if (el.type === 'checkbox') v = el.checked;
  else if (el.dataset.num != null) v = el.value.trim() === '' ? undefined : num(el.value);
  else if (el.type === 'number') v = num(el.value);
  else if (el.tagName === 'SELECT' && typeof getPath(src, key) === 'number') v = num(el.value);
  else v = el.value;
  setPath(src, key, v);
  if (src !== DB.settings && key.startsWith('b.') && src.draft !== false) { src.draft = false; const n = $('.draft-note'); if (n) n.remove(); }   // свои параметры дома — уже не образец
  save();
}
const kpi = (label, value, sub = '', cls = '') => `<div class="kpi${cls ? ' k-' + cls : ''}"><span>${label}</span><b title="${esc(String(value).replace(/<[^>]+>/g, ''))}">${value}</b><small>${sub}</small></div>`;
const rbox = (label, value, hl) => `<div class="rbox${hl ? ' hl' : ''}"><span>${label}</span><b>${value}</b></div>`;
const pill = (cls, t) => `<span class="pill p-${cls}">${esc(t)}</span>`;
function hbars(rows) { // rows: {label, plan, fact}
  const max = Math.max(1, ...rows.map(r => Math.max(r.plan, r.fact)));
  return rows.map(r => `<div class="hb"><div class="lb"><span>${esc(r.label)}</span><span>${moneyS(r.fact)} / ${moneyS(r.plan)}</span></div>
   <div class="tr"><div class="pl" style="width:${r.plan / max * 100}%"></div><div class="fa${r.fact > r.plan ? ' over' : ''}" style="width:${r.fact / max * 100}%"></div></div></div>`).join('');
}
const progBar = (pct, color) => `<div class="bar"><i style="width:${Math.max(0, Math.min(100, pct))}%${color ? ';background:' + color : ''}"></i></div>`;

function openModal(html) { $('.modal-box').classList.remove('wide'); $('#modalBody').innerHTML = html; $('#modal').classList.remove('hidden'); }
function closeModal() { $('#modal').classList.add('hidden'); }
function toast(msg) { const t = $('#toast'); t.textContent = T(msg); t.classList.add('show'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 2600); }
function download(name, content, type = 'text/plain') {
  const blob = content instanceof Blob ? content : new Blob([content], { type });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
}
function csv(rows) { return '﻿' + rows.map(r => r.map(c => { const s = String(c ?? ''); return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }).join(';')).join('\r\n'); }
function rng(seed) { return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; let t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }

/* иконка геологии (используется в меню, на главной и в группах) */
const GEO_ICON = `<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/></svg>`;
