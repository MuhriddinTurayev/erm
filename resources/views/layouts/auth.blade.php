<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>@yield('title') — {{ \App\Models\PlatformSetting::brand() }}</title>
</head>
<body>
<div class="auth">
  <aside class="auth-side">
    <a href="/" style="text-decoration:none">@include('partials.logo')</a>
    <h2>{!! __('Вся стройка и все деньги компании — <em>в одном окне</em>') !!}</h2>
    <ul>
      <li><span class="li-ic">{!! ic('wallet') !!}</span><span>{{ __('Сколько денег на счету, сколько ушло сегодня и вчера и на что — по каждому объекту') }}</span></li>
      <li><span class="li-ic">{!! ic('send') !!}</span><span>{{ __('Каждый вечер руководителю приходит отчёт в Telegram') }}</span></li>
      <li><span class="li-ic">{!! ic('building-2') !!}</span><span>{{ __('Все объекты компании: сроки, бригады, техника, материалы, продажи квартир') }}</span></li>
      <li><span class="li-ic">{!! ic('sparkles') !!}</span><span>{{ __('ИИ-аналитик: Gemini, ChatGPT, DeepSeek, Claude') }}</span></li>
    </ul>
    <div class="a-foot">© {{ date('Y') }} {{ \App\Models\PlatformSetting::brand() }} · {{ __('Узбекистан · Таджикистан') }}</div>
  </aside>
  <main class="auth-main">
    <div class="a-top"><a href="/" class="btn btn-ghost btn-s">{!! ic('arrow-left') !!}{{ __('На сайт') }}</a><div class="flex">@include('partials.lang')@include('partials.theme-btn')</div></div>
    <div class="auth-box">
      @include('partials.flash')
      @yield('content')
    </div>
  </main>
</div>
<script>
document.addEventListener('click', e => { const b = e.target.closest('[data-pw]'); if (!b) return; const i = b.parentElement.querySelector('input'); i.type = i.type === 'password' ? 'text' : 'password'; b.classList.toggle('shown', i.type === 'text'); });
</script>
<style>[data-pw] .ic+.ic,[data-pw].shown .ic:first-child{display:none}[data-pw].shown .ic+.ic{display:block}</style>
</body>
</html>
