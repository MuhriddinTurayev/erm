@if ($c = session('creds'))
@php $text = __('Вход в программу').': '.url('/login')."\n".__('Логин').': '.$c['login']."\n".__('Пароль').': '.$c['password']; @endphp
<div class="creds card">
  <div class="card-h"><h3>{!! ic('key-round') !!}{{ __('Аккаунт создан: :n', ['n' => $c['name']]) }}</h3><span class="badge b-ok">{{ $c['role'] }}</span></div>
  <p class="muted" style="margin-top:0">{{ __('Передайте сотруднику эти данные. Пароль показывается только один раз — потом его можно только сменить.') }}</p>
  <dl class="kv creds-kv">
    <dt>{{ __('Адрес') }}</dt><dd>{{ url('/login') }}</dd>
    <dt>{{ __('Логин') }}</dt><dd><b class="code">{{ $c['login'] }}</b></dd>
    <dt>{{ __('Пароль') }}</dt><dd><b class="code">{{ $c['password'] }}</b></dd>
  </dl>
  <div class="flex mt">
    <button type="button" class="btn btn-p btn-s" data-copy="{{ $text }}">{!! ic('copy') !!}{{ __('Скопировать') }}</button>
    <a class="btn btn-s" href="https://t.me/share/url?url={{ urlencode(url('/login')) }}&text={{ urlencode($text) }}" target="_blank" rel="noopener">{!! ic('send') !!}{{ __('Отправить в Telegram') }}</a>
  </div>
</div>
@push('scripts')
<script>document.addEventListener('click', e => { const b = e.target.closest('[data-copy]'); if (!b) return; navigator.clipboard.writeText(b.dataset.copy).then(() => { b.lastChild.textContent = ' ✓'; }); });</script>
@endpush
@endif
