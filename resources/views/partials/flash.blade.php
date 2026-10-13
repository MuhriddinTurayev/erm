@if ($errors->any())<div class="alert alert-bad">{!! ic('circle-alert') !!}<div>{{ $errors->first() }}</div></div>@endif
@if (session('ok'))<div class="alert alert-ok">{!! ic('circle-check') !!}<div>{{ session('ok') }}</div></div>@endif
@if (session('warn'))<div class="alert alert-warn">{!! ic('triangle-alert') !!}<div>{{ session('warn') }}</div></div>@endif
