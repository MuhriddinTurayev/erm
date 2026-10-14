@extends('layouts.panel')
@section('title', $p['name'] ?? __('Объект'))
@php
  $u = auth()->user(); $K = \App\Models\ObjectMedia::KINDS;
  $canMedia = $u->canMedia();
  $money = $u->seesMoney() || $u->role === 'manager';   // администратор объектов и прораб денег не видят
  $roleHelp = ['catalog' => __('заводит объекты, корпуса, подъезды, квартиры, планировки, цены, 3D и панорамы; денег и сделок не видит'),
    'foreman' => __('свой объект: график, бригады, табель, склад, заявки, фотоотчёты'), 'manager' => __('продажи квартир этого объекта, клиенты, 3D для клиентов'),
    'accountant' => __('деньги, договоры и отчёты этого объекта'), 'cashier' => __('приходы и расходы этого объекта'), 'viewer' => __('видит объект, ничего не меняет')];
@endphp
@section('actions')
<a class="btn btn-s" href="/company/objects">{!! ic('arrow-left') !!}{{ __('Все объекты') }}</a>
@if ($u->managesObjects())<button class="btn btn-s" data-open="dlg-info">{!! ic('pencil') !!}{{ __('Название и адрес') }}</button>@endif
@if ($u->canModule('sales'))<a class="btn btn-s" href="/company/sales?o={{ $pid }}">{!! ic('key-round') !!}{{ __('Продажи') }}</a>@endif
@if ($media->whereIn('kind', ['exterior', 'interior', 'pano'])->count())<a class="btn btn-p btn-s" href="/company/objects/{{ $pid }}/3d" target="_blank">{!! ic('box') !!}{{ __('Открыть 3D') }}</a>@endif
@endsection
@section('content')
@include('partials.creds')
<div class="tabs" style="margin-bottom:18px">
  <a href="?tab=overview" class="{{ $tab === 'overview' ? 'on' : '' }}">{!! ic('gauge', '', 15) !!} {{ __('Сводка') }}</a>
  @if ($u->managesObjects())<a href="?tab=units" class="{{ $tab === 'units' ? 'on' : '' }}">{!! ic('layout-grid', '', 15) !!} {{ __('Помещения') }} <span class="badge">{{ $s['apts'] }}</span></a>@endif
  @if ($u->canModule('sales') && $money)<a href="?tab=sales" class="{{ $tab === 'sales' ? 'on' : '' }}">{!! ic('key-round', '', 15) !!} {{ __('Продажи') }} <span class="badge">{{ $s['sold'] + $s['booked'] }}</span></a>@endif
  <a href="?tab=team" class="{{ $tab === 'team' ? 'on' : '' }}">{!! ic('users', '', 15) !!} {{ __('Команда и доступ') }} <span class="badge">{{ $team->count() }}</span></a>
  <a href="?tab=media" class="{{ $tab === 'media' ? 'on' : '' }}">{!! ic('box', '', 15) !!} {{ __('3D, панорамы, планировки') }} <span class="badge">{{ $media->count() }}</span></a>
