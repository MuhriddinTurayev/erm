{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as [$u, $mod, $freq, $prio])
@foreach ($locales as $l)
  <url>
    <loc>{{ url($u).($l === 'ru' ? '' : '?lang='.$l) }}</loc>
@foreach ($locales as $l2)
    <xhtml:link rel="alternate" hreflang="{{ $l2 }}" href="{{ url($u).($l2 === 'ru' ? '' : '?lang='.$l2) }}"/>
@endforeach
    <xhtml:link rel="alternate" hreflang="x-default" href="{{ url($u) }}"/>
    <lastmod>{{ \Illuminate\Support\Carbon::parse($mod)->toAtomString() }}</lastmod>
    <changefreq>{{ $freq }}</changefreq>
    <priority>{{ $prio }}</priority>
  </url>
@endforeach
@endforeach
</urlset>
