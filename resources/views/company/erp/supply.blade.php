@extends('layouts.panel')
@section('title', __('Снабжение'))
@php
  $u = auth()->user(); $cur = \App\Services\CompanyStats::currency($ws); $n = fn ($v) => \App\Services\Erp\Stock::n($v);
  $stl = ['new' => 'новая', 'approved' => 'согласована', 'ordered' => 'заказана', 'partial' => 'принята частично', 'received' => 'принята', 'rejected' => 'отклонена'];
  $boss = $u->isAdmin() || $u->role === 'accountant';
@endphp
@section('actions')
@if ($u->canEdit())<button class="btn btn-p btn-s" data-open="dlg-sr">{!! ic('plus') !!}{{ __('Заявка на материалы') }}</button>@endif
@endsection
@section('content')
<div class="chips" style="margin-bottom:14px">
  <a class="chip {{ ! $st ? 'on' : '' }}" href="/company/supply">{{ __('Все') }}</a>
  @foreach ($stl as $k => $v)<a class="chip {{ $st === $k ? 'on' : '' }}" href="?status={{ $k }}">{{ __($v) }}<span class="n">{{ $counts[$k] ?? 0 }}</span></a>@endforeach
</div>
<div class="alert alert-info">{!! ic('truck') !!}<div>{{ __('Прораб создаёт заявку → снабженец/бухгалтер согласует и заказывает у поставщика (видно, сколько уже есть на складе) → кладовщик принимает товар, можно частями.') }}</div></div>
@if ($boss && ! $st)
  @include('partials.insights', ['topic' => 'supply', 'title' => __('ИИ-анализ снабжения')])
@endif

@forelse ($list as $sr)
<div class="card mt">
  <div class="card-h">
    <h3>{!! ic('clipboard-list') !!}{{ __('Заявка') }} №{{ $sr->number }} @if ($sr->project_id)<span class="muted" style="font-weight:400">· {{ $projects[$sr->project_id] ?? '' }}</span>@endif</h3>
    <span class="st st-{{ $sr->status }}">{{ __($stl[$sr->status] ?? $sr->status) }}</span>
  </div>
  <div class="sum-line" style="margin-bottom:10px"><span>{{ $sr->user?->name }} · {{ $sr->created_at->format('d.m.Y') }}</span>@if ($sr->need_by)<span>{{ __('Нужно к') }}: <b class="{{ $sr->need_by->isPast() && ! in_array($sr->status, ['received', 'rejected']) ? 'out-c' : '' }}">{{ $sr->need_by->format('d.m.Y') }}</b></span>@endif
    @if ($sr->counterparty)<span>{{ __('Поставщик') }}: <b><a href="/company/parties/{{ $sr->counterparty_id }}">{{ $sr->counterparty->name }}</a></b></span>@endif @if ($sr->note)<span>{{ $sr->note }}</span>@endif</div>

  @php $action = $boss && in_array($sr->status, ['approved', 'ordered']) ? 'order' : (in_array($sr->status, ['approved', 'ordered', 'partial']) && $u->canEdit() ? 'receive' : null); @endphp
  <form method="post" action="/company/supply/{{ $sr->id }}">@csrf
  <div class="tbl-wrap"><table class="tbl lines-tbl"><thead><tr><th>{{ __('Материал') }}</th><th class="n">{{ __('Нужно') }}</th><th class="n">{{ __('Есть на складах') }}</th><th class="n">{{ __('Цена') }}</th><th class="n">{{ __('Принято') }}</th>
    @if (in_array($sr->status, ['approved', 'ordered', 'partial']))<th class="n">{{ __('Принять сейчас') }}</th>@endif</tr></thead><tbody>
  @foreach ($sr->lines as $l)
    @php $have = array_sum($bal[$l->item_id] ?? []); @endphp
    <tr><td><a href="/company/stock/items/{{ $l->item_id }}">{{ $l->item?->name }}</a></td><td class="n"><b>{{ $n($l->qty) }}</b> {{ __($l->item?->unit ?? '') }}</td>
      <td class="n {{ $have >= $l->qty ? 'in-c' : '' }}">{{ $n($have) }}</td>
      <td class="n">@if ($boss && in_array($sr->status, ['approved', 'ordered']))<input class="input" type="number" step="0.01" min="0" name="price[{{ $l->id }}]" value="{{ $l->price ?: '' }}" style="width:120px">@else{{ $l->price ? money_s($l->price, $cur) : '—' }}@endif</td>
      <td class="n">{{ $n($l->received) }}</td>
      @if (in_array($sr->status, ['approved', 'ordered', 'partial']))<td class="n"><input class="input" type="number" step="0.001" min="0" name="qty[{{ $l->id }}]" placeholder="{{ $n(max(0, $l->qty - $l->received)) }}" style="width:110px"></td>@endif
    </tr>
  @endforeach
  </tbody></table></div>
  <div class="toolbar mt" style="margin-bottom:0">
    @if ($boss && $sr->status === 'new')
      <button class="btn btn-p btn-s" name="action" value="approve">{!! ic('check') !!}{{ __('Согласовать') }}</button>
      <button class="btn btn-d btn-s" name="action" value="reject">{!! ic('x') !!}{{ __('Отклонить') }}</button>
    @endif
    @if ($boss && in_array($sr->status, ['approved', 'ordered']))
      <select class="input" name="counterparty_id"><option value="">{{ __('Поставщик') }}…</option>@foreach ($counterparties as $c)<option value="{{ $c->id }}" @selected($sr->counterparty_id === $c->id) @disabled($c->status === 'blocked')>{{ $c->name }}</option>@endforeach</select>
      <button class="btn btn-s" name="action" value="order" formnovalidate>{!! ic('send') !!}{{ __('Заказать') }}</button>
    @endif
    @if (in_array($sr->status, ['approved', 'ordered', 'partial']) && $u->canEdit())
      <span class="grow"></span>
      <select class="input" name="warehouse_id">@foreach ($whs as $w)<option value="{{ $w->id }}">{{ __($w->name) }}</option>@endforeach</select>
      <label class="switch" style="font-size:13px"><input type="checkbox" name="make_pr" value="1" @checked($sr->counterparty_id)> {{ __('и создать заявку на оплату') }}</label>
      <button class="btn btn-s btn-p" name="action" value="receive">{!! ic('package-check') !!}{{ __('Принять на склад') }}</button>
    @endif
    @if ($u->canModule('tenders') && in_array($sr->status, ['new', 'approved']))<a class="btn btn-s btn-ghost" href="/company/tenders?sr={{ $sr->id }}">{!! ic('gavel') !!}{{ __('Тендер') }}</a>@endif
  </div>
  </form>
