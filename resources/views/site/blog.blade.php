@extends('layouts.site')
@section('title', st('Блог').' — '.$brand)
@section('description', st('Статьи о строительном бизнесе: управление деньгами стройки, снабжение, склад, продажи квартир и автоматизация.'))
@section('content')
<section class="page-hero" data-cms="Блог">
  <div class="wrap"><span class="eyebrow">{{ st('Блог') }}</span><h1>{{ st('Полезное для строительного бизнеса') }}</h1><p>{{ st('Статьи о строительном бизнесе: управление деньгами стройки, снабжение, склад, продажи квартир и автоматизация.') }}</p></div>
</section>
<div class="wrap">
  @if ($posts->count())
  <div class="posts">
    @foreach ($posts as $p)
    <a class="post-card" href="/blog/{{ $p->slug }}">
      <div class="cv">@if ($p->cover)<img src="{{ $p->cover }}" alt="{{ $p->t('title') }}" loading="lazy">@else{!! ic('newspaper', '', 36) !!}@endif</div>
      <div class="bd"><small class="muted">{{ $p->published_at?->translatedFormat('d F Y') }}</small><h3>{{ $p->t('title') }}</h3><p>{{ \Illuminate\Support\Str::limit($p->t('excerpt'), 160) }}</p></div>
    </a>
    @endforeach
  </div>
  <div style="padding-bottom:60px">{{ $posts->links() }}</div>
  @else
  <div class="empty" style="padding:80px 0">{!! ic('newspaper') !!}<div>{{ st('Скоро здесь появятся статьи') }}</div></div>
  @endif
</div>
@endsection
