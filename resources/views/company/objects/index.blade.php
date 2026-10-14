@extends('layouts.panel')
@section('title', __('Объекты и 3D'))
@php $u = auth()->user(); $money = $u->seesMoney() || $u->role === 'manager'; @endphp
@section('actions')
@if ($u->managesObjects() && $u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-new">{!! ic('plus') !!}{{ __('Добавить объект') }}</button>@endif
@endsection
@section('content')
@if ($u->managesObjects() && $u->canEdit())
<dialog class="modal" id="dlg-new">
  <div class="modal-h"><h3>{!! ic('building-2') !!}{{ __('Новый объект') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <form method="post" action="/company/objects" class="modal-b form" style="grid-template-columns:1fr">@csrf
    <label class="field"><span>{{ __('Название объекта') }}</span><input name="name" required maxlength="120" value="{{ old('name') }}" placeholder="{{ __('ЖК «Новый Город»') }}"></label>
    <label class="field"><span>{{ __('Адрес') }}</span><input name="address" maxlength="200" value="{{ old('address') }}" placeholder="{{ __('Ташкент, Юнусабадский район') }}"></label>
    <small class="muted">{{ __('После создания откроется «Помещения»: корпуса, подъезды, квартиры, планировки и цены. 3D-модели — во вкладке «3D, панорамы, планировки».') }}</small>
    <div class="flex"><button class="btn btn-p">{!! ic('check') !!}{{ __('Создать объект') }}</button><button type="button" class="btn" data-close>{{ __('Отмена') }}</button></div>
  </form>
</dialog>
@if ($errors->has('name'))<script>document.addEventListener('DOMContentLoaded', () => document.getElementById('dlg-new').showModal());</script>@endif
@endif
<div class="alert alert-info">{!! ic('building-2') !!}<div>{{ __('Каждый объект ведётся отдельно: свои деньги, сотрудники, продажи, склад, 3D-модели. Прораб объекта видит только свой объект.') }}</div></div>
<div class="obj-grid">
  @forelse ($list as $o)
    @php $p = $o['p']; $s = $o['s']; $id = (string) $p['id']; @endphp
    <a class="obj-card" href="/company/objects/{{ $id }}">
      <div class="oc-cover">
        @if ($o['main'] && $o['main']->poster)<img src="/company/media/{{ $o['main']->id }}/file?poster=1" alt="" loading="lazy">
        @elseif ($o['main'] && $o['main']->isImage())<img src="/company/media/{{ $o['main']->id }}/file" alt="" loading="lazy">
        @else<div class="oc-ph">{!! ic('building-2', '', 40) !!}</div>@endif
        <span class="oc-badge">{{ \App\Services\Inventory::ready($p) ? __($p['status'] ?? '') : __('Готовится к продаже') }}@if ($o['demo']) · {{ __('демо') }}@endif</span>
        @if ($o['media'])<span class="oc-3d">{!! ic('box', '', 13) !!} 3D · {{ $o['media'] }}</span>@endif
      </div>
      <div class="oc-body">
        <h3>{{ $p['name'] ?? '' }}</h3>
        <small class="muted">{{ $p['address'] ?? '' }}</small>
        <div class="hbar" style="margin:12px 0 10px"><div class="hb-t"><span>{{ __('Готовность') }}</span><b>{{ $s['progress'] }}%</b></div><div class="hb-r"><i style="width:{{ $s['progress'] }}%;background:var(--ok)"></i></div></div>
        <div class="oc-stats">
          <div><b>{{ $s['sold'] }}</b><span>{{ __('продано') }}</span></div>
          <div><b>{{ $s['booked'] }}</b><span>{{ __('бронь') }}</span></div>
          <div><b>{{ $s['free'] }}</b><span>{{ __('свободно') }}</span></div>
          <div><b>{{ $o['team'] }}</b><span>{{ __('в команде') }}</span></div>
        </div>
        @if ($money)<div class="sum-line" style="margin-top:10px"><span>{{ __('Получено от продаж') }}: <b class="in-c">{{ money_s($s['paid'], $cur) }}</b></span>@if ($s['overdue'] > 0)<span>{{ __('Просрочка') }}: <b class="out-c">{{ money_s($s['overdue'], $cur) }}</b></span>@endif</div>@endif
      </div>
    </a>
  @empty
    <div class="card"><div class="empty">{{ __('Объектов пока нет') }}</div></div>
  @endforelse
</div>
@endsection
