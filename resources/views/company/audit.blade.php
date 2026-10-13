@extends('layouts.panel')
@section('title', __('История изменений'))
@section('content')
<form method="get" class="card flex" style="gap:10px;flex-wrap:wrap">
  <input class="input" name="q" value="{{ $q }}" placeholder="{{ __('Поиск: сумма, объект, описание') }}" style="flex:1;min-width:220px">
  <select class="input" name="user" style="width:auto"><option value="">{{ __('Все сотрудники') }}</option>@foreach ($users as $x)<option value="{{ $x->id }}" @selected((int) request('user') === $x->id)>{{ $x->name }}</option>@endforeach</select>
  <button class="btn btn-p">{!! ic('search') !!}{{ __('Найти') }}</button>
</form>
<div class="card mt">
  @if ($logs->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Когда') }}</th><th>{{ __('Кто') }}</th><th>{{ __('Объект') }}</th><th>{{ __('Что изменено') }}</th></tr></thead><tbody>
  @foreach ($logs as $l)
    @php $kind = explode('.', $l->action)[1] ?? ''; @endphp
    <tr><td class="nowrap">{{ $l->created_at?->format('d.m.Y H:i') }}</td><td>{{ $l->user?->name ?? '—' }}</td><td>{{ $l->project ?? '—' }}</td>
      <td><span class="badge {{ ['add' => 'b-ok', 'del' => 'b-bad', 'edit' => 'b-warn'][$kind] ?? 'b-info' }}">{{ __(['add' => 'добавлено', 'del' => 'удалено', 'edit' => 'изменено'][$kind] ?? 'изменено') }}</span> {{ sys_tr($l->summary) }}</td></tr>
  @endforeach
  </tbody></table></div>
  <div class="mt">{{ $logs->links() }}</div>
  @else
  <div class="empty">{!! ic('history') !!}<div>{{ __('Изменений пока нет') }}</div></div>
  @endif
</div>
@endsection
