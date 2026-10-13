@extends('layouts.panel')
@section('title', __('ИИ-подключения'))
@section('content')
<div class="alert alert-info">{!! ic('bot') !!}<div>{{ __('Ключи хранятся на сервере в зашифрованном виде — компании их не видят. Все запросы ИИ-консультанта и ИИ-вывода в ежедневных отчётах идут через подключение «по умолчанию» (компания может выбрать другое из включённых).') }}</div></div>

<div class="grid g3">
  @foreach ($drivers as $k => [$name, $model, $url])
    @if ($k !== 'compatible')
    <div class="card" style="padding:16px">
      <div class="flex between"><b>{{ $name }}</b>@if ($providers->where('driver', $k)->where('is_active', true)->count())<span class="badge b-ok">{{ __('подключён') }}</span>@else<span class="badge">{{ __('нет') }}</span>@endif</div>
      <small>{{ __('модель по умолчанию') }}: <span class="code">{{ $model }}</span></small><br>
      <a href="{{ $url }}" target="_blank" rel="noopener" style="font-size:13px">{{ __('Получить ключ') }} {!! ic('arrow-up-right') !!}</a>
    </div>
    @endif
  @endforeach
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('plug') !!}{{ __('Подключения') }}</h3></div>
  @if ($providers->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Название') }}</th><th>{{ __('Модель') }}</th><th>{{ __('Ключ') }}</th><th class="n">{{ __('Запросов за месяц') }}</th><th class="n">{{ __('Токены') }}</th><th></th></tr></thead><tbody>
    @foreach ($providers as $p)
    @php $u = $usage->get($p->id); @endphp
    <tr>
      <td><b>{{ $p->name }}</b> @if ($p->is_default)<span class="badge b-brand">{{ __('по умолчанию') }}</span>@endif @unless ($p->is_active)<span class="badge b-warn">{{ __('выключен') }}</span>@endunless<br><small>{{ $p->driverName() }}</small></td>
      <td><span class="code">{{ $p->model }}</span></td>
      <td><span class="code">{{ $p->maskedKey() }}</span></td>
      <td class="n">{{ $u?->n ?? 0 }}@if ($u && $u->n - $u->okn > 0) <span class="bad">({{ __('ошибок') }} {{ $u->n - $u->okn }})</span>@endif</td>
      <td class="n"><small>{{ number_format((int) ($u?->tin ?? 0), 0, '.', ' ') }} / {{ number_format((int) ($u?->tout ?? 0), 0, '.', ' ') }}</small></td>
      <td class="right nowrap">
        <form method="post" action="/admin/ai/{{ $p->id }}/test" style="display:inline">@csrf<button class="btn btn-s">{!! ic('zap') !!}{{ __('Проверить') }}</button></form>
        <form method="post" action="/admin/ai/{{ $p->id }}" style="display:inline" data-confirm="{{ __('Удалить подключение?') }}">@csrf @method('DELETE')<button class="btn btn-s btn-d">✕</button></form>
      </td>
    </tr>
    <tr><td colspan="6" style="padding-top:0">
      <details class="more"><summary>{{ __('Изменить') }}</summary>
        <form method="post" action="/admin/ai/{{ $p->id }}" class="mt">@csrf @method('PUT')
          @include('admin.ai-form', ['p' => $p])
          <button class="btn btn-p btn-s mt">{{ __('Сохранить') }}</button>
        </form>
      </details>
    </td></tr>
    @endforeach
  </tbody></table></div>
  @else
    <div class="empty">{!! ic('bot') !!}{{ __('Подключений ещё нет — добавьте первое ниже') }}</div>
  @endif
</div>

<div class="card mt">
  <div class="card-h"><h3>{!! ic('plus') !!}{{ __('Новое подключение') }}</h3></div>
  <form method="post" action="/admin/ai">@csrf
    @include('admin.ai-form')
    <button class="btn btn-p mt">{{ __('Добавить') }}</button>
  </form>
</div>

<div class="grid g2 mt">
  <div class="card">
    <div class="card-h"><h3>{!! ic('building-2') !!}{{ __('Кто больше всего пользуется (месяц)') }}</h3></div>
    @forelse ($byCompany as $row)
      <div class="flex between" style="padding:7px 0;border-bottom:1px solid var(--line2)"><span>{{ $row->workspace?->name ?? '—' }}</span><b>{{ $row->n }}</b></div>
    @empty <div class="empty">—</div> @endforelse
  </div>
  <div class="card">
    <div class="card-h"><h3>{!! ic('triangle-alert', 'warn') !!}{{ __('Последние ошибки') }}</h3></div>
    @forelse ($aiErrors as $e)
      <div style="padding:7px 0;border-bottom:1px solid var(--line2);font-size:13px"><b>{{ $e->created_at->format('d.m H:i') }}</b> · {{ $e->workspace?->name ?? __('проверка') }} · <span class="code">{{ $e->model }}</span><br><span class="bad">{{ $e->error }}</span></div>
    @empty <div class="empty">{!! ic('circle-check', 'ok') !!}{{ __('Ошибок нет') }}</div> @endforelse
  </div>
</div>
@endsection
