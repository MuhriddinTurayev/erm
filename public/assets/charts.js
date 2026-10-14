/* Графики панели на Chart.js (assets/vendor/chart.umd.js, MIT).
   Разметка: <div class="chart-box" data-chart='{"type":"bar|line|donut|hbar","labels":[…],"series":[{"name":"…","data":[…],"color":"c1"}],"money":"сум"}'></div>
   Цвета — из токенов темы (--c1…--c8), при смене темы графики перерисовываются. Суммы на осях — коротко (12,5 млн), в подсказке — полностью. */
(function () {
  'use strict';
  const LOC = (document.documentElement.lang || 'ru').slice(0, 2);
  const UNITS = { uz: ['ming', 'mln', 'mlrd'], tg: ['ҳаз.', 'млн', 'млрд'], ru: ['тыс.', 'млн', 'млрд'] }[LOC] || ['тыс.', 'млн', 'млрд'];
  const css = (n) => getComputedStyle(document.documentElement).getPropertyValue('--' + n).trim();
  const color = (c) => (c && c[0] === '#') || /^rgb/.test(c || '') ? c : css(c || 'c1') || '#6c4cf1';
  const alpha = (hex, a) => {
    const h = hex.replace('#', '');
    if (h.length !== 6) return hex;
    return `rgba(${parseInt(h.slice(0, 2), 16)},${parseInt(h.slice(2, 4), 16)},${parseInt(h.slice(4, 6), 16)},${a})`;
  };
  const num = (v, d = 0) => Number(v).toLocaleString('ru-RU', { maximumFractionDigits: d, minimumFractionDigits: 0 }).replace(/,/g, ',');
  // 12 500 000 → «12,5 млн»
  function short(v) {
    const a = Math.abs(v), s = v < 0 ? '−' : '';
    if (a >= 1e9) return s + num(a / 1e9, a >= 1e10 ? 1 : 2) + ' ' + UNITS[2];
    if (a >= 1e6) return s + num(a / 1e6, a >= 1e7 ? 0 : 1) + ' ' + UNITS[1];
    if (a >= 1e4) return s + num(a / 1e3, 0) + ' ' + UNITS[0];
    return s + num(a, a < 10 ? 1 : 0);
  }
  const full = (v, cur) => (v < 0 ? '−' : '') + num(Math.abs(v)) + (cur ? ' ' + cur : '');
  window.oftobShort = short;

  // подпись в центре кольца: крупное число и подпись под ним
  const centerText = {
    id: 'centerText',
    afterDraw(chart, _a, o) {
      if (!o || !o.value) return;
      const { ctx, chartArea: c } = chart, x = (c.left + c.right) / 2, y = (c.top + c.bottom) / 2;
      ctx.save();
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillStyle = css('text');
      // текст должен поместиться внутри кольца
      const arc = chart.getDatasetMeta(0).data[0];
      const inner = arc && arc.innerRadius ? arc.innerRadius : (c.right - c.left) / 3;
      let big = Math.min(24, inner / 2.6);
      ctx.font = `700 ${big}px Montserrat, Inter, sans-serif`;
      const w = ctx.measureText(o.value).width;
      if (w > inner * 1.55) { big = Math.max(11, big * (inner * 1.55) / w); ctx.font = `700 ${big}px Montserrat, Inter, sans-serif`; }
      ctx.fillText(o.value, x, o.label ? y - big * 0.35 : y);
      if (o.label) { ctx.fillStyle = css('muted'); ctx.font = `500 ${Math.max(11, big * 0.48)}px Inter, sans-serif`; ctx.fillText(o.label, x, y + big * 0.6); }
      ctx.restore();
    },
  };

  function build(el) {
    let d;
    try { d = JSON.parse(el.dataset.chart); } catch (e) { return; }
    if (!window.Chart) return;
    el.innerHTML = '';
    // переключатель рядов: показываем один ряд за раз (когда суммы рядов несравнимы — расходы и крупные поступления)
    if (d.toggle && (d.series || []).length > 1) {
      el._sel = el._sel || 0;
      if (el._seg) el._seg.remove();
      const seg = el._seg = document.createElement('div');
      seg.className = 'chart-seg';
      d.series.forEach((s, i) => {
        const b = document.createElement('button');
        b.type = 'button'; b.textContent = s.name; b.className = i === el._sel ? 'on' : '';
        b.style.setProperty('--c', color(s.color || 'c' + (i + 1)));
        b.addEventListener('click', () => { el._sel = i; build(el); });
        seg.appendChild(b);
      });
      el.parentNode.insertBefore(seg, el);
      d = Object.assign({}, d, { series: [d.series[el._sel]], legend: false });
    }
    const cv = document.createElement('canvas');
    cv.setAttribute('role', 'img');
    if (d.title) cv.setAttribute('aria-label', d.title);
    el.appendChild(cv);
    const grid = css('border-2') || '#eee', tick = css('muted') || '#777', money = d.money || '';
    const type = d.type || 'bar';
    const fmtAxis = (v) => (money || d.short ? short(v) : num(v)) + (d.suffix || '');
    const fmtTip = (v) => (money ? full(v, money) : num(v, 2) + (d.suffix || ''));
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = tick;
    const tooltip = {
      backgroundColor: css('text'), titleColor: css('surface'), bodyColor: css('surface'), padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true,
      titleFont: { weight: '600' },
      callbacks: {
        label: (c) => {
          const v = type === 'donut' ? c.parsed : (type === 'hbar' ? c.parsed.x : c.parsed.y);
          return ' ' + (c.dataset.label ? c.dataset.label + ': ' : (type === 'donut' ? c.label + ': ' : '')) + fmtTip(v);
        },
      },
    };
    const series = d.series || [];
    let cfg;
    if (type === 'donut') {
      const s = series[0] || { data: [] };
      const cols = (s.colors || []).map(color);
      cfg = {
        type: 'doughnut',
        data: { labels: d.labels, datasets: [{ data: s.data, backgroundColor: cols, borderColor: css('surface'), borderWidth: 3, hoverOffset: 6, borderRadius: 4, spacing: 1 }] },
        options: { cutout: d.cutout || '74%', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip, centerText: d.center || null }, animation: { animateRotate: true, duration: 700 } },
        plugins: [centerText],
      };
    } else {
      const horiz = type === 'hbar';
      const datasets = series.map((s, i) => {
        const c = color(s.color || 'c' + (i + 1));
        const neg = s.neg ? color(s.neg) : null;
        const base = { label: s.name || '', data: s.data, borderColor: c, yAxisID: s.axis === 'y1' && !horiz ? 'y1' : (horiz ? 'x' : 'y') };
        if (horiz) delete base.yAxisID;
        if (s.type === 'line' || type === 'line') {
          return Object.assign(base, {
            type: 'line', tension: 0.38, borderWidth: 2.5, pointRadius: s.points === false ? 0 : 3, pointHoverRadius: 5, pointBackgroundColor: css('surface'), pointBorderWidth: 2,
            fill: s.fill === false ? false : 'origin', order: 0, borderDash: s.dash ? [6, 5] : undefined,
            backgroundColor: (ctx) => {
              if (s.fill === false) return 'transparent';
              const a = ctx.chart.chartArea; if (!a) return alpha(c, 0.12);
              const g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom); g.addColorStop(0, alpha(c, 0.28)); g.addColorStop(1, alpha(c, 0.01)); return g;
            },
            segment: neg ? { borderColor: (ctx) => (ctx.p1.parsed.y < 0 || ctx.p0.parsed.y < 0 ? neg : c) } : undefined,
          });
        }
        const soft = s.soft ? alpha(c, 0.35) : c;
        return Object.assign(base, {
          type: 'bar', borderWidth: 0, borderRadius: horiz ? 6 : 7, borderSkipped: false, maxBarThickness: horiz ? 18 : (d.thin ? 14 : 30), categoryPercentage: 0.7, barPercentage: 0.85, order: 1,
          backgroundColor: neg ? s.data.map((v) => (v < 0 ? neg : soft)) : (s.highlightLast ? s.data.map((_, j) => (j === s.data.length - 1 ? c : alpha(c, 0.45))) : soft),
          hoverBackgroundColor: neg ? s.data.map((v) => (v < 0 ? neg : c)) : c,
        });
      });
      const valueAxis = {
        beginAtZero: true, grid: { color: grid, drawTicks: false }, border: { display: false },
        ticks: { padding: 8, maxTicksLimit: 6, callback: fmtAxis }, stacked: !!d.stacked,
      };
      const catAxis = { grid: { display: false }, border: { display: false }, ticks: { padding: 6, maxRotation: 0, autoSkipPadding: 10 }, stacked: !!d.stacked };
      cfg = {
        type: 'bar',
        data: { labels: d.labels, datasets },
        options: {
          indexAxis: horiz ? 'y' : 'x', maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          // второй ряд со своей шкалой справа (например, остаток в кассах поверх недельных оборотов)
          scales: horiz ? { x: valueAxis, y: catAxis } : Object.assign({ x: catAxis, y: valueAxis },
            series.some((s) => s.axis === 'y1') ? { y1: Object.assign({}, valueAxis, { position: 'right', beginAtZero: false, grid: { display: false }, border: { display: false } }) } : {}),
          plugins: {
            legend: { display: series.length > 1 && d.legend !== false, position: 'bottom', align: 'start', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16, color: css('text-2'), font: { weight: '500' } } },
            tooltip,
          },
          animation: { duration: 600 },
        },
      };
    }
    if (el._chart) el._chart.destroy();
    el._chart = new Chart(cv, cfg);
  }

  function all() { document.querySelectorAll('[data-chart]').forEach(build); }
  window.oftobCharts = { build, all, short };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
  window.addEventListener('themechange', () => setTimeout(all, 30));
})();
