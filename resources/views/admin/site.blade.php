@extends('layouts.panel')
@section('title', __('Тексты сайта'))
@php $tab = request('tab', 'texts'); $ln = config('oftob.locales'); @endphp
@section('actions')
<a class="btn btn-s" href="/" target="_blank">{!! ic('external-link') !!}{{ __('Открыть сайт') }}</a>
@endsection
@section('content')
<div class="tabs" style="margin-bottom:18px">
  <a href="?tab=texts" class="{{ $tab === 'texts' ? 'on' : '' }}">{!! ic('type', '', 15) !!} {{ __('Все тексты') }}</a>
  <a href="?tab=pages" class="{{ $tab === 'pages' ? 'on' : '' }}">{!! ic('file-text', '', 15) !!} {{ __('Правовые страницы') }}</a>
  <a href="?tab=seo" class="{{ $tab === 'seo' ? 'on' : '' }}">{!! ic('globe', '', 15) !!} {{ __('SEO и аналитика') }}</a>
  <a href="/admin/posts">{!! ic('newspaper', '', 15) !!} {{ __('Блог') }}</a>
</div>

@if ($tab === 'texts')
<div class="alert alert-info">{!! ic('type') !!}<div>{{ __('Здесь можно изменить любой текст сайта на трёх языках. Пустое поле — вернуть стандартный текст. Изменено текстов: :n.', ['n' => $changed]) }}</div></div>
<form method="get" class="toolbar"><input type="hidden" name="tab" value="texts"><input class="input grow" name="q" value="{{ $q }}" placeholder="{{ __('Найти текст на любом языке…') }}"><button class="btn btn-s">{!! ic('search') !!}{{ __('Найти') }}</button>@if ($q)<a class="btn btn-s btn-ghost" href="?tab=texts">{{ __('Сбросить') }}</a>@endif</form>
<div class="chips" style="margin-bottom:16px">@foreach ($keys as $sec => $list)<a class="chip" href="#s-{{ $loop->index }}">{{ __($sec) }}<span class="n">{{ count($list) }}</span></a>@endforeach</div>
@forelse ($keys as $sec => $list)
<form method="post" action="/admin/site" class="card mt" id="s-{{ $loop->index }}">@csrf
  <div class="card-h"><h3>{!! ic('layout-grid') !!}{{ __($sec) }}</h3><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Сохранить раздел') }}</button></div>
  <div class="tbl-wrap"><table class="tbl lines-tbl"><thead><tr>@foreach ($locales as $l)<th>{{ $ln[$l][0] }}</th>@endforeach<th style="width:40px"></th></tr></thead><tbody>
  @foreach ($list as $k)
    @php $hk = base64_encode($k); $isChanged = collect($locales)->contains(fn ($l) => isset($saved[$l][$k])); $long = mb_strlen($def['ru'][$k]) > 70; @endphp
    <tr>
      @foreach ($locales as $l)
      <td style="min-width:220px">
        @if ($long)<textarea class="input" name="t[{{ $l }}][{{ $hk }}]" rows="3" style="height:auto;padding:8px 10px;line-height:1.45">{{ $saved[$l][$k] ?? $def[$l][$k] }}</textarea>
        @else<input class="input" name="t[{{ $l }}][{{ $hk }}]" value="{{ $saved[$l][$k] ?? $def[$l][$k] }}">@endif
        @if (isset($saved[$l][$k]))<small class="ok" style="font-size:11px">{{ __('изменён') }}</small>@endif
      </td>
      @endforeach
      <td>@if ($isChanged)<button class="icon-btn" form="reset-{{ md5($k) }}" title="{{ __('Вернуть стандартный текст') }}">{!! ic('undo-2') !!}</button>@endif</td>
    </tr>
  @endforeach
  </tbody></table></div>
</form>
@foreach ($list as $k) @if (collect($locales)->contains(fn ($l) => isset($saved[$l][$k])))<form method="post" action="/admin/site/reset" id="reset-{{ md5($k) }}">@csrf<input type="hidden" name="k" value="{{ base64_encode($k) }}"></form>@endif @endforeach
@empty
<div class="card"><div class="empty">{{ __('Ничего не найдено') }}</div></div>
@endforelse

@elseif ($tab === 'pages')
<form method="post" action="/admin/site/pages" class="card">@csrf
  <div class="alert alert-info">{!! ic('file-text') !!}<div>{{ __('Пустое поле — показывается стандартный текст. Можно использовать Markdown: ## заголовок, **жирный**, списки.') }}</div></div>
  @foreach (['privacy' => __('Политика конфиденциальности'), 'terms' => __('Условия использования')] as $slug => $name)
    <h3 class="mt">{{ $name }} <a href="/{{ $slug }}" target="_blank" style="font-size:13px;font-weight:400">/{{ $slug }}</a></h3>
    <div class="grid g3">@foreach ($locales as $l)<label class="field"><span>{{ $ln[$l][0] }}</span><textarea name="pages[{{ $slug }}][{{ $l }}]" rows="12">{{ $pages[$slug][$l] ?? '' }}</textarea></label>@endforeach</div>
  @endforeach
  <div class="mt"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form>

@else
<form method="post" action="/admin/site/seo" enctype="multipart/form-data" class="card">@csrf
  <div class="card-h"><h3>{!! ic('globe') !!}{{ __('SEO и аналитика') }}</h3></div>
  <div class="form">
    <label class="field"><span>Google Analytics (G-XXXX)</span><input name="ga_id" value="{{ $seo['ga_id'] }}" placeholder="G-ABC123"></label>
    <label class="field"><span>{{ __('Яндекс Метрика (номер счётчика)') }}</span><input name="ym_id" value="{{ $seo['ym_id'] }}" inputmode="numeric"></label>
    <label class="field wide"><span>{{ __('Картинка для соцсетей (1200×630)') }}</span><input type="file" name="image" accept="image/*">@if ($seo['seo_image'])<small><a href="{{ $seo['seo_image'] }}" target="_blank">{{ __('текущая картинка') }}</a></small>@endif</label>
  </div>
  <div class="alert alert-info mt" style="margin-bottom:0">{!! ic('circle-check') !!}<div>{{ __('Автоматически: заголовок и описание каждой страницы на 3 языках, hreflang, canonical, Open Graph, разметка Schema.org, карта сайта :s и robots.txt.', ['s' => url('/sitemap.xml')]) }} <a href="/sitemap.xml" target="_blank">sitemap.xml</a> · <a href="/robots.txt" target="_blank">robots.txt</a></div></div>
  <div class="mt"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
</form>
@endif
@endsection
