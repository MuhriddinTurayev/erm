<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>@yield('title')</title>
<meta name="robots" content="@yield('robots', 'noindex')">
@stack('head')
</head>
<body class="pub">
<header class="pub-top">
  <div class="pub-wrap flex" style="justify-content:space-between">
    <div class="pub-brand"><span class="logo-mark">{{ mb_substr($ws->name, 0, 1) }}</span><div><b>{{ $ws->name }}</b>@if ($ws->phone)<small><a href="tel:{{ preg_replace('/[^\d+]/', '', $ws->phone) }}">{{ $ws->phone }}</a></small>@endif</div></div>
    <div class="flex">@include('partials.lang')@include('partials.theme-btn')</div>
  </div>
</header>
<main class="pub-wrap pub-main">
  @include('partials.flash')
  @yield('content')
</main>
<footer class="pub-wrap pub-foot">{{ __('Работает на платформе') }} <a href="{{ url('/') }}">{{ \App\Models\PlatformSetting::brand() }}</a></footer>
@stack('scripts')
</body>
</html>
