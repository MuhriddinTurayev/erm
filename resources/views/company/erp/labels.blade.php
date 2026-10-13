<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('Этикетки QR') }} — {{ $ws->name }}</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
  body{font-family:Inter,system-ui,sans-serif;margin:0;padding:16px;color:#0f172a;background:#fff}
  .bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
  .bar button,.bar input{height:36px;border:1px solid #cbd5e1;border-radius:8px;padding:0 12px;font:inherit;background:#fff}
  .bar button.p{background:#1d4ed8;color:#fff;border-color:#1d4ed8;cursor:pointer}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,62mm);gap:4mm}
  .lb{width:62mm;height:36mm;border:1px dashed #94a3b8;border-radius:2mm;padding:2.5mm;box-sizing:border-box;display:flex;gap:2.5mm;align-items:center;break-inside:avoid}
  .lb .q{width:28mm;height:28mm;flex:none}.lb .q img,.lb .q canvas{width:28mm!important;height:28mm!important}
  .lb b{display:block;font-size:10pt;line-height:1.2;max-height:3.6em;overflow:hidden}.lb small{display:block;font-size:8pt;color:#475569;margin-top:1mm}
  @media print{.bar{display:none}body{padding:0}.lb{border-color:#e2e8f0}}
</style>
</head>
<body>
<div class="bar"><b>{{ $ws->name }}</b> · {{ __('Этикетки QR') }} ({{ $items->count() }})
  <form method="get" style="display:flex;gap:8px">@if (request('ids'))<input type="hidden" name="ids" value="{{ request('ids') }}">@endif<input type="number" name="n" value="{{ $copies }}" min="1" max="50" style="width:80px" title="{{ __('копий') }}"><button>{{ __('копий') }}</button></form>
  <button class="p" onclick="print()">{{ __('Печать') }}</button></div>
<div class="grid">
@foreach ($items as $it)
  @for ($i = 0; $i < $copies; $i++)
  <div class="lb"><div class="q" data-qr="{{ url('/company/stock/items/'.$it->id) }}"></div><div><b>{{ $it->name }}</b><small>{{ $it->sku ? __('Арт.').' '.$it->sku.' · ' : '' }}{{ __($it->unit ?? '') }}</small><small>ID {{ $it->id }}@if ($it->group) · {{ __($it->group ?? '') }}@endif</small></div></div>
  @endfor
@endforeach
</div>
<script>document.querySelectorAll('[data-qr]').forEach(el => new QRCode(el, {text: el.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M}));</script>
</body>
</html>
