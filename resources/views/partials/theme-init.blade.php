<meta name="color-scheme" content="light dark">
<script>
/* Тема: выбор пользователя или системная; ставится до отрисовки, чтобы не мигало */
(function () {
  var t = null; try { t = localStorage.getItem('oftob_theme'); } catch (e) {}
  if (t !== 'light' && t !== 'dark') t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', t);
  window.toggleTheme = function () {
    var n = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', n);
    try { localStorage.setItem('oftob_theme', n); } catch (e) {}
    window.dispatchEvent(new Event('themechange'));
  };
})();
</script>
