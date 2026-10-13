'use strict';
/* ===== Эмодзи → линейные иконки Lucide по всей программе =====
   Программа большая и местами пишет эмодзи прямо в разметку. Здесь они на лету заменяются
   на аккуратные SVG-иконки (цвет — от текста, оттенок для статусов), а в списках <select> просто убираются. */
const ICON_RX = /(\p{Extended_Pictographic}️?)\s?/gu;
const ICON_KEEP = new Set(['©', '®', '™']);
const ICON_SKIP = 'script,style,textarea,svg,canvas,code,.no-icons';

/** SVG-иконка по имени: ic('wallet') */
function ic(name, cls = '') {
  const p = ICONS[name] || ICONS.circle;
  return `<svg class="ic${cls ? ' ' + cls : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
}
function emojiSvg(e) {
  const m = EMOJI_ICON[e] || EMOJI_ICON[e.replace(/️/g, '')] || EMOJI_ICON[e + '️'];
  if (!m) return null;
  const t = document.createElement('template');
  t.innerHTML = ic(m[0], 'emo' + (m[1] ? ' t-' + m[1] : ''));
  return t.content.firstChild;
}
function iconizeText(n) {
  let v = n.nodeValue;
  if (!v || !ICON_RX.test(v)) return; ICON_RX.lastIndex = 0;
  const p = n.parentElement; if (!p || p.closest(ICON_SKIP)) return;
  // сначала переводим фразу целиком (в словаре она со значками), потом режем на текст и иконки
  if (typeof T === 'function' && !p.closest('.notr')) { const t = T(v); if (t !== v) v = t; }
  if (p.closest('select,option,optgroup,title')) { n.nodeValue = v.replace(ICON_RX, (m, e) => ICON_KEEP.has(e) ? m : '').trim() || v; return; }
  const frag = document.createDocumentFragment(); let last = 0, changed = false;
  v.replace(ICON_RX, (m, e, off) => {
    if (ICON_KEEP.has(e)) return m;
    if (off > last) frag.appendChild(document.createTextNode(v.slice(last, off)));
    const svg = emojiSvg(e); if (svg) frag.appendChild(svg);
    last = off + m.length; changed = true; return m;
  });
  if (!changed) return;
  if (last < v.length) frag.appendChild(document.createTextNode(v.slice(last)));
  n.replaceWith(frag);
}
function iconize(root) {
  if (!root) return;
  if (root.nodeType === 3) return iconizeText(root);
  if (root.nodeType !== 1 || root.closest && root.closest(ICON_SKIP)) return;
  const tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT); const list = []; let x;
  while ((x = tw.nextNode())) if (ICON_RX.test(x.nodeValue)) { ICON_RX.lastIndex = 0; list.push(x); } else ICON_RX.lastIndex = 0;
  list.forEach(iconizeText);
  // подписи кнопок и полей
  root.querySelectorAll && root.querySelectorAll('[title],[placeholder]').forEach(el => ['title', 'placeholder'].forEach(a => {
    const v = el.getAttribute(a); if (v && ICON_RX.test(v)) { ICON_RX.lastIndex = 0; el.setAttribute(a, v.replace(ICON_RX, '').trim()); } ICON_RX.lastIndex = 0;
  }));
}
const iconObs = new MutationObserver(ms => ms.forEach(m => {
  if (m.type === 'characterData') return iconizeText(m.target);
  m.addedNodes.forEach(iconize);
}));
function iconStart() { iconize(document.body); iconObs.observe(document.body, { childList: true, subtree: true, characterData: true }); }
if (document.body) iconStart(); else document.addEventListener('DOMContentLoaded', iconStart);
