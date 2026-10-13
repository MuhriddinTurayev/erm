@extends('layouts.site')
@section('title', $post->t('title').' — '.$brand)
@section('description', \Illuminate\Support\Str::limit($post->t('excerpt') ?: strip_tags($post->html()), 158))
@section('og_type', 'article')
@if ($post->cover) @section('og_image', url($post->cover)) @endif
@section('content')
<section class="page-hero">
  <div class="wrap"><a href="/blog" class="eyebrow">{!! ic('arrow-left', '', 14) !!} {{ st('Блог') }}</a><h1>{{ $post->t('title') }}</h1><p>{{ $post->published_at?->translatedFormat('d F Y') }}</p></div>
</section>
<div class="wrap">
  <article class="prose">
    @if ($post->cover)<img src="{{ $post->cover }}" alt="{{ $post->t('title') }}" style="margin-bottom:24px">@endif
    {!! $post->html() !!}
    <div class="cta-band" style="margin-top:44px"><div><h2 style="font-size:24px">{{ st('Попробуйте платформу бесплатно') }}</h2><p>{{ st('14 дней без оплаты и без карты') }}</p></div><a class="btn btn-p btn-l" href="/register">{{ st('Начать') }}{!! ic('arrow-right') !!}</a></div>
  </article>
  @if ($more->count())
  <h2 style="margin:0">{{ st('Читайте также') }}</h2>
  <div class="posts" style="padding-top:20px">
    @foreach ($more as $p)<a class="post-card" href="/blog/{{ $p->slug }}"><div class="bd"><small class="muted">{{ $p->published_at?->format('d.m.Y') }}</small><h3>{{ $p->t('title') }}</h3></div></a>@endforeach
  </div>
  @endif
</div>
@endsection
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $post->t('title'), 'datePublished' => $post->published_at?->toAtomString(),
  'dateModified' => $post->updated_at?->toAtomString(), 'image' => $post->cover ? url($post->cover) : null, 'publisher' => ['@type' => 'Organization', 'name' => $brand]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush
