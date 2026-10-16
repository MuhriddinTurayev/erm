/* Oftob — свои диалоги вместо системных alert / confirm / prompt (они выглядят чужеродно и пишут «Сообщение с oftob.local»).
   ui.confirm(text, {ok, danger})           → Promise<boolean>
   ui.prompt(title, {value, label, placeholder, multiline, ok}) → Promise<string|null>
   ui.alert(text)                           → Promise<void>
   ui.form(title, fields, {ok, text})       → Promise<object|null>
     поле: {name, label, type: text|textarea|number|date|select|readonly, value, placeholder, options: [[v, label]], required, hint, min, max}
   Формы: <form data-confirm="Удалить?"> и <form data-prompt="Причина" data-prompt-field="reason"> обрабатываются автоматически. */
(() => {
  const L = { ru: { ok: 'OK', cancel: 'Отмена', yes: 'Да', del: 'Удалить', save: 'Сохранить', close: 'Закрыть', copy: 'Копировать', copied: 'Скопировано', req: 'Заполните поле' },
    uz: { ok: 'OK', cancel: 'Bekor qilish', yes: 'Ha', del: 'O‘chirish', save: 'Saqlash', close: 'Yopish', copy: 'Nusxa olish', copied: 'Nusxa olindi', req: 'Maydonni to‘ldiring' },
    tg: { ok: 'OK', cancel: 'Бекор кардан', yes: 'Ҳа', del: 'Нест кардан', save: 'Сабт кардан', close: 'Пӯшидан', copy: 'Нусха гирифтан', copied: 'Нусха гирифта шуд', req: 'Майдонро пур кунед' } };
  const lang = () => { const l = (window.LANG || (typeof DB !== 'undefined' && DB.settings && DB.settings.lang) || document.documentElement.lang || 'ru').slice(0, 2); return L[l] ? l : 'ru'; };
  const W = k => L[lang()][k];
  const tr = s => (typeof window.T === 'function' ? window.T(String(s)) : String(s));   // в программе /crm — словарь T()
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const DANGER = /удал|сброс|отвяз|очист|отмен(ить|а выпл)|delete|o[‘'`]?chir|bekor|нест|хориҷ|тоза/i;

  const css = `.ui-dlg{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);animation:uiF .12s ease-out}
.ui-dlg .ui-box{width:100%;max-width:460px;max-height:calc(100vh - 32px);overflow:auto;background:var(--surface,#fff);color:var(--text,#0f172a);border:1px solid var(--border,#dde3ec);border-radius:14px;box-shadow:0 24px 60px -12px rgba(15,23,42,.35);font-family:var(--font,system-ui,sans-serif);animation:uiU .14s ease-out}
.ui-dlg .ui-h{padding:18px 20px 4px;font-size:17px;font-weight:650;line-height:1.35}
.ui-dlg .ui-t{padding:8px 20px 0;font-size:14.5px;line-height:1.55;color:var(--text-2,#334155);white-space:pre-line}
.ui-dlg .ui-f{display:grid;gap:12px;padding:14px 20px 4px}
.ui-dlg label{display:grid;gap:6px;font-size:13px;font-weight:600;color:var(--text-2,#334155)}
.ui-dlg input,.ui-dlg select,.ui-dlg textarea{width:100%;box-sizing:border-box;font:inherit;font-size:15px;font-weight:400;color:var(--text,#0f172a);background:var(--surface-2,#f8fafc);border:1px solid var(--border,#dde3ec);border-radius:9px;padding:10px 12px;outline:none}
.ui-dlg textarea{min-height:90px;resize:vertical}
.ui-dlg input:focus,.ui-dlg select:focus,.ui-dlg textarea:focus{border-color:var(--accent,#1d4ed8);box-shadow:0 0 0 3px var(--ring,rgba(37,99,235,.2))}
.ui-dlg input[readonly]{background:var(--sunken,#eef2f7)}
.ui-dlg small{font-weight:400;color:var(--muted,#64748b)}
.ui-dlg .ui-err{border-color:var(--bad,#c81e1e)!important}
.ui-dlg .ui-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.ui-dlg .ui-b{display:flex;justify-content:flex-end;gap:8px;padding:18px 20px 18px;flex-wrap:wrap}
.ui-dlg button{font:inherit;font-size:14.5px;font-weight:600;border-radius:9px;padding:10px 18px;cursor:pointer;border:1px solid var(--border,#dde3ec);background:var(--surface,#fff);color:var(--text,#0f172a)}
.ui-dlg button:hover{background:var(--hover,#f1f5f9)}
.ui-dlg button.ui-p{background:var(--accent,#1d4ed8);border-color:var(--accent,#1d4ed8);color:var(--on-accent,#fff)}
.ui-dlg button.ui-p:hover{background:var(--accent-hover,#1e40af)}
.ui-dlg button.ui-d{background:var(--bad,#c81e1e);border-color:var(--bad,#c81e1e);color:#fff}
@media (max-width:520px){.ui-dlg{align-items:flex-end;padding:0}.ui-dlg .ui-box{max-width:none;border-radius:16px 16px 0 0}.ui-dlg .ui-row{grid-template-columns:1fr}.ui-dlg .ui-b button{flex:1}}
@keyframes uiF{from{opacity:0}}@keyframes uiU{from{transform:translateY(8px);opacity:0}}`;
  const st = document.createElement('style'); st.textContent = css; (document.head || document.documentElement).appendChild(st);

  function open({ title, text, fields = [], ok, cancel = true, danger = false }) {
    return new Promise(res => {
      const prevFocus = document.activeElement;
      const el = document.createElement('div'); el.className = 'ui-dlg'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
      const field = f => {
        const v = esc(f.value ?? ''), ph = f.placeholder ? ` placeholder="${esc(tr(f.placeholder))}"` : '', req = f.required ? ' required' : '';
        let inp;
        if (f.type === 'select') inp = `<select name="${f.name}">${(f.options || []).map(([ov, ol]) => `<option value="${esc(ov)}"${String(ov) === String(f.value ?? '') ? ' selected' : ''}>${esc(tr(ol))}</option>`).join('')}</select>`;
        else if (f.type === 'textarea') inp = `<textarea name="${f.name}"${ph}${req}>${v}</textarea>`;
        else inp = `<input name="${f.name}" type="${f.type === 'readonly' ? 'text' : (f.type || 'text')}" value="${v}"${ph}${req}${f.type === 'readonly' ? ' readonly' : ''}${f.min != null ? ` min="${f.min}"` : ''}${f.max != null ? ` max="${f.max}"` : ''}${f.step ? ` step="${f.step}"` : ''}>`;
        return `<label>${f.label ? esc(tr(f.label)) : ''}${inp}${f.hint ? `<small>${esc(tr(f.hint))}</small>` : ''}</label>`;
      };
      // поля по два в строку, если отмечены half
      let fh = '', buf = [];
      for (const f of fields) { if (f.half) { buf.push(field(f)); if (buf.length === 2) { fh += `<div class="ui-row">${buf.join('')}</div>`; buf = []; } } else { if (buf.length) { fh += `<div class="ui-row">${buf.join('')}</div>`; buf = []; } fh += field(f); } }
      if (buf.length) fh += `<div class="ui-row">${buf.join('')}</div>`;
      el.innerHTML = `<form class="ui-box" novalidate>${title ? `<div class="ui-h">${esc(tr(title))}</div>` : ''}${text ? `<div class="ui-t">${esc(tr(text))}</div>` : ''}${fields.length ? `<div class="ui-f">${fh}</div>` : ''}
        <div class="ui-b">${cancel ? `<button type="button" data-x>${esc(typeof cancel === 'string' ? cancel : W('cancel'))}</button>` : ''}<button type="submit" class="${danger ? 'ui-d' : 'ui-p'}">${esc(ok || W('ok'))}</button></div></form>`;
      const form = el.firstElementChild;
      const done = v => { el.remove(); document.removeEventListener('keydown', key, true); try { prevFocus && prevFocus.focus && prevFocus.focus(); } catch (e) {} res(v); };
      const key = e => { if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); done(cancel ? null : true); } };
      document.addEventListener('keydown', key, true);
      el.addEventListener('mousedown', e => { if (e.target === el && cancel) done(null); });
      form.querySelector('[data-x]')?.addEventListener('click', () => done(null));
      form.addEventListener('submit', e => {
        e.preventDefault(); if (!fields.length) return done(true);
        const out = {}; let bad = null;
        for (const f of fields) { const i = form.elements[f.name]; i.classList.remove('ui-err'); out[f.name] = f.type === 'number' ? (i.value === '' ? '' : +i.value) : i.value.trim(); if (f.required && out[f.name] === '' && !bad) bad = i; }
        if (bad) { bad.classList.add('ui-err'); bad.focus(); bad.title = W('req'); return; }
        done(out);
      });
      // открытое модальное окно <dialog> лежит «поверх всего» и блокирует остальную страницу — тогда показываемся внутри него
      const host = [...document.querySelectorAll('dialog[open]')].pop() || document.body;
      host.appendChild(el);
      const first = form.querySelector('input:not([readonly]),select,textarea') || form.querySelector('input') || form.querySelector('button[type=submit]');
      setTimeout(() => { first.focus(); if (first.select && first.tagName === 'INPUT') first.select(); }, 20);
    });
  }

  const ui = {
    confirm: (text, o = {}) => {
      const dg = o.danger ?? DANGER.test(tr(text)), del = /удал|delete|o[‘'`]?chir|нест/i.test(tr(text));
      return open({ title: o.title, text, ok: o.ok || (dg && del ? W('del') : W('yes')), danger: dg }).then(v => v === true);
    },
    alert: (text, o = {}) => open({ title: o.title, text, cancel: false, ok: W('ok') }).then(() => undefined),
    prompt: (title, o = {}) => open({ title, text: o.text, ok: o.ok || W('save'), fields: [{ name: 'v', label: o.label, value: o.value ?? '', placeholder: o.placeholder, type: o.multiline ? 'textarea' : 'text', required: o.required }] }).then(v => v ? v.v : null),
    copy: (title, value) => open({ title, ok: W('copy'), fields: [{ name: 'v', type: 'readonly', value }] }).then(v => { if (v && navigator.clipboard) navigator.clipboard.writeText(value).catch(() => {}); }),
    form: (title, fields, o = {}) => open({ title, text: o.text, fields, ok: o.ok || W('save') }),
  };
  window.ui = ui;

  // формы с подтверждением / вопросом — в фазе захвата, раньше остальных обработчиков
  document.addEventListener('submit', async e => {
    const f = e.target; if (!(f instanceof HTMLFormElement) || f.dataset.uiOk === '1') { if (f.dataset) delete f.dataset.uiOk; return; }
    const q = f.dataset.confirm, p = f.dataset.prompt; if (!q && !p) return;
    e.preventDefault(); e.stopImmediatePropagation();
    if (q && !(await ui.confirm(q))) return;
    if (p) { const v = await ui.prompt(p, { multiline: true, required: f.dataset.promptRequired === '1' }); if (v === null) return; const inp = f.elements[f.dataset.promptField || 'reason']; if (inp) inp.value = v; }
    f.dataset.uiOk = '1'; if (f.requestSubmit) f.requestSubmit(e.submitter && e.submitter.form === f ? e.submitter : undefined); else f.submit();
  }, true);
})();

