@extends('layouts.panel')
@section('title', __('Кто что вносит — инструкция'))
@php
  $R = config('oftob.roles');
  // кто, где, что видит руководитель
  $scenarios = [
    ['package-plus', __('Привезли 10 тонн цемента'), [
      ['foreman', __('Прораб или кладовщик'), __('Склад → «Приход»: товар «Цемент М400», 10 т, цена за тонну, поставщик. Галочка «Создать заявку на оплату» — включена.'), '/company/stock'],
      ['accountant', __('Бухгалтер'), __('Заявки на оплату → проверяет цену и договор, нажимает «Согласовать».'), '/company/payments'],
      ['admin', __('Руководитель'), __('Получает кнопку в Telegram или открывает заявку — «Утвердить».'), '/company/payments'],
      ['cashier', __('Кассир'), __('Открывает утверждённую заявку → «Оплатить» из нужной кассы. Расход сразу в бухгалтерии объекта.'), '/company/payments?tab=approved'],
    ], __('Цемент на складе объекта, долг поставщику закрыт, расход «Материалы» виден в кассе, прогнозе и вечернем отчёте.')],
    ['wallet', __('Мелкий ежедневный расход (топливо, такси, крепёж)'), [
      ['foreman', __('Прораб'), __('Пишет боту в Telegram одной строкой: «Солярка 850 000 АЗС» или фото чека — ИИ сам прочитает сумму.'), '/me/telegram'],
      ['admin', __('Руководитель'), __('Если сумма больше лимита согласования — подтверждает кнопкой в Telegram. Меньше — проводится сразу.'), '/company/approvals'],
    ], __('Расход попадает в бухгалтерию объекта с чеком, статьёй и автором.')],
    ['package-minus', __('Материал выдали бригаде на объект'), [
      ['foreman', __('Прораб / кладовщик'), __('Склад → «Выдать»: товар, количество, объект. Программа не даст выдать больше, чем есть.'), '/company/stock'],
    ], __('Себестоимость объекта растёт на стоимость выданного, остаток склада уменьшается, при нехватке — предупреждение.')],
    ['truck', __('Нужен материал, которого нет на складе'), [
      ['foreman', __('Прораб'), __('Снабжение → «Заявка на материалы»: что, сколько, к какой дате.'), '/company/supply'],
      ['accountant', __('Бухгалтер / снабженец'), __('Согласует, выбирает поставщика (или проводит тендер), указывает цены → «Заказать».'), '/company/supply'],
      ['foreman', __('Кладовщик'), __('Когда привезли — «Принять на склад» (можно частями) и «создать заявку на оплату».'), '/company/supply'],
    ], __('Видно, что заказано, что пришло и сколько должны поставщику.')],
    ['hand-coins', __('Зарплата рабочим'), [
      ['cashier', __('Кассир'), __('Кассы → «Зарплата»: сотрудник или бригада, сумма, объект.'), '/company/cash'],
    ], __('Выплата в статье «Зарплата» объекта, остаток кассы уменьшается.')],
    ['key-round', __('Продали квартиру / покупатель заплатил'), [
      ['manager', __('Менеджер по продажам'), __('Продажи квартир → нажать на квартиру в шахматке → «Бронь» или «Продана», покупатель, цена, рассрочка.'), '/company/sales'],
      ['manager', __('Менеджер или кассир'), __('В той же карточке — «Принять оплату». Создать кабинет покупателя и отправить ссылку клиенту.'), '/company/sales'],
    ], __('Деньги сразу в поступлениях объекта, график рассрочки и просрочки считаются сами, покупатель видит всё в кабинете и 3D.')],
    ['camera', __('Что сделано за день на объекте'), [
      ['foreman', __('Прораб'), __('Фото и описание — в Telegram-бот или «Журнал → Фотоотчёт» с телефона (с геометкой).'), '/company/journal'],
    ], __('Руководитель видит фото в мониторинге, покупатели — в кабинете.')],
  ];
  $roleCards = [
    ['admin', 'briefcase', __('Утверждает платежи, назначает сотрудников и их доступ, смотрит мониторинг и вечерний отчёт.')],
    ['accountant', 'calculator', __('Договоры, контрагенты, согласование заявок, лимиты статей, склад и сверки.')],
    ['cashier', 'wallet', __('Оплачивает утверждённые заявки, приходы и расходы по кассам, зарплата, обмен валюты.')],
    ['manager', 'key-round', __('Шахматка, бронь и продажа квартир, оплаты покупателей, 3D для клиентов, заявки с сайта.')],
    ['foreman', 'hard-hat', __('Свой объект: приход и выдача материалов, заявки на снабжение и оплату, расходы через Telegram, фотоотчёты, задачи.')],
    ['viewer', 'eye', __('Инвестор или учредитель: видит всё, ничего не меняет.')],
  ];
@endphp
@section('content')
<div class="alert alert-info">{!! ic('circle-help') !!}<div>{{ __('Правило простое: каждый вносит то, что делает сам, в момент, когда это произошло. Деньги никогда не уходят без заявки, материал не приходит без записи на склад — поэтому руководитель вечером видит точную картину.') }}</div></div>

