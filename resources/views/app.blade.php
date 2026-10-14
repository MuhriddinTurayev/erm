@php
    $v = fn ($f) => asset('app/'.$f).'?v='.@filemtime(public_path('app/'.$f));
    $u = auth()->user();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.theme-init')
<title>{{ $ws->name }} — {{ $boot['brand'] }}</title>
<link rel="icon" type="image/png" href="/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('assets/tokens.css') }}?v={{ @filemtime(public_path('assets/tokens.css')) }}">
<link rel="stylesheet" href="{{ $v('css/style.css') }}">
<link rel="stylesheet" href="{{ $v('css/theme.css') }}">
<script src="{{ asset('assets/dialog.js') }}?v={{ @filemtime(public_path('assets/dialog.js')) }}" defer></script>
<link rel="manifest" href="/manifest.webmanifest"><link rel="apple-touch-icon" href="/icons/apple-touch-icon.png"><meta name="theme-color" content="#6c4cf1">
<script>if ('serviceWorker' in navigator && location.protocol === 'https:') addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));</script>
</head>
<body>
@if (session('impersonator'))
<div class="imp-bar notr">{!! ic('eye', '', 16) !!}<span>{{ __('Вы вошли как :n (:c)', ['n' => $u->name, 'c' => $ws->name]) }}</span><form method="post" action="/impersonate/stop">@csrf<button>{{ __('Вернуться в админку') }}</button></form></div>
@endif
<aside id="sidebar">
  <div class="brand" data-act="go" data-page="home"><span class="b-mark"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="14" r="5" fill="currentColor"/><path d="M12 3v2.5M4.2 6.7l1.8 1.8M19.8 6.7 18 8.5M2 14h2.5M19.5 14H22M3 21h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span><span class="b-t notr">{{ $boot['brand'] }}<small>{{ $ws->name }}</small></span></div>
  <nav id="nav"></nav>
  <div class="side-foot" id="sideFoot"></div>
</aside>
<div id="app">
  <header id="topbar">
    <button id="menuBtn" data-act="menu" aria-label="Menu">{!! ic('menu', '', 18) !!}</button>
    <button id="homeBtn" data-act="go" data-page="home" title="На главную">{!! ic('house', '', 18) !!}</button><div id="pageTitle"></div>
    <div class="proj-select">
      <span class="muted">Объект:</span>
      <select id="projSel"></select>
      <button data-act="newProject" title="Добавить объект">{!! ic('plus', '', 18) !!}</button>
    </div>
    <select id="langSel" title="Язык / Til / Забон"></select>
    <span id="saveState"></span>
    @if ($u->canModule('monitoring'))<a class="erp-btn notr" href="/company/monitor" title="{{ __('Мониторинг') }}">{!! ic('layout-dashboard', '', 16) !!}<span>{{ __('Панель ERP') }}</span></a>@endif
    <button class="icon-btn" onclick="toggleTheme()" title="{{ __('Светлая / тёмная тема') }}">{!! ic('moon', 'only-light', 18) !!}{!! ic('sun', 'only-dark', 18) !!}</button>
    <div class="u-menu notr">
      <button class="u-btn" onclick="this.parentElement.classList.toggle('open')"><span class="u-av">{{ initials($u->name) }}</span><span class="u-nm">{{ $u->name }}</span>{!! ic('chevron-down') !!}</button>
      <div class="u-drop">
        <div class="u-head"><b>{{ $u->name }}</b><span>{{ $u->email }}</span><span>{{ $u->roleName() }}@unless ($u->canEdit()) · {{ __('только просмотр') }}@endunless</span></div>
        @foreach ([['monitoring', '/company/monitor', 'gauge', 'Мониторинг'], ['objects', '/company/objects', 'building-2', 'Объекты и 3D'], ['sales', '/company/sales', 'key-round', 'Продажи квартир'], ['cash', '/company/cash', 'wallet', 'Кассы и счета'], ['payments', '/company/payments', 'file-check', 'Заявки на оплату'],
                   ['tasks', '/company/tasks', 'list-checks', 'Задачи'], ['warehouse', '/company/stock', 'warehouse', 'Склад'], ['supply', '/company/supply', 'truck', 'Снабжение'],
                   ['counterparties', '/company/parties', 'contact', 'Контрагенты'], ['contracts', '/company/contracts', 'file-pen-line', 'Договоры'], ['journal', '/company/journal', 'camera', 'Журнал и фотоотчёты']] as [$mk, $href, $mi, $mn])
          @if ($u->canModule($mk))<a href="{{ $href }}">{!! ic($mi) !!}{{ __($mn) }}</a>@endif
        @endforeach
        @if ($u->seesMoney())
          <a href="/company/overview">{!! ic('chart-pie') !!}{{ __('Сводка по объектам') }}</a>
          <a href="/company/reports">{!! ic('send') !!}{{ __('Ежедневные отчёты') }}</a>
        @endif
        @if ($u->isAdmin())
          <a href="/company/users">{!! ic('users') !!}{{ __('Пользователи и роли') }}</a>
          <a href="/company/settings">{!! ic('settings') !!}{{ __('Настройки компании') }}</a>
          <a href="/company/billing">{!! ic('credit-card') !!}{{ __('Тариф') }}: {{ $boot['plan']['name'] }}</a>
        @endif
        <a href="/company/guide">{!! ic('circle-help') !!}{{ __('Кто что вносит') }}</a>
        <a href="/me/telegram">{!! ic('send') !!}{{ __('Мой Telegram') }}</a>
        <a href="/security">{!! ic('shield-check') !!}{{ __('Безопасность') }}</a>
        <form method="post" action="/logout">@csrf<button type="submit">{!! ic('log-out') !!}{{ __('Выйти') }}</button></form>
      </div>
    </div>
  </header>
  <main id="main"></main>
</div>
<div id="modal" class="modal hidden"><div class="modal-box"><button class="modal-x" data-act="closeModal" aria-label="Close">×</button><div id="modalBody"></div></div></div>
<div id="toast"></div>
<button id="aiFab" data-act="aiOpen" title="ИИ-консультант">{!! ic('sparkles') !!}<span>ИИ-консультант</span></button>

<script>
window.OFTOB = @json($boot);
window.OFTOB.state = @json($state);
document.addEventListener('click', e => { const m = document.querySelector('.u-menu.open'); if (m && !m.contains(e.target)) m.classList.remove('open'); });
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/geometries/RoundedBoxGeometry.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/utils/BufferGeometryUtils.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/lights/RectAreaLightUniformsLib.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/environments/RoomEnvironment.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/shaders/CopyShader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/shaders/SSAOShader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/shaders/LuminosityHighPassShader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/shaders/GammaCorrectionShader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/shaders/FXAAShader.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/math/SimplexNoise.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/postprocessing/EffectComposer.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/postprocessing/RenderPass.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/postprocessing/ShaderPass.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/postprocessing/SSAOPass.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/postprocessing/UnrealBloomPass.js"></script>
@foreach (['core', 'icons', 'iconize', 'server', 'i18n', 'i18n_dict', 'i18n_extra', 'i18n_fix', 'calc', 'data', 'pages', 'pages2', 'geo', 'ai', 'hiring', 'safety', 'legal', 'finance', 'home', 'design3d', 'photos', 'pano360', 'site3d', 'aptshow', 'aptroute', 'leads', 'attend', 'saas', 'app'] as $f)
<script src="{{ $v('js/'.$f.'.js') }}"></script>
@endforeach
</body>
</html>
