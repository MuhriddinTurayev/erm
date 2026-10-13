@php
    $brand = \App\Models\PlatformSetting::brand();
    $u = auth()->user();
    $appUrl = $u ? ($u->isSuperAdmin() ? '/admin' : '/crm') : null;
    $regOpen = \App\Models\PlatformSetting::get('registration_open') === '1';
    $support = ['phone' => \App\Models\PlatformSetting::get('support_phone'), 'telegram' => \App\Models\PlatformSetting::get('support_telegram'), 'email' => \App\Models\PlatformSetting::get('support_email')];
    $loc = app()->getLocale();
    $path = '/'.ltrim(request()->path(), '/');
    $canon = url($path === '/' ? '/' : $path).($loc !== 'ru' ? '?lang='.$loc : '');
    $ogImage = \App\Models\PlatformSetting::get('seo_image') ?: asset('assets/og.png');
    $isHome = $path === '/';
    $h = fn ($a) => $isHome ? $a : '/'.$a;
@endphp
<!doctype html>
<html lang="{{ $loc }}">
<head>
@include('partials.head')
<link rel="stylesheet" href="{{ asset('assets/site.css') }}?v={{ @filemtime(public_path('assets/site.css')) }}">
<script>document.documentElement.classList.add('js')</script>
<title>@yield('title')</title>
<meta name="description" content="@yield('description')">
<link rel="canonical" href="{{ $canon }}">
@foreach (config('oftob.locales') as $l => $x)<link rel="alternate" hreflang="{{ $l }}" href="{{ url($path === '/' ? '/' : $path).'?lang='.$l }}">
@endforeach<link rel="alternate" hreflang="x-default" href="{{ url($path === '/' ? '/' : $path) }}">
<meta property="og:type" content="@yield('og_type', 'website')">
<meta property="og:site_name" content="{{ $brand }}">
<meta property="og:title" content="@yield('title')">
<meta property="og:description" content="@yield('description')">
<meta property="og:url" content="{{ $canon }}">
<meta property="og:image" content="@yield('og_image', $ogImage)">
<meta property="og:locale" content="{{ ['ru' => 'ru_RU', 'uz' => 'uz_UZ', 'tg' => 'tg_TJ'][$loc] ?? 'ru_RU' }}">
<meta name="twitter:card" content="summary_large_image">
@stack('head')
@if ($ga = \App\Models\PlatformSetting::get('ga_id'))
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $ga }}"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config',@json($ga));</script>
@endif
@if ($ym = \App\Models\PlatformSetting::get('ym_id'))
<script>(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");ym({{ (int) $ym }},"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true});</script>
@endif
</head>
<body class="site">

<header class="nav{{ $appUrl ? ' authed' : '' }}" id="nav" data-cms="Шапка и меню">
  <div class="wrap">
    <a href="/" style="text-decoration:none" aria-label="{{ $brand }}">@include('partials.logo')</a>
    <nav class="links" id="site-menu">
      <a href="{{ $h('#leaks') }}">{{ st('Потери') }}</a>
      <a href="{{ $h('#math') }}">{{ st('Калькулятор') }}</a>
      <a href="{{ $h('#modules') }}">{{ st('Модули') }}</a>
      <a href="{{ $h('#pricing') }}">{{ st('Тарифы') }}</a>
      <a href="/blog">{{ st('Блог') }}</a>
      <a href="{{ $h('#faq') }}">{{ st('Вопросы') }}</a>
      {{-- на телефоне язык, тема и заявка переезжают сюда, в меню: в шапке остаются логотип, «Войти» и меню --}}
      <div class="menu-extra">
        <div class="menu-tools">@include('partials.lang')@include('partials.theme-btn')</div>
        @unless ($appUrl)<a class="btn btn-p btn-l" href="{{ $h('#demo') }}">{{ st('Бесплатный разбор') }}</a>@endunless
      </div>
    </nav>
    <div class="act">
      @include('partials.lang')
      @include('partials.theme-btn')
      @if ($appUrl)
        <a class="btn btn-p" href="{{ $appUrl }}">{{ st('Открыть программу') }}{!! ic('arrow-right') !!}</a>
      @else
        <a class="btn btn-ghost login" href="/login">{!! ic('log-in', 'login-ic') !!}{{ st('Войти') }}</a>
        <a class="btn btn-p cta" href="{{ $h('#demo') }}">{{ st('Бесплатный разбор') }}</a>
      @endif
      <button type="button" class="icon-btn burger" aria-label="Menu" aria-controls="site-menu" aria-expanded="false">{!! ic('menu', 'i-open') !!}{!! ic('x', 'i-close') !!}</button>
    </div>
  </div>