<h3 style="margin:22px 0 12px">{{ __('Кто за что отвечает') }}</h3>
<div class="guide-roles">
  @foreach ($roleCards as [$r, $i, $t])
    <div class="gr {{ auth()->user()->role === $r ? 'me' : '' }}"><span class="gr-ic">{!! ic($i, '', 20) !!}</span><div><b>{{ __($R[$r] ?? $r) }}</b>@if (auth()->user()->role === $r) <span class="badge b-brand">{{ __('это вы') }}</span>@endif<p>{{ $t }}</p></div></div>
  @endforeach
</div>

<h3 style="margin:26px 0 12px">{{ __('Частые ситуации — пошагово') }}</h3>
@foreach ($scenarios as [$icon, $title, $steps, $result])
<div class="card guide-sc">
  <div class="card-h"><h3>{!! ic($icon) !!}{{ $title }}</h3></div>
  <ol class="g-steps">
    @foreach ($steps as $k => [$role, $who, $what, $href])
      <li><span class="g-n">{{ $k + 1 }}</span><div><span class="badge {{ auth()->user()->role === $role ? 'b-brand' : 'b-info' }}">{{ $who }}</span><p>{{ $what }}</p>
        @if (auth()->user()->isAdmin() || auth()->user()->role === $role)<a class="btn btn-s btn-ghost" href="{{ $href }}">{{ __('Открыть') }}{!! ic('chevron-right') !!}</a>@endif</div></li>
    @endforeach
  </ol>
  <div class="g-res">{!! ic('circle-check') !!}<span>{{ $result }}</span></div>
</div>
@endforeach

<div class="card guide-sc" id="3d">
  <div class="card-h"><h3>{!! ic('box') !!}{{ __('3D-модели: что и в каком порядке загружать') }}</h3></div>
  <ol class="g-steps">
    <li><span class="g-n">1</span><div><span class="badge b-info">{{ __('Архитектор / дизайнер') }}</span><p>{{ __('Из проекта (Revit, ArchiCAD, SketchUp, 3ds Max, Blender) экспортирует здание целиком с участком в формат GLB. Текстуры — до 2048 px, сжатие Draco, размер лучше до 50 МБ.') }}</p></div></li>
    <li><span class="g-n">2</span><div><span class="badge b-info">{{ __('Менеджер или руководитель') }}</span><p>{{ __('Объекты и 3D → объект → «3D, панорамы, планировки» → «Фасад и здание 3D» → загрузить файл. Первая модель здания становится главной.') }}</p></div></li>
    <li><span class="g-n">2+</span><div><span class="badge b-info">{{ __('Менеджер или руководитель') }}</span><p>{{ __('Готовый вид дома: та же модель, но достроенная → «Состояние здания» → «Готовый дом для…». Модель «Сейчас» обновляйте раз в месяц по фото с площадки. Если в модели есть варианты day/night — появятся кнопки День/Ночь.') }}</p></div></li>
    <li><span class="g-n">3</span><div><span class="badge b-info">{{ __('Менеджер') }}</span><p>{{ __('Для каждой планировки (1-, 2-, 3-комнатной) — отдельная модель интерьера с мебелью: вид «Интерьер квартиры 3D» и номер планировки. Покупатель 2-комнатной увидит именно свою.') }}</p></div></li>
    <li><span class="g-n">4</span><div><span class="badge b-info">{{ __('Менеджер') }}</span><p>{{ __('По желанию: панорамы 360° (JPG 2:1) из рендера, планировки (JPG/PDF), видео-обзор.') }}</p></div></li>
    <li><span class="g-n">5</span><div><span class="badge b-info">{{ __('Менеджер') }}</span><p>{{ __('Открыть модель, повернуть на красивый ракурс, нажать «Сохранить вид» — это превью карточки и стартовый вид для покупателей. Включить «Показывать покупателям и на сайте».') }}</p></div></li>
  </ol>
  <div class="g-res">{!! ic('circle-check') !!}<span>{{ __('Модель видна в кабинете покупателя, на шахматке для сайта и в продажах. На телефоне — кнопка «Посмотреть в AR».') }}</span></div>
</div>
@endsection
@push('head')
<style>
.guide-roles{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
.gr{display:flex;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:14px}.gr.me{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.gr-ic{width:40px;height:40px;border-radius:10px;background:var(--accent-soft);color:var(--accent-text);display:grid;place-items:center;flex:none}.gr p{margin:4px 0 0;color:var(--muted);font-size:13.5px}
.guide-sc{margin-bottom:14px}.g-steps{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.g-steps li{display:flex;gap:12px;align-items:flex-start}.g-steps p{margin:6px 0;font-size:14.5px}
.g-n{width:28px;height:28px;border-radius:50%;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:700;font-size:13px;flex:none}
.g-res{display:flex;gap:8px;align-items:flex-start;margin-top:14px;padding:10px 12px;border-radius:10px;background:var(--ok-soft);color:var(--ok-text);font-size:14px}
</style>
@endpush
