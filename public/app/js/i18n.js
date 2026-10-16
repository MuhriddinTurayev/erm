'use strict';
/* ===== Языки интерфейса: русский, узбекский, таджикский ===== */
const LANGS = [['tg', 'Тоҷикӣ'], ['ru', 'Русский'], ['uz', "O'zbekcha"]];   // таджикский первым
const L10N = { uz: {}, tg: {} };      // точные фразы
const L10W = { uz: {}, tg: {} };      // слова и части фраз
const curLang = () => (typeof DB !== 'undefined' && DB && DB.settings && DB.settings.lang) || 'ru';
const CYR = 'А-Яа-яЁё';
const _rx = {};
function wordRx(l) {
  if (_rx[l]) return _rx[l];
  const map = {}; Object.entries(L10W[l]).forEach(([k, v]) => { map[k] = v; const K = k[0].toUpperCase() + k.slice(1); if (!(K in map)) map[K] = v[0].toUpperCase() + v.slice(1); });
  const keys = Object.keys(map).sort((a, b) => b.length - a.length).map(k => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  _rx[l] = { map, rx: keys.length ? new RegExp(`(?<![${CYR}A-Za-z])(${keys.join('|')})(?![${CYR}])`, 'g') : null };
  return _rx[l];
}
/* Шаблоны с изменяемыми частями: [RegExp, { uz: строка|функция, tg: ... }], $1… — найденные части */
const L10P = [];
function tPattern(l, k) {
  for (const [rx, tr] of L10P) {
    const m = rx.exec(k); if (!m || !tr[l]) continue;
    const r = typeof tr[l] === 'function' ? tr[l](...m.slice(1)) : tr[l].replace(/\$(\d)/g, (x, i) => m[+i] ?? '');
    if (r != null) return r;
  }
  return null;
}
/* Перевод строки целиком: точная фраза → шаблон → по словам (только если переведены ВСЕ слова, иначе — без изменений) */
function tWhole(l, k) {
  const d = L10N[l][k]; if (d) return d;
  const k2 = k.replace(/^[^А-Яа-яЁёA-Za-z0-9«(№+]+/, ''), d2 = k2 !== k && L10N[l][k2];
  if (d2) return k.slice(0, k.length - k2.length) + d2;
  const p = tPattern(l, k2); if (p != null) return k.slice(0, k.length - k2.length) + p;
  const w = wordRx(l); if (!w.rx) return null;
  if (/[А-Яа-яЁё]/.test(k.replace(w.rx, ''))) return null;   // есть непереведённые слова — не смешиваем языки
  return k.replace(w.rx, (m, g, off, str) => (m.length === 1 && m !== m.toLowerCase() && str[off + 1] === '.') ? m : (w.map[m] ?? m));
}
function T(s) {
  const l = curLang(); if (l === 'ru' || !s || typeof s !== 'string' || !/[А-Яа-яЁё]/.test(s)) return s;
  const k = s.trim(); if (!k) return s;
  const r = tWhole(l, k); if (r != null) return s.replace(k, r);
  // «Иванов А. · Продана», «2-комн. · до 950 млн»: каждую часть отдельно, имена остаются как есть
  if (/ · /.test(k)) {
    const parts = k.split(' · '), tr = parts.map(p => (p.trim() && tWhole(l, p.trim())) ?? p);
    if (tr.some((x, i) => x !== parts[i])) return s.replace(k, tr.join(' · '));
  }
  return s;
}
const SKIP_SEL = '.notr,script,style,textarea';
function trNode(n) {
  if (n.nodeType === 3) { const p = n.parentElement; if (!p || p.closest(SKIP_SEL)) return; const v = n.nodeValue, t = T(v); if (t !== v) n.nodeValue = t; return; }
  if (n.nodeType !== 1 || n.closest(SKIP_SEL)) return;
  const tw = document.createTreeWalker(n, NodeFilter.SHOW_TEXT); let x; const list = [];
  while ((x = tw.nextNode())) list.push(x);
  list.forEach(trNode);
  [n, ...n.querySelectorAll('[placeholder],[title]')].forEach(e => {
    if (e.closest && e.closest(SKIP_SEL)) return;
    ['placeholder', 'title'].forEach(a => { if (e.hasAttribute && e.hasAttribute(a)) { const v = e.getAttribute(a), t = T(v); if (t !== v) e.setAttribute(a, t); } });
  });
}
const i18nObs = new MutationObserver(ms => { if (curLang() === 'ru') return; ms.forEach(m => m.addedNodes.forEach(trNode)); });
function i18nStart() {
  document.documentElement.lang = curLang() === 'tg' ? 'tg' : curLang();
  if (curLang() !== 'ru') trNode(document.body);
  i18nObs.observe(document.body, { childList: true, subtree: true });
}
['confirm', 'prompt', 'alert'].forEach(fn => { const o = window[fn].bind(window); window[fn] = (m, ...a) => o(T(m), ...a); });
