'use strict';
/* ===== Фотореалистичные интерьеры (сгенерированы нейросетью) ===== */
const PH_BASE = 'https://d8j0ntlcm91z4.cloudfront.net/user_36AiUD1pSgeNBvCGCmBgypBYHJL/';
const INTERIOR_PHOTOS = [
  { style: 'modern', room: 'Кухня-гостиная', file: 'hf_20261009_102119_4c8a9dc3-a6b6-435f-b57e-1de69b54f8af' },
  { style: 'modern', room: 'Спальня', file: 'hf_20261009_102120_450b0929-196d-46cc-a08a-20e982fb8f88' },
  { style: 'scandi', room: 'Спальня', file: 'hf_20261009_102120_a9b7fdf1-5950-4c4b-8c62-61e393778786' },
  { style: 'classic', room: 'Спальня', file: 'hf_20261009_102120_edc4cd70-862e-4816-8b6b-f09e27b1c581' },
  { style: 'eco', room: 'Кухня-гостиная', file: 'hf_20261009_102121_26b47ffc-d33d-4424-bb35-82e8a1ac6b6d' },
];
const phUrl = (p, min) => PH_BASE + p.file + (min ? '_min.webp' : '.png');
function photoGallery(style) {
  const own = INTERIOR_PHOTOS.filter(p => p.style === style), main = own[0] || INTERIOR_PHOTOS[0];
  return `<div class="card ph-card"><h3>📸 Фотореалистичный дизайн квартиры <span class="tools"><button class="btn-p btn-s pv-open" data-act="ph360" data-style="${own.length ? style : ''}">🌐 Осмотреться 360°</button></span></h3>
    <div class="small muted" style="margin:-6px 0 10px">Примеры стилей (нарисованы нейросетью) — это не ваша квартира. Загрузите интерьер своей квартиры — покупатели увидят его.</div>
    <div class="ph-wrap">
      <button class="ph-main" data-act="phOpen" data-i="${INTERIOR_PHOTOS.indexOf(main)}" id="phMain"><img src="${phUrl(main)}" alt="" loading="lazy"><span class="ph-tag">${STYLES[main.style].name} · ${main.room}</span><span class="ph-zoom">🔍</span></button>
      <div class="ph-thumbs">${INTERIOR_PHOTOS.map((p, i) => `<button class="ph-th${p.style === style ? ' on' : ''}" data-act="phOpen" data-i="${i}"><img src="${phUrl(p, 1)}" alt="" loading="lazy"><span>${STYLES[p.style].name}<br><b>${p.room}</b></span></button>`).join('')}</div>
    </div>
    ${own.length ? '' : `<div class="small muted mt">Для стиля «${STYLES[style].name}» фото ещё не сгенерированы — показан другой стиль. Ниже — 3D-модель в этом стиле.</div>`}
  </div>`;
}
ACT.phOpen = d => {
  const i = +d.i, p = INTERIOR_PHOTOS[i], n = INTERIOR_PHOTOS.length;
  openModal(`<div class="ph-big"><img src="${phUrl(p)}" alt=""><div class="flex mt"><b>${STYLES[p.style].name} · ${p.room}</b><span class="right"></span>
    <button data-act="phOpen" data-i="${(i - 1 + n) % n}">←</button><button data-act="phOpen" data-i="${(i + 1) % n}">→</button>
    <a class="btn btn-p" href="${phUrl(p)}" target="_blank" rel="noopener">⬇ Открыть в полном размере</a>
    <button class="btn-p" data-act="ph360" data-style="${p.style}" data-title="${STYLES[p.style].name} · ${p.room}">🌐 Осмотреться 360°</button><button data-act="phStyle" data-style="${p.style}">🛋 Этот стиль в 3D</button></div></div>`);
  $('.modal-box').classList.add('wide');
};
ACT.phStyle = d => { closeModal(); ACT.d3Style({ style: d.style }); };
