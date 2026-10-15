<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>API — {{ \App\Models\PlatformSetting::brand() }}</title>
<meta name="description" content="{{ __('Документация API для разработчика, которому выдали ключ') }}">
<style>
  body{background:var(--bg)}
  .dev{max-width:1080px;margin:0 auto;padding:24px 16px 60px}
  .dev-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}
  .dev-top .logo{display:flex;align-items:center;gap:10px;font-weight:700;font-size:18px;color:var(--text);text-decoration:none}
  .dev h1{font-size:28px;margin:6px 0 6px}
  .dev .lead{color:var(--text-2);margin:0 0 18px;line-height:1.55}
</style>
</head>
<body>
<div class="dev">
  <div class="dev-top">
    <a class="logo" href="/">@include('partials.logo')</a>
    <div class="flex" style="gap:10px">@include('partials.lang')<a class="btn btn-s" href="/api/v1/openapi.json" target="_blank" rel="noopener">{!! ic('download') !!}OpenAPI · Postman</a></div>
  </div>
  <h1>{{ __('API для разработчика') }}</h1>
  <p class="lead">{{ __('Ключ API вам выдал администратор платформы — для одной компании и с одной ролью. Адрес API:') }} <code class="code">{{ $base }}</code>
    <button type="button" class="btn btn-s" data-copy="{{ $base }}">{!! ic('copy') !!}<span>{{ __('Скопировать') }}</span></button></p>
  <div class="card">
    @include('partials.api-docs')
  </div>
</div>
</body>
</html>
