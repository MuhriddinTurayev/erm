<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
@include('partials.theme-init')
<link rel="icon" type="image/png" href="/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('assets/tokens.css') }}?v={{ @filemtime(public_path('assets/tokens.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/ui.css') }}?v={{ @filemtime(public_path('assets/ui.css')) }}">
<script src="{{ asset('assets/dialog.js') }}?v={{ @filemtime(public_path('assets/dialog.js')) }}" defer></script>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<meta name="theme-color" content="#1d4ed8">
<meta name="apple-mobile-web-app-capable" content="yes">
<script>if ('serviceWorker' in navigator && location.protocol === 'https:') addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));</script>
