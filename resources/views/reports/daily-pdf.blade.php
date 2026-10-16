{{-- Ежедневный отчёт в PDF (dompdf: вёрстка таблицами, шрифт Inter внутри файла) --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $c['title'] }} — {{ $c['company'] }} — {{ $c['date'] }}</title>
<style>
  @font-face { font-family: 'Inter'; font-weight: 400; src: url("{{ $font }}/Inter-Regular.ttf") format('truetype'); }
  @font-face { font-family: 'Inter'; font-weight: 600; src: url("{{ $font }}/Inter-SemiBold.ttf") format('truetype'); }
  @font-face { font-family: 'Inter'; font-weight: 700; src: url("{{ $font }}/Inter-Bold.ttf") format('truetype'); }
  @page { margin: 34px 38px 48px 38px; }
  * { font-family: 'Inter', sans-serif; }
  body { color: #1e1b4b; font-size: 10px; line-height: 1.45; }
  table { border-collapse: collapse; width: 100%; }
  td, th { vertical-align: top; }
  .muted { color: #6b7280; }
  .b { font-weight: 700; }
  .sb { font-weight: 600; }
  .r { text-align: right; }
  /* шапка */
  .head td { vertical-align: middle; }
  .brand { font-size: 15px; font-weight: 700; color: #6c4cf1; letter-spacing: -0.2px; }
  .tag { font-size: 8.5px; color: #6b7280; }
  .co { font-size: 12.5px; font-weight: 700; }
  .ini { width: 40px; height: 40px; border-radius: 11px; background: #f5f3ff; color: #6c4cf1; font-size: 15px; font-weight: 700; text-align: center; vertical-align: middle; padding: 0; }
  /* заголовок */
  .band { margin-top: 14px; background: #6c4cf1; border-radius: 14px; color: #fff; }
  .band td { padding: 16px 20px; vertical-align: middle; }
  .band .t { font-size: 22px; font-weight: 700; letter-spacing: -0.4px; }
  .band .d { font-size: 11px; color: #e9e5ff; margin-top: 2px; }
  .band .g { font-size: 8.5px; color: #e9e5ff; text-align: right; }
  .band .gv { font-size: 11px; font-weight: 600; color: #fff; text-align: right; }
  /* кто кому */
  .meta { margin-top: 12px; border: 1px solid #e9e7f5; border-radius: 12px; }
  .meta td { padding: 7px 12px; border-bottom: 1px solid #f0eff8; font-size: 9.5px; }
  .meta tr:last-child td { border-bottom: 0; }
  .meta .k { width: 120px; color: #6c4cf1; font-weight: 600; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; }
  /* карточки */
  .kpis { margin-top: 14px; border-collapse: separate; border-spacing: 7px 0; margin-left: -7px; width: 102.4%; }
  .kpi { background: #f6f5fd; border-radius: 12px; padding: 11px 12px; }
  .kpi.hero { background: #6c4cf1; color: #fff; }
  .kpi .l { font-size: 8.5px; color: #6b7280; }
  .kpi.hero .l, .kpi.hero .s { color: #e9e5ff; }
  .kpi .v { font-size: 13.5px; font-weight: 700; margin: 3px 0 2px; letter-spacing: -0.2px; }
  .kpi .s { font-size: 7.8px; color: #6b7280; }
  /* разделы */
  h2 { font-size: 12.5px; font-weight: 700; margin: 18px 0 7px; color: #1e1b4b; }
  h2 .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; background: #6c4cf1; margin-right: 6px; }
  .grid th { background: #f5f3ff; color: #4c3bb5; font-weight: 600; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; padding: 6px 8px; }
  .grid td { padding: 6px 8px; border-bottom: 1px solid #eef0f4; font-size: 9.5px; }
  .grid tr:nth-child(even) td { background: #fbfbfe; }
  .bar { height: 6px; border-radius: 3px; background: #eceafb; }
  .bar i { display: block; height: 6px; border-radius: 3px; background: #6c4cf1; }
  .pill { display: inline-block; padding: 1px 6px; border-radius: 8px; background: #fdecec; color: #b91c1c; font-size: 8px; font-weight: 600; }
  /* график 14 дней */
  .chart td { vertical-align: bottom; text-align: center; padding: 0 2px; height: 74px; }
  .chart .col { margin: 0 auto; width: 70%; border-radius: 3px 3px 0 0; background: #c9c0fb; }
  .chart .col.today { background: #6c4cf1; }
  .chart .lab td { height: auto; font-size: 7.2px; color: #6b7280; padding-top: 3px; vertical-align: top; }
  .ai { background: #f5f3ff; border-left: 4px solid #6c4cf1; border-radius: 10px; padding: 10px 14px; font-size: 9.8px; }
  .ai p { margin: 0 0 4px; }
  .foot-link { margin-top: 16px; font-size: 8.5px; color: #6b7280; }
  .foot-link a { color: #6c4cf1; text-decoration: none; }
  .keep { page-break-inside: avoid; }
</style>
</head>
<body>

{{-- шапка: платформа слева, компания справа --}}
<table class="head"><tr>
  <td style="width:52%">
    <table><tr>
      <td style="width:46px">@if ($brandLogo)<img src="{{ $brandLogo }}" style="width:38px;height:38px">@endif</td>
      <td><div class="brand">{{ $c['brand'] }}</div><div class="tag">{{ __('Учёт стройки, денег и продаж') }}</div></td>
    </tr></table>
  </td>
  <td style="width:48%">
    <table><tr>
      <td class="r" style="padding-right:10px"><div class="co">{{ $c['company'] }}</div>@if ($c['phone'])<div class="tag">{{ $c['phone'] }}</div>@endif</td>
      <td style="width:48px;text-align:right">
        @if ($companyLogo)<img src="{{ $companyLogo }}" style="max-width:96px;max-height:44px">@else<table style="width:40px;margin-left:auto"><tr><td class="ini">{{ $c['initials'] }}</td></tr></table>@endif
      </td>
    </tr></table>
  </td>
</tr></table>

{{-- заголовок --}}
<table class="band"><tr>
  <td><div class="t">{{ $c['title'] }}</div><div class="d">{{ $c['date'] }} · {{ $c['weekday'] }} · {{ $c['month'] }}</div></td>
  <td style="width:34%"><div class="g">{{ __('Сформирован') }}</div><div class="gv">{{ $c['generated'] }}</div></td>
</tr></table>

{{-- кто прислал и кому --}}
<table class="meta">
  <tr><td class="k">{{ __('Отправитель') }}</td><td>{{ $c['from'] }}</td></tr>
  <tr><td class="k">{{ __('Компания') }}</td><td class="sb">{{ $c['company'] }}@if ($c['phone']) <span class="muted">· {{ $c['phone'] }}</span>@endif</td></tr>
  <tr><td class="k">{{ __('Получатель') }}</td><td>{{ $c['to'] }}</td></tr>
</table>

{{-- главные цифры --}}
<table class="kpis"><tr>
  @foreach ($c['kpis'] as $k)
  <td style="width:25%"><div class="kpi {{ ! empty($k['hero']) ? 'hero' : '' }}"><div class="l">{{ $k['l'] }}</div><div class="v">{{ $k['v'] }}</div><div class="s">{{ $k['s'] }}</div></div></td>
  @endforeach
</tr></table>

{{-- расходы за 14 дней --}}
@php $days = array_slice($c['days'], -14); $max = max(1, ...array_column($days ?: [['v' => 0]], 'v')); @endphp
@if ($days)
<div class="keep">
<h2><span class="dot"></span>{{ __('Расходы за 14 дней') }}</h2>
<table class="chart">
  <tr>@foreach ($days as $x)<td><div class="col {{ $x['today'] ? 'today' : '' }}" style="height:{{ max(2, round($x['v'] / $max * 68)) }}px"></div></td>@endforeach</tr>
  <tr class="lab">@foreach ($days as $x)<td>{{ $x['d'] }}</td>@endforeach</tr>
</table>
</div>
@endif

{{-- на что ушли деньги --}}
<div class="keep">
<h2><span class="dot"></span>{{ __('На что ушли деньги сегодня') }}</h2>
@if ($c['cats'])
<table class="grid">
  <tr><th>{{ __('Статья') }}</th><th class="r" style="width:130px">{{ __('Сумма') }}</th><th style="width:170px">{{ __('Доля') }}</th></tr>
  @foreach ($c['cats'] as $x)
  <tr><td class="sb">{{ $x['n'] }}</td><td class="r">{{ $x['v'] }}</td>
    <td><table><tr><td style="padding:0;vertical-align:middle"><div class="bar"><i style="width:{{ max(2, min(100, $x['p'])) }}%"></i></div></td><td style="width:34px;padding:0 0 0 6px;vertical-align:middle" class="r muted">{{ $x['p'] }}%</td></tr></table></td></tr>
  @endforeach
</table>
@else
<div class="muted">{{ __('Сегодня расходов не было') }}</div>
@endif
</div>

@if ($c['top'])
<div class="keep">
<h2><span class="dot"></span>{{ __('Крупные расходы') }}</h2>
<table class="grid">
  <tr><th class="r" style="width:120px">{{ __('Сумма') }}</th><th>{{ __('Описание') }}</th><th style="width:110px">{{ __('Кому') }}</th><th style="width:110px">{{ __('Объект') }}</th></tr>
  @foreach ($c['top'] as $x)
  <tr><td class="r b">{{ $x['v'] }}</td><td>{{ $x['desc'] }}</td><td>{{ $x['who'] ?: '—' }}</td><td class="muted">{{ $x['obj'] }}</td></tr>
  @endforeach
</table>
</div>
@endif

@if ($c['objects'])
<h2><span class="dot"></span>{{ __('Объекты') }}</h2>
<table class="grid">
  <thead><tr><th>{{ __('Объект') }}</th><th class="r">{{ __('Приход − расход') }}</th><th class="r">{{ __('Сегодня') }}</th><th class="r">{{ __('За месяц') }}</th><th style="width:110px">{{ __('Готовность') }}</th></tr></thead>
  @foreach ($c['objects'] as $o)
  <tr><td class="sb">{{ $o['n'] }}@if ($o['late'])<br><span class="pill">{{ __('просрочено этапов') }}: {{ $o['late'] }}</span>@endif</td>
    <td class="r">{{ $o['bal'] }}</td><td class="r">{{ $o['day'] }}</td><td class="r">{{ $o['month'] }}</td>
    <td><table><tr><td style="padding:0;vertical-align:middle"><div class="bar"><i style="width:{{ max(2, min(100, $o['p'])) }}%"></i></div></td><td style="width:30px;padding:0 0 0 6px;vertical-align:middle" class="r muted">{{ $o['p'] }}%</td></tr></table></td></tr>
  @endforeach
  @if ($c['objectsIdle'] > 0)<tr><td colspan="5" class="muted">{{ __('Ещё объектов без движения денег и работ: :n', ['n' => $c['objectsIdle']]) }}</td></tr>@endif
</table>
@elseif ($c['objectsIdle'] > 0)
<h2><span class="dot"></span>{{ __('Объекты') }}</h2>
<div class="muted">{{ __('Ещё объектов без движения денег и работ: :n', ['n' => $c['objectsIdle']]) }}</div>
@endif

@if ($c['low'])
<div class="keep">
<h2><span class="dot"></span>{{ __('Заканчиваются материалы') }}</h2>
<table class="grid">
  <tr><th>{{ __('Объект') }}</th><th>{{ __('Материал') }}</th><th class="r">{{ __('Осталось') }}</th><th class="r">{{ __('Хватит на') }}</th></tr>
  @foreach ($c['low'] as $x)
  <tr><td>{{ $x['obj'] }}</td><td class="sb">{{ $x['item'] }}</td><td class="r">{{ $x['qty'] }} {{ $x['unit'] }}</td><td class="r">{{ $x['days'] !== null ? __(':n дн.', ['n' => $x['days']]) : '—' }}</td></tr>
  @endforeach
</table>
</div>
@endif

@if ($c['late'])
<div class="keep">
<h2><span class="dot"></span>{{ __('Материал опаздывает') }}</h2>
<table class="grid">
  <tr><th style="width:44px">№</th><th>{{ __('Объект') }}</th><th>{{ __('Нужно было к') }}</th><th>{{ __('Статус') }}</th><th>{{ __('Поставщик') }}</th></tr>
  @foreach ($c['late'] as $x)
  <tr><td>{{ $x['number'] }}</td><td>{{ $x['obj'] }}</td><td>{{ $x['need'] }}</td><td>{{ $x['status'] }}</td><td>{{ $x['who'] ?: '—' }}</td></tr>
  @endforeach
</table>
</div>
@endif

@if ($c['ai'])
<div class="keep">
<h2><span class="dot"></span>{{ __('Вывод ИИ') }}</h2>
<div class="ai">@foreach (preg_split('/\R+/u', $c['ai']) as $line)@if (trim($line) !== '')<p>{{ trim($line) }}</p>@endif @endforeach</div>
</div>
@endif

<div class="foot-link">{{ __('Отчёт на сайте') }}: <a href="{{ $c['url'] }}">{{ $c['url'] }}</a></div>
</body>
</html>
