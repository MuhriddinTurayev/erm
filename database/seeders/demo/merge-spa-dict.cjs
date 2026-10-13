// Переводы из словаря программы (/crm) → lang/uz.json и lang/tg.json, чтобы серверные страницы
// переводили те же данные: этапы стройки, статусы, статьи, должности, категории.
// Запуск: node database/seeders/demo/merge-spa-dict.cjs
const fs = require('fs'), vm = require('vm'), path = require('path');
const JS = path.join(__dirname, '../../../public/app/js'), LANG = path.join(__dirname, '../../../lang');
const ctx = { console, window: {}, document: { addEventListener() {} }, MutationObserver: function () { return { observe() {} }; }, localStorage: { getItem: () => null } };
ctx.window = ctx; vm.createContext(ctx);
let src = '';
for (const f of ['i18n_dict', 'i18n_extra', 'i18n_fix']) {
  let s = fs.readFileSync(path.join(JS, f + '.js'), 'utf8').replace(/^'use strict';/, '');
  // берём только объявления массивов-словарей
  const parts = [...s.matchAll(/^const (_[EW]\d*) = \[[\s\S]*?^\];/gm)].map(m => m[0]);
  src += parts.join('\n') + '\n';
}
src += 'globalThis.__d = [...(typeof _E !== "undefined" ? _E : []), ...(typeof _E2 !== "undefined" ? _E2 : []), ...(typeof _E3 !== "undefined" ? _E3 : []), ...(typeof _E4 !== "undefined" ? _E4 : []), ...(typeof _W !== "undefined" ? _W : []), ...(typeof _W2 !== "undefined" ? _W2 : [])];';
vm.runInContext(src, ctx);
const strip = s => String(s).replace(/^[\p{Extended_Pictographic}️‍\s]+/u, '').trim();
const uz = JSON.parse(fs.readFileSync(LANG + '/uz.json', 'utf8')), tg = JSON.parse(fs.readFileSync(LANG + '/tg.json', 'utf8'));
let n = 0;
for (const row of ctx.__d) {
  if (!Array.isArray(row) || row.length < 3) continue;
  const [ru, u, t] = row.map(strip);
  if (!ru || !u || !t || ru.length > 300 || !/[А-Яа-яЁё]/.test(ru)) continue;
  if (!uz[ru]) { uz[ru] = u; n++; }
  if (!tg[ru]) { tg[ru] = t; }
}
fs.writeFileSync(LANG + '/uz.json', JSON.stringify(uz, null, 4) + '\n');
fs.writeFileSync(LANG + '/tg.json', JSON.stringify(tg, null, 4) + '\n');
console.log('dictionary rows', ctx.__d.length, 'added', n);