</header>

<main>
@yield('content')
</main>

<footer class="foot" data-cms="Подвал сайта">
  <div class="wrap">
    <div><a href="/" style="text-decoration:none">@include('partials.logo')</a>
      <p class="muted" style="max-width:340px;margin-top:14px;font-size:14px">{{ st('ERP и CRM для строительных компаний: деньги, объекты, склад, снабжение, продажи квартир и ежедневный отчёт руководителю.') }}</p>
      <div class="foot-badges"><span>{!! ic('lock', '', 14) !!}{{ st('Шифрование данных') }}</span><span>{!! ic('server', '', 14) !!}{{ st('Ежедневные резервные копии') }}</span></div></div>
    <div><h4>{{ st('Продукт') }}</h4><a href="{{ $h('#math') }}">{{ st('Калькулятор потерь') }}</a><a href="{{ $h('#modules') }}">{{ st('Модули') }}</a><a href="{{ $h('#report') }}">{{ st('Отчёт директору') }}</a><a href="{{ $h('#security') }}">{{ st('Безопасность') }}</a><a href="{{ $h('#pricing') }}">{{ st('Тарифы') }}</a><a href="/blog">{{ st('Блог') }}</a></div>
    <div><h4>{{ st('Компания') }}</h4><a href="{{ $h('#demo') }}">{{ st('Бесплатный разбор') }}</a><a href="/login">{{ st('Войти') }}</a>@if ($regOpen)<a href="/register">{{ st('Регистрация') }}</a>@endif<a href="/privacy">{{ st('Политика конфиденциальности') }}</a><a href="/terms">{{ st('Условия использования') }}</a></div>
    <div><h4>{{ st('Контакты') }}</h4>
      @if ($support['phone'])<a href="tel:{{ preg_replace('/[^+\d]/', '', $support['phone']) }}">{!! ic('phone', '', 14) !!} {{ $support['phone'] }}</a>@endif
      @if ($support['telegram'])<a href="https://t.me/{{ ltrim($support['telegram'], '@') }}" target="_blank" rel="noopener">{!! ic('send', '', 14) !!} Telegram</a>@endif
      @if ($support['email'])<a href="mailto:{{ $support['email'] }}">{!! ic('mail', '', 14) !!} {{ $support['email'] }}</a>@endif
      <span class="muted" style="font-size:14px">{{ st('Ташкент, Узбекистан · Душанбе, Таджикистан') }}</span>
    </div>
    <div class="copy"><span>© {{ date('Y') }} {{ $brand }}. {{ st('Все права защищены.') }}</span>@include('partials.lang')</div>
  </div>
</footer>

<script>
const nav = document.getElementById('nav');
addEventListener('scroll', () => nav.classList.toggle('scrolled', scrollY > 8), { passive: true });
// меню на телефоне: открыть/закрыть кнопкой, закрыть после выбора пункта, по Esc и по нажатию вне шапки
const burger = nav.querySelector('.burger');
const setMenu = open => { document.body.classList.toggle('nav-open', open); burger.setAttribute('aria-expanded', open); };
burger.addEventListener('click', () => setMenu(!document.body.classList.contains('nav-open')));
document.querySelectorAll('.nav .links a').forEach(a => a.addEventListener('click', () => setMenu(false)));
addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
document.addEventListener('click', e => { if (document.body.classList.contains('nav-open') && !nav.contains(e.target)) setMenu(false); });
const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .1 });
document.querySelectorAll('.reveal').forEach(el => io.observe(el));
// счётчики
const cio = new IntersectionObserver(es => es.forEach(e => { if (!e.isIntersecting) return; cio.unobserve(e.target);
  const el = e.target, to = parseFloat(el.dataset.count), suf = el.dataset.suf || '', t0 = performance.now();
  const step = t => { const k = Math.min(1, (t - t0) / 1200), v = to * (1 - Math.pow(1 - k, 3)); el.textContent = (to % 1 ? v.toFixed(1) : Math.round(v).toLocaleString('ru-RU')) + suf; if (k < 1) requestAnimationFrame(step); };
  requestAnimationFrame(step); }), { threshold: .4 });
document.querySelectorAll('[data-count]').forEach(el => cio.observe(el));
</script>
@stack('scripts')
</body>
</html>