</div>
@empty
<div class="card"><div class="empty">{!! ic('truck') !!}<div>{{ __('Заявок пока нет') }}</div></div></div>
@endforelse
<div class="mt">{{ $list->links() }}</div>

<dialog class="modal wide" id="dlg-sr"><form method="post" action="/company/supply">@csrf
  <div class="modal-h"><h3>{!! ic('clipboard-list') !!}{{ __('Заявка на материалы') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b">
    <div class="form" style="margin-bottom:14px">
      <label class="field"><span>{{ __('Объект') }}</span><select name="project_id"><option value="">—</option>@foreach ($projects as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach</select></label>
      <label class="field"><span>{{ __('Нужно к дате') }}</span><input type="date" name="need_by"></label>
      <label class="field"><span>{{ __('Комментарий') }}</span><input name="note" maxlength="300"></label>
    </div>
    <datalist id="items-list">@foreach ($items as $it)<option value="{{ $it->name }}">{{ $n(array_sum($bal[$it->id] ?? [])) }} {{ __($it->unit ?? '') }} {{ __('на складе') }}</option>@endforeach</datalist>
    <table class="tbl lines-tbl" id="sr-lines"><thead><tr><th>{{ __('Материал') }}</th><th style="width:130px">{{ __('Кол-во') }}</th><th style="width:110px">{{ __('Ед.') }}</th></tr></thead><tbody>
      @for ($i = 0; $i < 3; $i++)<tr><td><input class="input" name="lines[{{ $i }}][item]" list="items-list" placeholder="{{ __('Начните вводить название') }}"></td><td><input class="input" type="number" step="0.001" min="0" name="lines[{{ $i }}][qty]"></td><td><input class="input" name="lines[{{ $i }}][unit]" value="шт" list="units2"></td></tr>@endfor
    </tbody></table>
    <datalist id="units2">@foreach (['шт', 'кг', 'т', 'м', 'м²', 'м³', 'л', 'мешок'] as $x)<option value="{{ $x }}">@endforeach</datalist>
    <button type="button" class="btn btn-s btn-ghost mt" onclick="const b=document.querySelector('#sr-lines tbody'),r=b.rows[0].cloneNode(true),i=b.rows.length;r.querySelectorAll('input').forEach(x=>{x.name=x.name.replace(/\[\d+\]/,'['+i+']');x.value=x.name.endsWith('[unit]')?'шт':''});b.appendChild(r)">{!! ic('plus') !!}{{ __('Ещё строка') }}</button>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('send') !!}{{ __('Отправить') }}</button></div>
</form></dialog>
@endsection