/* Форма уходит один раз: повторное нажатие «Сохранить / Оплатить / Провести» не отправляет её второй раз.
   У каждой отправки — одноразовый номер _once: даже если запрос всё же придёт дважды, сервер второй не выполнит (OnceSubmit). */
(() => {
  const uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12));
  const busy = (f, on) => f.querySelectorAll('button:not([type=button]), input[type=submit]').forEach(b => b.classList.toggle('is-busy', on));
  // true — можно отправлять; false — эта форма уже ушла
  const guard = f => {
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return true;
    if (f.dataset.sending === '1') return false;
    f.dataset.sending = '1';
    let i = f.querySelector('input[name="_once"]');
    if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = '_once'; i.value = uid(); f.appendChild(i); }
    busy(f, true);
    // ответ без перехода (скачивание файла, сеть пропала) — через 8 с форму снова можно отправить (номер тот же: сервер не выполнит дважды)
    clearTimeout(f._onceT); f._onceT = setTimeout(() => { delete f.dataset.sending; busy(f, false); }, 8000);
    return true;
  };
  document.addEventListener('submit', e => {
    const f = e.target; if (!(f instanceof HTMLFormElement) || e.defaultPrevented) return;
    if (!guard(f)) e.preventDefault();
  });
  const native = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { if (guard(this)) native.call(this); };
  // «Назад» в браузере вернул страницу из кэша — формы снова готовы, с новым номером
  window.addEventListener('pageshow', e => {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-sending]').forEach(f => { delete f.dataset.sending; busy(f, false); f.querySelector('input[name="_once"]')?.remove(); });
  });
})();

/* Список с пунктом «✎ Своё…» (select[data-free]): выбрали — спрашиваем значение и добавляем его в список.
   ensureOpt(select, value) — выбрать значение, даже если его нет в списке (своё, введённое раньше). */
(() => {
  const addOpt = (s, v) => { if (![...s.options].some(o => o.value === v)) { const o = new Option(v, v); s.insertBefore(o, s.querySelector('option[value="__other"]')); } };
  window.ensureOpt = (s, v) => { if (!s) return; if (v) addOpt(s, v); s.value = v || ''; };
  document.addEventListener('focusin', e => { const s = e.target; if (s instanceof HTMLSelectElement && s.dataset.free != null && s.value !== '__other') s.dataset.prev = s.value; });
  document.addEventListener('change', async e => {
    const s = e.target; if (!(s instanceof HTMLSelectElement) || s.value !== '__other') return;
    const v = window.ui ? await ui.prompt(s.dataset.free || '…', { required: true }) : prompt(s.dataset.free || '…');
    const t = (v || '').trim().slice(0, 60);
    if (!t) { s.value = s.dataset.prev || ''; return; }
    addOpt(s, t); s.value = t; s.dataset.prev = t; s.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();