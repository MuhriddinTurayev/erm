{{-- Руководитель (бухгалтер) без Telegram: заявки прорабов и кнопки согласования из бота ему не приходят — подсказываем подключить --}}
@php $me = auth()->user(); @endphp
@if (\App\Services\Telegram::enabled() && $me && ! $me->telegram_chat_id && in_array($me->role, $roles ?? ['admin'], true))
<div class="alert alert-info" style="margin-bottom:14px">{!! ic('send') !!}<div>
  <b>{{ __('Ваш Telegram не подключён.') }}</b>
  {{ __('Заявки прорабов и кнопки «Согласовать / Отклонить» приходят в бот только тем, у кого он подключён. Сейчас вы видите их только здесь, на сайте.') }}
  <a href="/me/telegram"><b>{{ __('Подключить Telegram') }} →</b></a>
</div></div>
@endif
