@extends('layouts.panel')
@section('title', __('Администраторы платформы'))
@section('actions')
<button class="btn btn-p btn-s" data-open="dlg-adm">{!! ic('user-plus') !!}{{ __('Назначить администратора') }}</button>
@endsection
@section('content')
<div class="alert alert-info">{!! ic('user-cog') !!}<div>{{ __('Владелец платформы может всё. Администратору вы выдаёте только нужные разделы: например, менеджер по продажам видит компании и оплаты, а контент-менеджер — только тексты сайта и блог.') }}</div></div>
<div class="card">
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ __('Администратор') }}</th><th>{{ __('Права') }}</th><th>{{ __('Последний вход') }}</th><th></th></tr></thead><tbody>
  @foreach ($admins as $a)
    <tr class="{{ $a->is_active === false ? 'dim' : '' }}">
      <td><div class="who"><span class="avatar">{{ initials($a->name) }}</span><div><b>{{ $a->name }}</b>@if ($a->id === auth()->id()) <small class="badge">{{ __('это вы') }}</small>@endif<br><small>{{ $a->email }}</small></div></div></td>
      <td>@if ($a->isOwner())<span class="st st-done">{!! ic('crown', '', 13) !!}{{ __('Владелец — все права') }}</span>@else
        <div class="chips">@forelse ((array) $a->admin_perms as $p)<span class="badge b-brand">{{ __($perms[$p] ?? $p) }}</span>@empty<span class="muted">{{ __('нет прав') }}</span>@endforelse</div>@endif</td>
      <td><small>{{ $a->last_login_at?->format('d.m.Y H:i') ?? '—' }}</small></td>
      <td class="right nowrap">
        <button class="btn btn-s" data-open="dlg-edit-{{ $a->id }}">{!! ic('pencil') !!}</button>
        @if ($a->id !== auth()->id())<form method="post" action="/admin/admins/{{ $a->id }}" style="display:inline" data-confirm="{{ __('Удалить администратора :n?', ['n' => $a->name]) }}">@csrf @method('delete')<button class="btn btn-s btn-ghost">{!! ic('trash-2') !!}</button></form>@endif
      </td>
    </tr>
    <dialog class="modal" id="dlg-edit-{{ $a->id }}"><form method="post" action="/admin/admins/{{ $a->id }}">@csrf @method('put')
      <div class="modal-h"><h3>{!! ic('user-cog') !!}{{ $a->name }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
      <div class="modal-b">
        <label class="switch"><input type="checkbox" name="owner" value="1" @checked($a->isOwner())> {{ __('Владелец платформы (все права, может назначать администраторов)') }}</label>
        <div class="check-list mt">@foreach ($perms as $k => $n)<label><input type="checkbox" name="perms[]" value="{{ $k }}" @checked(in_array($k, (array) $a->admin_perms, true))> {{ __($n) }}</label>@endforeach</div>
        <label class="switch mt"><input type="checkbox" name="is_active" value="1" @checked($a->is_active !== false)> {{ __('Доступ включён') }}</label>
        <label class="field mt"><span>{{ __('Новый пароль (необязательно)') }}</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password"></label>
      </div>
      <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Сохранить') }}</button></div>
    </form></dialog>
  @endforeach
  </tbody></table></div>
</div>

<dialog class="modal" id="dlg-adm"><form method="post" action="/admin/admins">@csrf
  <div class="modal-h"><h3>{!! ic('user-plus') !!}{{ __('Новый администратор') }}</h3><button type="button" class="icon-btn" data-close>{!! ic('x') !!}</button></div>
  <div class="modal-b form">
    <label class="field"><span>{{ __('Имя') }}</span><input name="name" required maxlength="80" value="{{ old('name') }}"></label>
    <label class="field"><span>Email</span><input type="email" name="email" required value="{{ old('email') }}"></label>
    <label class="field full"><span>{{ __('Пароль') }}</span><input name="password" required minlength="10" value="{{ \Illuminate\Support\Str::password(14, symbols: false) }}"><small>{{ __('не меньше 10 символов, буквы и цифры — передайте его администратору') }}</small></label>
    <div class="full check-list"><b>{{ __('Разделы') }}</b>@foreach ($perms as $k => $n)<label><input type="checkbox" name="perms[]" value="{{ $k }}"> {{ __($n) }}</label>@endforeach</div>
    <label class="switch full"><input type="checkbox" name="owner" value="1"> {{ __('Сделать владельцем (все права)') }}</label>
  </div>
  <div class="modal-f"><button type="button" class="btn" data-close>{{ __('Отмена') }}</button><button class="btn btn-p">{!! ic('check') !!}{{ __('Назначить') }}</button></div>
</form></dialog>
@endsection
