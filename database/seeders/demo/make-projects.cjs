// Генерирует объекты для демо-компаний генератором самой программы (структура данных 100% совпадает с /crm).
// Запуск: node database/seeders/demo/make-projects.cjs → database/seeders/demo/projects.json
const fs = require('fs'), vm = require('vm'), path = require('path');
const DIR = path.join(__dirname, '../../../public/app/js');
const files = ['core', 'icons', 'i18n', 'i18n_dict', 'i18n_extra', 'i18n_fix', 'calc', 'data', 'pages', 'pages2', 'geo', 'ai', 'hiring', 'safety', 'legal', 'finance', 'home', 'leads', 'attend', 'saas'];
const stub = () => new Proxy(function () {}, { get: (t, k) => k === Symbol.toPrimitive ? () => '' : stub(), apply: () => stub(), set: () => true });
const store = {};
const ctx = {
  console, Math, Date, JSON, URLSearchParams, Intl, setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, requestAnimationFrame: () => 0, addEventListener() {},
  localStorage: { getItem: k => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); }, removeItem: k => { delete store[k]; } },
  document: stub(), navigator: { language: 'ru' }, location: { search: '', hash: '', href: '' }, history: { replaceState() {} },
  MutationObserver: function () { return { observe() {} }; }, matchMedia: () => ({ matches: false, addEventListener() {} }), fetch: () => Promise.reject(new Error('offline')), alert() {}, confirm: () => false, prompt: () => null,
};
ctx.window = ctx; ctx.globalThis = ctx;
vm.createContext(ctx);
let src = files.map(f => fs.readFileSync(path.join(DIR, f + '.js'), 'utf8').replace(/^'use strict';/, '')).join('\n;\n');
src += `\nDB = { settings: { lang: 'ru', currency: 'сум', company: 'Demo', fuelPrice: 12500, normHours: 8, overtimeK: 1.5 }, projects: [], currentId: null };\n`;
// демо-проект программы, но с параметрами: название, адрес, начало, здание, зерно случайности
src += `globalThis.__make = function (o) {
  const body = demoProject.toString()
    .replace('rng(20261009)', 'rng(' + o.seed + ')')
    .replace("addDays(today(), -220)", "addDays(today(), -" + o.age + ")")
    .replace("'ЖК «Новый Город» (демо)'", JSON.stringify(o.name))
    .replace("p.address = 'ул. Строителей, участок 15'; p.status = 'Строительство';", 'p.address = ' + JSON.stringify(o.address) + '; p.status = ' + JSON.stringify(o.status) + '; Object.assign(p.b, ' + JSON.stringify(o.b) + ');');
  const fn = (0, eval)('(' + body + ')');
  return fn();
};`;
vm.runInContext(src, ctx, { filename: 'spa.js' });
const make = o => {
  const p = ctx.__make(o); p.id = o.id;
  if (o.scale) {   // суммы генератора в сумах → в рубли (кроме оплат квартир: они уже от цены в рублях)
    const k = o.scale, r = v => Math.round(v * k / 1000) * 1000 || Math.round(v * k);
    const aptTx = new Set(); Object.values(p.apts || {}).forEach(a => (a.payments || []).forEach(x => aptTx.add(x.txId)));
    p.tx.forEach(t => { if (!aptTx.has(t.id)) t.amount = r(t.amount); });
    (p.pays || []).forEach(x => { x.amount = r(x.amount); }); (p.staff || []).forEach(x => { x.rate = Math.round(x.rate * k); });
    (p.fuel || []).forEach(x => { x.price = Math.round(x.price * k * 10) / 10; }); (p.fleet || []).forEach(x => { if (x.rent) x.rent = Math.round(x.rent * k); });
  }
  return p;
};

const out = {
  generated: new Date().toISOString().slice(0, 10),
  navruz: [
    make({ id: 'nvz-park', seed: 11, age: 260, name: '«Navruz Park» TJM', address: 'Toshkent, Yunusobod tumani, Amir Temur ko‘chasi, 108', status: 'Строительство',
      b: { floors: 12, sections: 2, aptsPerFloor: 4, mix: '1,2,2,3', pricePerM2: 11500000, commercialPrice: 15000000, length: 56, width: 17 } }),
    make({ id: 'nvz-city', seed: 22, age: 140, name: '«Navruz City» TJM', address: 'Toshkent, Mirzo Ulug‘bek tumani, Buyuk Ipak Yo‘li massivi', status: 'Строительство',
      b: { floors: 9, sections: 3, aptsPerFloor: 4, mix: '1,2,3,2', pricePerM2: 9800000, commercialPrice: 13000000, length: 72, width: 16 } }),
    make({ id: 'nvz-tower', seed: 33, age: 60, name: '«Navruz Tower» TJM', address: 'Toshkent, Mirobod tumani, Shahrisabz ko‘chasi, 5', status: 'Строительство',
      b: { floors: 16, sections: 1, aptsPerFloor: 6, mix: '1,1,2,2,3,4', pricePerM2: 14500000, commercialPrice: 19000000, length: 42, width: 22 } }),
  ],
  moscow: [
    make({ id: 'msk-sever', seed: 55, age: 300, name: 'МФК «Oftob Tower» (Москва-Сити)', address: 'Москва, Пресненская набережная, ММДЦ «Москва-Сити», участок 17', status: 'Строительство', scale: 0.0075,
      b: { floors: 60, sections: 1, aptsPerFloor: 6, mix: '1,1,2,2,3,4', pricePerM2: 690000, commercialPrice: 820000, length: 46, width: 40 } }),
    make({ id: 'msk-reka', seed: 66, age: 90, name: 'ЖК «Речной квартал»', address: 'Москва, Нагатинская набережная, 10', status: 'Строительство', scale: 0.0075,
      b: { floors: 14, sections: 2, aptsPerFloor: 5, mix: '1,2,2,3,4', pricePerM2: 395000, commercialPrice: 470000, length: 60, width: 18 } }),
  ],
  pamir: [
    make({ id: 'pmr-sohil', seed: 44, age: 180, name: 'ШИ «Соҳил»', address: 'Душанбе, хиёбони Рӯдакӣ 140', status: 'Строительство',
      b: { floors: 10, sections: 2, aptsPerFloor: 4, mix: '1,2,2,3', pricePerM2: 9500000, commercialPrice: 12500000, length: 54, width: 16 } }),
  ],
};
fs.writeFileSync(path.join(__dirname, 'projects.json'), JSON.stringify(out));
for (const k of ['navruz', 'moscow', 'pamir']) for (const p of out[k]) console.log(k, p.id, p.name, 'tx', p.tx.length, 'staff', p.staff.length, 'apts', Object.keys(p.apts).length, 'floors', p.b.floors);
