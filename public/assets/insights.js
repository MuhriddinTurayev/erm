/* Карточка «ИИ-анализ» (partials/insights.blade.php): сразу выводы по правилам, затем — разбор ИИ, если он подключён.
   GET /company/insights/{topic}?mode=fast|ai|refresh[&o=объект] → {source, summary, items[{level,title,text,href}], ai_pending, at, note} */
(function () {
  'use strict';
  const svg = (p) => `<svg class="ic" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${p}</svg>`;
  const ICON = {
    bad: svg('<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>'),
    warn: svg('<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>'),
    ok: svg('<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'),
    info: svg('<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>'),
    go: svg('<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>'),
    ai: svg('<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>'),
  };
  const T = () => window.OFTOB_AI_T || {};
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  function draw(card, r, thinking) {
    const body = card.querySelector('[data-ai-body]'), src = card.querySelector('[data-ai-src]');
    const items = (r.items || []).map((i) => `<div class="ai-item ai-${esc(i.level)}"><span class="ai-ic">${ICON[i.level] || ICON.info}</span><div><b>${esc(i.title)}</b><p>${esc(i.text)}</p>${i.href ? `<a class="ai-go" href="${esc(i.href)}">${esc(T().go)}${ICON.go}</a>` : ''}</div></div>`).join('');
    body.innerHTML = (r.summary ? `<p class="ai-sum">${esc(r.summary)}</p>` : '') + `<div class="ai-list">${items}</div>`
      + (thinking ? `<div class="ai-load" style="margin-top:12px"><i></i>${esc(T().thinking)}</div>` : '')
      + (r.note ? `<div class="ai-foot"><span>${esc(r.note)}</span></div>` : '');
    if (src) src.innerHTML = r.source === 'ai' ? `${ICON.ai}${esc(T().ai)} · ${esc(T().at)} ${esc(r.at || '')}` : esc(T().rules);
  }

  async function load(card, mode) {
    const url = '/company/insights/' + encodeURIComponent(card.dataset.insights) + '?mode=' + mode + (card.dataset.o ? '&o=' + encodeURIComponent(card.dataset.o) : '');
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error(res.status);
    return res.json();
  }

  async function run(card, refresh) {
    const btn = card.querySelector('[data-ai-refresh]');
    if (btn) btn.disabled = true;
    try {
      let r = refresh ? null : await load(card, 'fast');
      if (r) draw(card, r, r.ai_pending);
      if (refresh || (r && r.ai_pending)) {
        if (refresh) card.querySelector('[data-ai-body]').insertAdjacentHTML('beforeend', `<div class="ai-load" style="margin-top:12px"><i></i>${esc(T().thinking)}</div>`);
        r = await load(card, refresh ? 'refresh' : 'ai');
        draw(card, r, false);
      }
    } catch (e) {
      card.querySelector('[data-ai-body]').innerHTML = `<div class="ai-foot"><span>${esc(T().err)}</span></div>`;
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function init() {
    document.querySelectorAll('[data-insights]').forEach((card) => {
      run(card, false);
      const b = card.querySelector('[data-ai-refresh]');
      if (b) b.addEventListener('click', () => run(card, true));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