</div>
@if ($u->managesObjects())
<dialog class="modal" id="dlg-info">
  <div class="modal-h"><h3>{!! ic('building-2') !!}{{ __('Название и адрес') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <form method="post" action="/company/objects/{{ $pid }}/info" class="modal-b form" style="grid-template-columns:1fr">@csrf
    <label class="field"><span>{{ __('Название объекта') }}</span><input name="name" required maxlength="120" value="{{ $p['name'] ?? '' }}"></label>
    <label class="field"><span>{{ __('Адрес') }}</span><input name="address" maxlength="200" value="{{ $p['address'] ?? '' }}"></label>
    <div class="flex"><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button><button type="button" class="btn" data-close>{{ __('Отмена') }}</button></div>
  </form>
</dialog>
@endif

@if ($tab === 'units' && $u->managesObjects())
@include('company.objects.units')

@elseif ($tab === 'overview')
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('hard-hat') !!}{{ __('Готовность') }}</div><div class="k-v">{{ $s['progress'] }}%</div><div class="prog"><i style="width:{{ $s['progress'] }}%"></i></div></div>
  <div class="kpi"><div class="k-l">{!! ic('key-round') !!}{{ __('Квартир продано / бронь') }}</div><div class="k-v">{{ $s['sold'] }} / {{ $s['booked'] }}</div><div class="k-s">{{ __('из :n помещений', ['n' => $s['apts']]) }}</div></div>
  @if ($money)
  <div class="kpi"><div class="k-l">{!! ic('circle-check') !!}{{ __('Получено от покупателей') }}</div><div class="k-v in-c">{{ money_s($s['paid'], $cur) }}</div><div class="k-s">{{ __('по договорам') }} {{ money_s($s['revenue'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('scale') !!}{{ __('Покупатели должны') }}</div><div class="k-v">{{ money_s($s['debt'], $cur) }}</div>@if ($s['overdue'] > 0)<div class="k-s out-c">{{ __('просрочено') }} {{ money_s($s['overdue'], $cur) }}</div>@endif</div>
  @endif
  @if ($u->seesMoney())<div class="kpi"><div class="k-l">{!! ic('wallet') !!}{{ __('Баланс объекта') }}</div><div class="k-v {{ $s['balance'] < 0 ? 'out-c' : '' }}">{{ money_s($s['balance'], $cur) }}</div></div>@endif
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Непроданные помещения') }}</div><div class="k-v">{{ money_s($s['stock_value'], $cur) }}</div><div class="k-s">{{ $s['free'] }} {{ __('свободно') }}</div></div>
</div>
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h3>{!! ic('list-checks') !!}{{ __('Этапы строительства') }}</h3><a class="btn btn-s btn-ghost" href="/crm#schedule">{{ __('График') }}{!! ic('chevron-right') !!}</a></div>
    @foreach (array_slice((array) ($p['stages'] ?? []), 0, 12) as $st)
      <div class="hbar"><div class="hb-t"><span>{{ __($st['name'] ?? '') }}</span><b>{{ (int) ($st['progress'] ?? 0) }}%</b></div><div class="hb-r"><i style="width:{{ (float) ($st['progress'] ?? 0) }}%;background:var(--ok)"></i></div></div>
    @endforeach
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('layout-grid') !!}{{ __('Быстрые действия') }}</h3></div>
    <div class="quick">
      @if ($u->canModule('sales'))<a href="/company/sales?o={{ $pid }}">{!! ic('key-round') !!}<b>{{ __('Шахматка и продажи') }}</b><small>{{ __('бронь, оплата, рассрочка') }}</small></a>@endif
      @if ($u->managesObjects())<a href="?tab=units">{!! ic('layout-grid') !!}<b>{{ __('Помещения') }}</b><small>{{ __('корпуса, подъезды, квартиры, цены') }}</small></a>@endif
      <a href="?tab=media">{!! ic('box') !!}<b>{{ __('3D-модель') }}</b><small>{{ __('фасад, интерьеры, 360°') }}</small></a>
      @if ($u->isAdmin())<a href="?tab=team">{!! ic('user-plus') !!}<b>{{ __('Аккаунт прораба') }}</b><small>{{ __('логин только для этого объекта') }}</small></a>@endif
      @if ($u->canModule('journal'))<a href="/company/journal">{!! ic('camera') !!}<b>{{ __('Фотоотчёты') }}</b><small>{{ __('с геометкой') }}</small></a>@endif
      @if ($u->canModule('warehouse'))<a href="/company/stock">{!! ic('warehouse') !!}<b>{{ __('Склад') }}</b><small>{{ __('материалы объекта') }}</small></a>@endif
      <a href="/crm">{!! ic('layers') !!}<b>{{ __('Программа') }}</b><small>{{ __('смета, бригады, техника') }}</small></a>
    </div>
  </div>
</div>

@elseif ($tab === 'sales' && $u->canModule('sales') && $money)
<div class="kpis">
  <div class="kpi"><div class="k-l">{!! ic('key-round') !!}{{ __('Продано') }}</div><div class="k-v">{{ $s['sold'] }}</div><div class="k-s">{{ __('бронь') }}: {{ $s['booked'] }} · {{ __('свободно') }}: {{ $s['free'] }}</div><div class="prog"><i style="width:{{ $s['apts'] ? round(($s['sold'] + $s['booked']) / $s['apts'] * 100) : 0 }}%"></i></div></div>
  <div class="kpi"><div class="k-l">{!! ic('file-pen-line') !!}{{ __('Сумма сделок') }}</div><div class="k-v">{{ money_s($s['revenue'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('circle-check') !!}{{ __('Получено') }}</div><div class="k-v in-c">{{ money_s($s['paid'], $cur) }}</div><div class="k-s">{{ __('осталось получить') }} {{ money_s($s['debt'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('alarm-clock') !!}{{ __('Просрочка покупателей') }}</div><div class="k-v {{ $s['overdue'] ? 'out-c' : '' }}">{{ money_s($s['overdue'], $cur) }}</div></div>
  <div class="kpi"><div class="k-l">{!! ic('building-2') !!}{{ __('Осталось продать') }}</div><div class="k-v">{{ money_s($s['stock_value'], $cur) }}</div></div>
</div>
<div class="card">
  <div class="card-h"><h3>{!! ic('layout-grid') !!}{{ __('Продажи по планировкам') }}</h3><a class="btn btn-p btn-s" href="/company/sales?o={{ $pid }}">{!! ic('layout-grid') !!}{{ __('Открыть шахматку') }}</a></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Планировка') }}</th><th class="n">{{ __('Всего') }}</th><th class="n">{{ __('Продано') }}</th><th class="n">{{ __('Бронь') }}</th><th class="n">{{ __('Свободно') }}</th>
    <th style="min-width:140px">{{ __('Продано, %') }}</th><th class="n">{{ __('Средняя цена м²') }}</th><th class="n">{{ __('Цена от') }}</th><th class="n">{{ __('Сумма сделок') }}</th><th></th></tr></thead><tbody>
  @foreach ($byRooms as $rooms => $r)
    @php $pct = $r['n'] ? round(($r['sold'] + $r['booked']) / $r['n'] * 100) : 0; @endphp
    <tr><td><b>{{ $rooms ? $rooms.__('-комн.') : __('Коммерция') }}</b></td><td class="n">{{ $r['n'] }}</td><td class="n">{{ $r['sold'] }}</td><td class="n">{{ $r['booked'] }}</td><td class="n"><b class="{{ $r['free'] ? 'in-c' : '' }}">{{ $r['free'] }}</b></td>
      <td>{{ $pct }}%<div class="prog"><i style="width:{{ $pct }}%"></i></div></td>
      <td class="n">{{ $r['area'] ? money_s($r['sum'] / $r['area'], $cur) : '—' }}</td><td class="n">{{ $r['min'] !== null ? money_s($r['min'], $cur) : '—' }}</td><td class="n">{{ money_s($r['revenue'], $cur) }}</td>
      <td class="right"><a class="btn btn-s btn-ghost" href="/company/sales?o={{ $pid }}&rooms={{ $rooms }}">{!! ic('chevron-right') !!}</a></td></tr>
  @endforeach
  </tbody></table></div>
</div>
<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('alarm-clock') !!}{{ __('Просрочили оплату') }}</h3><span class="badge {{ count($debtors) ? 'b-bad' : '' }}">{{ count($debtors) }}</span></div>
    @forelse ($debtors as $a)
      <div class="list-row"><div><b>{{ __('Кв.') }} {{ apt_num($a['num']) }}</b> · {{ $a['buyer'] }}<br><small class="muted">{{ $a['phone'] }} · {{ __($a['plan']) }}</small></div><b class="out-c">{{ money_s($a['od'], $cur) }}</b></div>
    @empty<div class="muted">{{ __('Все платят вовремя') }}</div>@endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('wallet') !!}{{ __('Последние оплаты') }}</h3></div>
    @forelse ($lastPays as $x)
      <div class="list-row"><div>{{ __('Кв.') }} {{ apt_num($x['apt']) }} · {{ $x['buyer'] }}<br><small class="muted">{{ dmy($x['date'] ?? null) }}</small></div><b class="in-c">+{{ money_s($x['amount'], $cur) }}</b></div>
    @empty<div class="muted">{{ __('Оплат пока нет') }}</div>@endforelse
  </div>
</div>

@elseif ($tab === 'team')
<div class="grid g32">
  <div class="card">
    <div class="card-h"><h3>{!! ic('users') !!}{{ __('Кто работает с объектом') }}</h3></div>
    @foreach ($team as $t)
      @php $onlyThis = ! empty($t->object_ids) && count($t->object_ids) === 1; @endphp
      <div class="list-row">
        <div class="who"><span class="avatar">{{ initials($t->name) }}</span><div><b>{{ $t->name }}</b> <span class="badge {{ $t->role === 'admin' ? 'b-brand' : 'b-info' }}">{{ $t->roleName() }}</span>@unless ($t->is_active) <span class="badge b-bad">{{ __('отключён') }}</span>@endunless
          <br><small class="muted">{{ \App\Services\Accounts::login($t) }} · {{ $t->role === 'admin' || empty($t->object_ids) ? __('все объекты') : ($onlyThis ? __('только этот объект') : __('объектов: :n', ['n' => count($t->object_ids)])) }}
          · {{ $t->last_login_at ? __('был :d', ['d' => $t->last_login_at->diffForHumans()]) : __('ещё не входил') }}</small></div></div>
        @if ($u->isAdmin() && $t->id !== $u->id)
        <form method="post" action="/company/objects/{{ $pid }}/users/{{ $t->id }}/password" data-confirm="{{ __('Создать новый пароль для :n? Старый перестанет работать.', ['n' => $t->name]) }}">@csrf<button class="btn btn-s">{!! ic('key-round') !!}{{ __('Новый пароль') }}</button></form>
        @endif
      </div>
    @endforeach
    @if ($u->isAdmin())<p class="muted mt" style="font-size:13px">{{ __('Изменить роль или доступ к нескольким объектам можно в разделе') }} <a href="/company/users">{{ __('Пользователи и роли') }}</a>.</p>@endif
  </div>
  @if ($u->isAdmin())
  <div class="card">
    <div class="card-h"><h3>{!! ic('user-plus') !!}{{ __('Создать аккаунт для объекта') }}</h3></div>
    <form method="post" action="/company/objects/{{ $pid }}/account" class="form" style="grid-template-columns:1fr">@csrf
      <label class="field"><span>{{ __('Роль') }}</span><select name="role" id="acc-role">@foreach ($roles as $k => $n) @if ($k !== 'admin')<option value="{{ $k }}" @selected(old('role', 'foreman') === $k)>{{ __($n) }}</option>@endif @endforeach</select>
        <small id="acc-help">{{ $roleHelp['foreman'] }}</small></label>
      <label class="field"><span>{{ __('Имя и фамилия') }}</span><input name="name" required maxlength="80" value="{{ old('name') }}" placeholder="{{ __('Алишер Каримов') }}"></label>
      <label class="field"><span>{{ __('Телефон (логин)') }}</span><input name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="+998 90 123 45 67" inputmode="tel"></label>
      <label class="field"><span>{{ __('Email (необязательно)') }}</span><input type="email" name="email" maxlength="120" value="{{ old('email') }}"></label>
      <div><button class="btn btn-p btn-block">{!! ic('key-round') !!}{{ __('Создать логин и пароль') }}</button></div>
      <small class="muted">{{ __('Пароль создастся автоматически и покажется один раз. Сотрудник увидит только этот объект.') }}</small>
    </form>
  </div>
  @endif
</div>
@push('scripts')<script>
const RH = @json($roleHelp); document.getElementById('acc-role')?.addEventListener('change', e => document.getElementById('acc-help').textContent = RH[e.target.value] || '');
</script>@endpush

@else
<div class="grid g32">
  <div>
    @foreach ($K as $kind => [$kn, $ki, $exts])
      @php $items = $media->where('kind', $kind); @endphp
      @continue(! $items->count())
      <div class="card" style="margin-bottom:16px">
        <div class="card-h"><h3>{!! ic($ki) !!}{{ __($kn) }}</h3><span class="badge">{{ $items->count() }}</span></div>
        <div class="media-grid">
          @foreach ($items as $m)
            <div class="media-card">
              <a class="mc-th" href="{{ in_array($kind, ['exterior', 'interior', 'pano'], true) ? '/company/objects/'.$pid.'/3d?m='.$m->id : $m->url() }}" target="_blank">
                @if ($m->poster)<img src="{{ $m->url() }}?poster=1" alt="" loading="lazy">@elseif ($m->isImage())<img src="{{ $m->url() }}" alt="" loading="lazy">@else{!! ic($ki, '', 34) !!}@endif
                @if ($m->is_main)<span class="oc-badge">{{ __('главная') }}</span>@endif
                @if (! $m->is_public)<span class="oc-3d">{!! ic('eye-off', '', 12) !!} {{ __('скрыта') }}</span>@endif
              </a>
              <div class="mc-b"><b>{{ $m->title }}</b><small class="muted">{{ $m->layout && isset($types[$m->layout]) ? $types[$m->layout]['name'] : ($m->rooms !== null ? ($m->rooms ? $m->rooms.__('-комн.') : __('коммерция')) : __('для всех')) }}@if (! empty($m->meta['room'])) · {{ $m->meta['room'] }}@endif
                · {{ strtoupper($m->ext) }}@if (! empty($m->meta['src'])) ({{ __('из') }} {{ strtoupper(pathinfo($m->meta['src'], PATHINFO_EXTENSION)) }})@endif · {{ file_size($m->size) }}@if (! empty($m->meta['views'])) · {{ __('комнат в 3D') }}: {{ count($m->meta['views']) }}@endif</small></div>
              @if ($canMedia)
              <details class="mc-edit"><summary>{!! ic('pencil', '', 14) !!}{{ __('Настроить') }}</summary>
                <form method="post" action="/company/media/{{ $m->id }}" class="form" style="grid-template-columns:1fr 1fr;gap:8px">@csrf
                  <label class="field full"><span>{{ __('Название') }}</span><input name="title" value="{{ $m->title }}" required maxlength="160"></label>
                  @if ($types && $kind !== 'exterior')
                    <label class="field"><span>{{ __('Планировка') }}</span><select name="layout"><option value="">{{ __('для всех') }}</option>@foreach ($types as $tid => $t)<option value="{{ $tid }}" @selected($m->layout === $tid)>{{ $t['name'] }}</option>@endforeach</select></label>
                    <input type="hidden" name="rooms" value="{{ $m->rooms }}">
                  @else
                    <label class="field"><span>{{ __('Планировка') }}</span><select name="rooms"><option value="">{{ __('для всех') }}</option>@for ($r = 0; $r <= 5; $r++)<option value="{{ $r }}" @selected($m->rooms === $r)>{{ $r ? $r.__('-комн.') : __('коммерция') }}</option>@endfor</select></label>
                  @endif
                  @if ($kind === 'pano')<label class="field"><span>{{ __('Комната') }}</span><input name="room" value="{{ $m->meta['room'] ?? '' }}" maxlength="60" list="room-names" placeholder="{{ __('Гостиная') }}"></label>@endif
                  <label class="field"><span>{{ __('Порядок') }}</span><input type="number" name="sort" value="{{ $m->sort }}"></label>
                  <label class="switch full"><input type="checkbox" name="is_public" value="1" @checked($m->is_public)> {{ __('Показывать покупателям и на сайте') }}</label>
                  @if ($kind === 'exterior')<label class="switch full"><input type="checkbox" name="is_main" value="1" @checked($m->is_main)> {{ __('Главная модель объекта') }}</label>
                    <input type="hidden" name="opts" value="1">
                    <label class="switch full"><input type="checkbox" name="sky" value="1" @checked($m->meta['sky'] ?? false)> {{ __('Небо и город вокруг (для моделей с кварталом)') }}</label>
                    <div class="full muted" style="font-size:13px">
                      {!! ic('building-2', '', 14) !!} {{ __('Готовый дом') }}: @if (! empty($m->meta['finished']))<b style="color:var(--ok)">{{ __('загружен') }}</b>
                        <label class="switch" style="display:inline-flex;margin-left:8px"><input type="checkbox" name="drop_finished" value="1"> {{ __('убрать') }}</label>@else{{ __('нет — загрузите справа: «Состояние здания» → «Готовый дом для…»') }}@endif
                      <br>{!! ic('moon', '', 14) !!} {{ __('День / ночь') }}: {{ ! empty($m->meta['daynight']) ? __('есть в модели') : __('нет (в модели нет вариантов day/night)') }}
                    </div>
                  @endif
                  <div class="full flex"><button class="btn btn-p btn-s">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
                </form>
                <form method="post" action="/company/media/{{ $m->id }}" data-confirm="{{ __('Удалить файл?') }}">@csrf @method('delete')<button class="btn btn-s btn-ghost">{!! ic('trash-2') !!}{{ __('Удалить') }}</button></form>
              </details>
              @endif
            </div>
          @endforeach
        </div>
      </div>
    @endforeach
    @if (! $media->count())<div class="card"><div class="empty">{!! ic('box') !!}<div>{{ __('Загрузите 3D-модель здания, интерьеры квартир, панорамы 360° и планировки — покупатели увидят их в кабинете и на сайте.') }}</div></div></div>@endif
  </div>

  <div>
    @if ($canMedia)
    <div class="card" id="upload-card">
      <div class="card-h"><h3>{!! ic('upload') !!}{{ __('Загрузить') }}</h3></div>
      <div class="form" style="grid-template-columns:1fr">
        <label class="field"><span>{{ __('Что загружаем') }}</span><select id="up-kind">@foreach ($K as $k => [$n, $i, $ex])<option value="{{ $k }}" data-ext="{{ implode(',', array_map(fn ($e) => '.'.$e, $ex)) }}">{{ __($n) }} ({{ in_array($k, ['exterior', 'interior'], true) ? 'GLB, FBX, OBJ, DAE, IFC…' : implode(', ', $ex) }})</option>@endforeach</select></label>
        @php $exts3d = $media->where('kind', 'exterior')->whereIn('ext', ['glb', 'gltf']); @endphp
        <label class="field" id="up-attach-f"><span>{{ __('Состояние здания') }}</span><select id="up-attach">
          <option value="">{{ __('Сейчас (как идёт стройка) — новая модель') }}</option>
          @foreach ($exts3d as $e)<option value="{{ $e->id }}">{{ __('Готовый дом для') }}: {{ \Illuminate\Support\Str::limit($e->title, 50) }}@if (! empty($e->meta['finished'])) ({{ __('заменить') }})@endif</option>@endforeach
        </select><small>{{ __('Сначала загрузите модель «как сейчас», потом ту же модель в готовом виде — в 3D появятся кнопки «Сейчас / Готовый дом».') }}</small></label>
        @if ($types)
        <label class="field" id="up-layout-f"><span>{{ __('Для какой планировки') }}</span><select id="up-layout"><option value="">{{ __('для всех') }}</option>@foreach ($types as $tid => $t)<option value="{{ $tid }}" @selected(request('layout') === $tid)>{{ $t['name'] }}</option>@endforeach</select></label>
        <input type="hidden" id="up-rooms" value="">
        @else
        <label class="field" id="up-layout-f"><span>{{ __('Для какой планировки') }}</span><select id="up-rooms"><option value="">{{ __('для всех') }}</option>@for ($r = 1; $r <= 5; $r++)<option value="{{ $r }}">{{ $r }}{{ __('-комн.') }}</option>@endfor<option value="0">{{ __('коммерция') }}</option></select>
          @if (auth()->user()->managesObjects())<small><a href="?tab=units">{{ __('Заведите планировки в «Помещения» — файлы привяжутся к ним') }}</a></small>@endif</label>
        @endif
        <label class="field" id="up-room-f"><span>{{ __('Комната на панораме') }}</span><input id="up-room" maxlength="60" list="room-names" placeholder="{{ __('Гостиная, кухня, спальня…') }}">
          <small>{{ __('Можно выбрать сразу несколько панорам: комната возьмётся из имени файла (Кухня.jpg).') }}</small></label>
        <label class="field"><span>{{ __('Название') }}</span><input id="up-title" maxlength="160" placeholder="{{ __('Например: Фасад, вид с улицы') }}"></label>
        <label class="drop" id="drop"><input type="file" id="up-file" hidden multiple>{!! ic('upload', '', 26) !!}<b>{{ __('Перетащите файлы сюда или нажмите') }}</b><small id="up-hint"></small></label>
        <div id="cv" class="cv" hidden>
          <div class="cv-view" id="cv-view"></div>
          <div class="cv-info" id="cv-info"></div>
          <div class="cv-opts">
            <label class="field"><span>{{ __('Единицы модели') }}</span><select id="cv-units"><option value="auto">{{ __('определить сами') }}</option><option value="m">{{ __('метры') }}</option><option value="cm">{{ __('сантиметры') }}</option><option value="mm">{{ __('миллиметры') }}</option><option value="in">{{ __('дюймы') }}</option><option value="ft">{{ __('футы') }}</option></select></label>
            <label class="field"><span>{{ __('Верх модели') }}</span><select id="cv-up"><option value="auto">{{ __('как в формате') }}</option><option value="y">Y</option><option value="z">{{ __('Z (повернуть)') }}</option></select></label>
            <label class="field"><span>{{ __('Текстуры') }}</span><select id="cv-tex"><option value="1024">1024 px — {{ __('легче') }}</option><option value="2048" selected>2048 px</option><option value="4096">4096 px — {{ __('чётче') }}</option></select></label>
            <label class="check"><input type="checkbox" id="cv-merge" checked> {{ __('Объединить детали (открывается быстрее)') }}</label>
          </div>
          <div class="flex"><button type="button" class="btn btn-p" id="cv-go">{!! ic('upload') !!}{{ __('Загрузить модель') }}</button><button type="button" class="btn" id="cv-redo">{{ __('Пересчитать') }}</button><button type="button" class="btn btn-ghost" id="cv-cancel">{{ __('Отмена') }}</button></div>
        </div>
        <div id="cv-step" class="muted" hidden></div>
        <div id="up-prog" hidden><div class="hb-t" style="display:flex;justify-content:space-between;font-size:13px"><span id="up-name"></span><b id="up-pct">0%</b></div><div class="prog" style="height:8px"><i id="up-bar" style="width:0"></i></div></div>
      </div>
      <datalist id="room-names">@foreach (['Гостиная', 'Кухня', 'Кухня-гостиная', 'Спальня', 'Детская', 'Ванная', 'Санузел', 'Прихожая', 'Коридор', 'Балкон', 'Лоджия', 'Кабинет', 'Гардеробная', 'Терраса'] as $r)<option value="{{ __($r) }}">@endforeach</datalist>
    </div>
    @endif
    <div class="card mt">
      <div class="card-h"><h3>{!! ic('circle-help') !!}{{ __('Как подготовить 3D-модель') }}</h3></div>
      <ol class="steps-list" style="font-size:14px">
        <li>{!! __('<b>Подходит модель из любой программы.</b> Загружайте как есть: GLB, GLTF, FBX, OBJ (+MTL и текстуры), DAE, 3DS, IFC, STL, PLY, 3MF, USDZ, KMZ, VRML, 3DM или ZIP-архив с моделью и текстурами. Программа сама переведёт её в GLB, покажет результат и только потом загрузит.') !!}</li>
        <li>{!! __('<b>SketchUp:</b> Файл → Экспорт → 3D-модель → COLLADA (.dae) или glTF. <b>Revit и ArchiCAD:</b> IFC или FBX. <b>3ds Max, Cinema 4D, Maya:</b> FBX или OBJ. <b>Blender:</b> glTF Binary (.glb). <b>Rhino:</b> файл .3dm как есть. Lumion и Twinmotion: экспорт в FBX/glTF или модель из исходной программы.') !!}</li>
        <li>{!! __('<b>Фасад</b> — модель всего здания с участком. <b>Интерьер</b> — модель квартиры каждой планировки с мебелью; внутри можно отметить комнаты кнопкой «Комнаты» в просмотре.') !!}</li>
        <li>{!! __('<b>Размер:</b> лучше до 50–80 МБ. Если модель тяжёлая — выберите текстуры 1024 px и оставьте «Объединить детали».') !!}</li>
        <li>{!! __('<b>Сейчас и Готовый дом:</b> загрузите модель в текущем состоянии стройки, затем ту же модель в готовом виде, выбрав «Состояние здания» → «Готовый дом для…». Обновляйте модель «Сейчас» по мере стройки (раз в месяц).') !!}</li>
        <li>{!! __('<b>День и ночь:</b> если в модели есть варианты материалов «day» и «night» (KHR_materials_variants — Blender, 3ds Max через Babylon/Khronos exporter), кнопки День/Ночь и небо включатся сами.') !!}</li>
        <li>{!! __('<b>Панорама 360°</b> — JPG 2:1 (например 6000×3000) из рендера Lumion, Enscape, Corona или с камеры 360°. Для каждой комнаты планировки — своя панорама.') !!}</li>
        <li>{!! __('После загрузки откройте модель, поверните на красивый ракурс и нажмите <b>«Сохранить вид»</b> — это станет превью и стартовым видом для покупателей.') !!}</li>
      </ol>
    </div>
  </div>
</div>
@if ($canMedia)
@push('head')
{{-- three.js для перевода моделей в GLB (грузится только при выборе не-GLB файла) --}}
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.180.0/examples/jsm/"}}</script>
@endpush
@push('scripts')
@php
  $CVT = [
    'p_zip' => __('Распаковываю архив…'), 'p_read' => __('Читаю модель…'), 'p_lib' => __('Загружаю модуль чтения формата…'), 'p_ifc' => __('Разбираю IFC (это может занять минуту)…'),
    'p_opt' => __('Объединяю детали и привожу к метрам…'), 'p_glb' => __('Сохраняю в GLB…'), 'p_up' => __('Загружаю на сервер…'),
    'unknown' => __('Этот формат не удалось прочитать. Сохраните модель в GLB, FBX, OBJ, DAE или IFC.'), 'noModel' => __('Среди выбранных файлов нет 3D-модели. Выберите файл модели (и, если есть, папку с текстурами или ZIP).'),
    'emptyModel' => __('В модели нет видимых объектов.'), 'ifcBad' => __('Файл IFC повреждён или не поддерживается.'), 'ifcEmpty' => __('В файле IFC нет геометрии (только данные). Экспортируйте IFC с 3D-видом.'),
    'x_any' => __(':p (:e) — это файл программы, браузер его не прочитает. Экспортируйте модель в FBX, OBJ, DAE, IFC или GLB и загрузите полученный файл.'),
    'x_skp' => __('SketchUp (.skp): откройте модель в SketchUp → Файл → Экспорт → 3D-модель → COLLADA (.dae) или glTF (.glb) и загрузите полученный файл (вместе с папкой текстур или ZIP).'),
    'x_rvt' => __('Revit (.rvt): Файл → Экспорт → IFC (или FBX) и загрузите полученный файл.'), 'x_rfa' => __('Revit (.rfa): Файл → Экспорт → IFC (или FBX) и загрузите полученный файл.'),
    'x_max' => __('3ds Max (.max): Файл → Экспорт → FBX (или OBJ) с текстурами и загрузите полученный файл или ZIP.'),
    'x_blend' => __('Blender (.blend): Файл → Экспорт → glTF 2.0 (.glb) и загрузите полученный файл.'),
    'x_pln' => __('ArchiCAD (.pln): Файл → Сохранить как → IFC (или 3DS / DAE) и загрузите полученный файл.'), 'x_pla' => __('ArchiCAD (.pla): Файл → Сохранить как → IFC и загрузите полученный файл.'),
    'x_dwg' => __('AutoCAD (.dwg): экспортируйте 3D-модель в FBX (команда FBXEXPORT) или откройте в 3ds Max/Revit и сохраните в FBX.'),
    'x_c4d' => __('Cinema 4D (.c4d): Файл → Экспорт → glTF или FBX и загрузите полученный файл.'),
    'fail' => __('Не удалось перевести модель'), 'tris' => __('треугольников'), 'mats' => __('материалов'), 'texs' => __('текстур'), 'size' => __('Размер'), 'unit' => __('единицы'),
    'heavy' => __('Модель тяжёлая — на телефоне может открываться долго. Выберите текстуры 1024 px.'), 'tiny' => __('Модель получилась крошечной — проверьте единицы.'), 'huge' => __('Модель получилась огромной — проверьте единицы.'),
    'w' => __('Ш'), 'd' => __('Г'), 'h' => __('В'), 'm' => __('м'), 'MB' => __('МБ'), 'from' => __('из'), 'ok' => __('Модель готова. Покрутите её мышью и загрузите.'),
  ];
  $mcUrl = asset('assets/model-convert.js').'?v='.@filemtime(public_path('assets/model-convert.js'));
@endphp
<script>
(() => {
  const kind = document.getElementById('up-kind'), file = document.getElementById('up-file'), drop = document.getElementById('drop');
  const $ = id => document.getElementById(id), T = @json($CVT), MC = @json($mcUrl);
  const ACC = { exterior: '.glb,.gltf,.bin,.fbx,.obj,.mtl,.dae,.kmz,.wrl,.vrml,.usdz,.ifc,.3ds,.stl,.ply,.3mf,.3dm,.zip,.jpg,.jpeg,.png,.webp,.tga,.bmp,.tif,.tiff,.dds,.ktx2,.skp,.rvt,.rfa,.max,.blend,.pln,.pla,.c4d,.dwg',
    pano: '.jpg,.jpeg,.png,.webp', plan: '.jpg,.jpeg,.png,.webp,.pdf', photo: '.jpg,.jpeg,.png,.webp', video: '.mp4,.webm' };
  ACC.interior = ACC.exterior;
  const is3d = () => ['exterior', 'interior'].includes(kind.value);
  @if (request('kind') && isset($K[request('kind')])) kind.value = @json(request('kind')); @endif
  const sync = () => {
    file.accept = ACC[kind.value];
    $('up-hint').textContent = (is3d() ? 'GLB, FBX, OBJ, DAE, IFC, 3DS, STL, ZIP…' : ACC[kind.value].replace(/\./g, '').toUpperCase()) + ' · ' + @json(__('до 400 МБ'));
    $('up-layout-f').style.display = ['interior', 'pano', 'plan'].includes(kind.value) ? '' : 'none';
    $('up-room-f').style.display = kind.value === 'pano' ? '' : 'none';
    $('up-attach-f').style.display = kind.value === 'exterior' && $('up-attach').options.length > 1 ? '' : 'none';
  };
  kind.addEventListener('change', sync); sync();
  ['dragover', 'dragenter'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => e.dataTransfer.files.length && pick([...e.dataTransfer.files]));
  file.addEventListener('change', () => { file.files.length && pick([...file.files]); file.value = ''; });
  const step = t => { $('cv-step').hidden = !t; $('cv-step').textContent = t || ''; };
  const busy = on => { drop.style.pointerEvents = on ? 'none' : ''; drop.style.opacity = on ? .6 : ''; };

  /* выбор файлов: 3D — через перевод в GLB (кроме готового GLB), картинки и видео — по одному */
  let cvFiles = null, cvRes = null, cvView = null, mod = null;
  async function pick(files) {
    if (is3d()) {
      if (files.length === 1 && /\.glb$/i.test(files[0].name)) return upload([[files[0], {}]]);
      cvFiles = files; return convert();
    }
    const room = $('up-room').value.trim();
    return upload(files.map(f => [f, kind.value === 'pano' && (files.length > 1 || !room) ? { room: f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim() } : {}]));
  }
  async function convert() {
    busy(true); step(T.p_read);
    try {
      mod = mod || await import(MC);
      cvRes = await mod.convert(cvFiles, { kind: kind.value, units: $('cv-units').value, up: $('cv-up').value, tex: $('cv-tex').value, merge: $('cv-merge').checked, T }, step);
    } catch (e) { busy(false); step(''); console.error(e); return ui.alert(e && e.message ? e.message : T.fail); }
    const i = cvRes.info, s = i.size, mb = (i.bytes / 1048576).toFixed(1), n = v => (Math.round(v * 10) / 10).toLocaleString('ru-RU');
    const warn = i.bytes > 120 * 1048576 || i.tris > 4e6 ? `<div class="cv-warn">${T.heavy}</div>` : Math.max(s.x, s.y, s.z) < 0.5 ? `<div class="cv-warn">${T.tiny}</div>` : Math.max(s.x, s.y, s.z) > 3000 ? `<div class="cv-warn">${T.huge}</div>` : '';
    $('cv-info').innerHTML = `<b>${i.format} → GLB</b> · ${mb} ${T.MB} · ${Math.round(i.tris).toLocaleString('ru-RU')} ${T.tris} · ${i.materials} ${T.mats} · ${i.textures} ${T.texs}<br>
      ${T.size}: ${T.w} ${n(s.x)} × ${T.d} ${n(s.z)} × ${T.h} ${n(s.y)} ${T.m} · ${T.unit}: ${i.unit}${warn}`;
    $('cv').hidden = false; step(T.ok); busy(false);
    if (cvView) cvView.dispose();
    cvView = await mod.preview($('cv-view'), cvRes.scene);
  }
  $('cv-redo').onclick = () => cvFiles && convert();
  ['cv-units', 'cv-up', 'cv-tex', 'cv-merge'].forEach(id => $(id).addEventListener('change', () => cvFiles && convert()));
  $('cv-cancel').onclick = () => { if (cvView) cvView.dispose(); cvView = null; cvRes = cvFiles = null; $('cv').hidden = true; step(''); };
  $('cv-go').onclick = () => cvRes && upload([[cvRes.file, { src: cvRes.info.source }]]);

  /* загрузка частями по 5 МБ (большие модели проходят даже при маленьком лимите сервера) */
  async function upload(items) {
    busy(true); $('cv-go').disabled = true; $('up-prog').hidden = false;
    for (const [f, extra] of items) {
      const CH = 5 * 1024 * 1024, total = Math.max(1, Math.ceil(f.size / CH)), id = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
      $('up-name').textContent = (extra.src ? extra.src + ' → ' : '') + f.name;
      for (let i = 0; i < total; i++) {
        const fd = new FormData(); fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
        fd.append('upload', id); fd.append('index', i); fd.append('total', total); fd.append('name', f.name); fd.append('chunk', f.slice(i * CH, (i + 1) * CH), 'part');
        fd.append('kind', kind.value); fd.append('rooms', $('up-rooms').value); fd.append('title', items.length > 1 ? '' : $('up-title').value);
        if ($('up-layout') && ['interior', 'pano', 'plan'].includes(kind.value)) fd.append('layout', $('up-layout').value);
        if (kind.value === 'pano') fd.append('room', extra.room || $('up-room').value.trim());
        if (extra.src) fd.append('src', extra.src);
        if (kind.value === 'exterior' && $('up-attach').value) fd.append('attach', $('up-attach').value);
        let r, tries = 0;
        do { r = await fetch('/company/objects/{{ $pid }}/media', { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).catch(() => null); } while ((!r || r.status >= 500) && ++tries < 3);
        if (!r || !r.ok) { let m = @json(__('Ошибка загрузки')); try { const j = await r.json(); m = j.error || j.message || m; } catch (e) {} await ui.alert(m); location.reload(); return; }
        const p = Math.round((i + 1) / total * 100); $('up-pct').textContent = p + '%'; $('up-bar').style.width = p + '%';
      }
    }
    location.href = '?tab=media';
  }
  @if (request('kind')) document.getElementById('upload-card')?.scrollIntoView({ block: 'start' }); @endif
})();
</script>
@endpush
@endif
@endif
@endsection
