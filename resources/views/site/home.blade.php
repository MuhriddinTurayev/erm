@extends('layouts.site')
@section('title', $brand.' — '.st('ERP для стройки: контроль денег, склада и подрядчиков'))
@section('description', st('Найдите, где стройка теряет деньги: согласование платежей, склад с QR, договоры с подрядчиками, кассы в сумах и долларах и отчёт директору в Telegram. Бесплатный разбор за 20 минут.'))
@php
    $modules = [
        ['wallet', st('Кассы, счета и валюта'), st('Несколько касс и банковских счетов в сумах и долларах, обмен по курсу ЦБ, переводы, выплата зарплаты, лимиты по статьям.')],
        ['file-check', st('Заявки на оплату'), st('Прораб создаёт заявку, бухгалтер и директор согласуют по цепочке — даже одной кнопкой в Telegram. Без устных «оплати срочно».')],
        ['trending-up', st('Прогноз денег'), st('Программа сама считает, сколько денег будет через 1–12 недель, и заранее предупреждает о кассовом разрыве.')],
        ['contact', st('Контрагенты'), st('Карточки поставщиков и подрядчиков, папки, проверка по ИНН, отметка «подозрительный» или «заблокирован» с причиной.')],
        ['file-pen-line', st('Договоры'), st('Сумма, сроки, оплаты по договору, остаток долга и полная история изменений. Подготовка к подписанию через Didox.')],
        ['warehouse', st('Склад с QR-кодами'), st('Несколько складов, партии, приход, выдача на объект, перемещение и инвентаризация. Печать этикеток с QR.')],
        ['truck', st('Снабжение'), st('Заявка с объекта, согласование, заказ поставщику с видимым остатком на складе и приём товара частями.')],
        ['gavel', st('Тендеры'), st('Сравнение предложений поставщиков по цене и срокам, выбор победителя с утверждением руководителя.')],
        ['list-checks', st('Задачи'), st('Канбан, сроки, прогресс, приём работы и массовое согласование. Исполнитель получает задачу в Telegram.')],
        ['camera', st('Фотоотчёты с GPS'), st('Прораб фотографирует работу с телефона — фото сохраняется с геометкой и временем, видно руководителю и покупателю.')],
        ['layout-grid', st('Продажи квартир'), st('Шахматка, бронь, рассрочка, график платежей, кабинет покупателя и шахматка для вашего сайта.')],
        ['sparkles', st('ИИ-аналитик'), st('Gemini, ChatGPT, DeepSeek или Claude находит перерасход, читает чеки по фото и отвечает по вашим данным.')],
    ];
    // утечки денег: проблема → как закрывает программа
    $leaks = [
        ['receipt', st('Смета отдельно, расходы отдельно'), st('Закупки и работы не сверяют со сметой. О перерасходе узнают, когда объект уже почти сдан.'), st('Каждый расход сразу сравнивается со сметой и лимитом статьи')],
        ['hand-coins', st('Платежи «по звонку»'), st('Деньги уходят без заявки и подписи. Потом никто не помнит, кто разрешил и почему заплатили дважды.'), st('Каждая оплата — по заявке, с цепочкой согласования и защитой от дублей')],
        ['package-x', st('Склад в тетради'), st('Материал покупают повторно, потому что не знают остаток. Часть уезжает с объекта без следа.'), st('Остатки по складам и партиям с QR, выдача только на объект')],
        ['hourglass', st('Простои и сорванные сроки'), st('Неделя задержки — это аренда техники, зарплата бригад и неустойка заказчику.'), st('Этапы и сроки на графике, просрочки видны в отчёте каждый вечер')],
        ['handshake', st('Подрядчику заплатили вперёд'), st('Аванс выдан, акт не подписан, работа сделана наполовину. Долг подрядчика никто не считает.'), st('Договоры с остатком долга, оплата только после согласования')],
        ['file-x', st('Отчёт собирают неделю'), st('Цифры сводят вручную в Excel, ошибаются и опаздывают. Решения принимаются наугад.'), st('Отчёт директору готов сам — каждый вечер в Telegram')],
    ];
    // калькулятор: статья потерь — [ключ, иконка, название, пояснение, мин %, макс %, по умолчанию %]
    $losses = [
        ['materials', 'package-x', st('Перерасход и двойные закупки материалов'), st('покупки «про запас», потери и хищения на объекте'), 1, 5, 2.5],
        ['supply', 'truck', st('Завышенные цены поставщиков'), st('закупка без сравнения нескольких предложений'), 0.5, 4, 1.5],
        ['payments', 'hand-coins', st('Ошибки и дубли в платежах'), st('оплата без согласования, двойные и лишние платежи'), 0.5, 4, 1],
        ['delays', 'hourglass', st('Простои и срыв сроков'), st('аренда техники, зарплата бригад, неустойки'), 1, 6, 2.5],
        ['contractors', 'handshake', st('Переплата подрядчикам'), st('оплата невыполненных и непринятых работ'), 0.5, 4, 1.5],
    ];
    $faq = [
        [st('Что будет на бесплатном разборе?'), st('Онлайн-встреча на 20 минут: узнаем, как сейчас устроены деньги, склад и согласования, покажем программу на похожем примере и вместе посчитаем возможную экономию. Это ни к чему вас не обязывает.')],
        [st('Нужно ли что-то устанавливать?'), st('Нет. Программа работает в браузере на компьютере, планшете и телефоне. Прорабы могут работать через Telegram-бота.')],
        [st('На каких языках работает программа?'), st('На русском, узбекском и таджикском. Каждый сотрудник выбирает свой язык, отчёт руководителю приходит на языке компании.')],
        [st('Кто видит деньги компании?'), st('Только те, кому вы разрешили. Руководитель настраивает доступ к каждому разделу для каждой роли и к каждому объекту.')],
        [st('Безопасны ли наши данные?'), st('Данные каждой компании изолированы, соединение зашифровано, ключи и токены хранятся в зашифрованном виде. Все изменения записываются в журнал, а вы видите все активные сеансы входа.')],
        [st('Можно ли перенести данные из Excel?'), st('Да. Наши специалисты помогут перенести объекты, контрагентов, остатки склада и продажи бесплатно на тарифах «Бизнес» и «Корпорация».')],
        [st('Что будет после пробного периода?'), st('Вы выбираете тариф и оплачиваете удобным способом: перечисление, Click, Payme или наличные. Все данные сохраняются.')],
        [st('Работает ли программа с долларом?'), st('Да. Кассы и договоры можно вести в сумах (сомони) и долларах, программа пересчитывает по курсу ЦБ или по вашему курсу.')],
    ];
    $sent = session('demo_ok');
@endphp
@section('content')
<section class="hero" data-cms="Первый экран">
  <div class="wrap">
    <div class="hero-copy">
      <span class="eyebrow-pill">{!! ic('hard-hat', '', 15) !!}{{ st('Для застройщиков и подрядчиков Узбекистана и Таджикистана') }}</span>
      <h1>{!! st_html('Перестаньте терять деньги на стройке <em>незаметно</em>') !!}</h1>
      <p class="sub">{{ st('Сметы, кассы, склад, подрядчики и все объекты — в одной системе. Каждый платёж проходит согласование, каждый мешок цемента учтён, а итог дня приходит директору в Telegram.') }}</p>
      <div class="cta">
        <a class="btn btn-p btn-l" href="#demo">{!! ic('calendar-clock') !!}{{ st('Получить бесплатный разбор') }}</a>
        <a class="btn btn-l" href="#math">{!! ic('calculator') !!}{{ st('Посчитать свои потери') }}</a>
      </div>
      <p class="hero-note">{!! ic('clock', '', 15) !!}{{ st('20 минут онлайн — покажем на ваших цифрах, где утекают деньги') }}</p>
      <div class="trust"><span>{!! ic('check', '', 16) !!}{{ st('14 дней бесплатно') }}</span><span>{!! ic('languages', '', 16) !!}{{ st('русский · o‘zbekcha · тоҷикӣ') }}</span><span>{!! ic('headset', '', 16) !!}{{ st('бесплатное внедрение') }}</span></div>
    </div>
    <div class="mock reveal">
      <div class="win">
        <div class="win-bar"><i></i><i></i><i></i><span>{{ st('Мониторинг руководителя') }}</span></div>
        <div class="win-body">
          <div class="m-row">
            <div class="m-k hl"><span>{{ st('Деньги во всех кассах') }}</span><b>4 820 000 000</b><small class="ok">+640 000 000 · {{ st('за месяц') }}</small></div>
            <div class="m-k"><span>{{ st('Сегодня ушло') }}</span><b>186 000 000</b><small><span class="delta up">+12%</span></small></div>
            <div class="m-k"><span>{{ st('Ждут согласования') }}</span><b>7</b><small class="muted">{{ st('заявок') }}</small></div>
          </div>
          <div class="m-chart"><div class="bars">@foreach ([38, 52, 30, 61, 45, 70, 35, 48, 66, 40, 58, 75, 64, 82] as $i => $hh)<div class="b {{ $i === 13 ? 'today' : '' }}"><i style="height:{{ $hh }}%"></i></div>@endforeach</div></div>
          <div class="m-cats">
            @foreach ([[st('Материалы'), 54], [st('Зарплата'), 22], [st('Техника и топливо'), 15], [st('Подрядчики'), 9]] as [$n, $p])
              <div class="hbar"><div class="hb-t"><span>{{ $n }}</span><b>{{ $p }}%</b></div><div class="hb-r"><i style="width:{{ $p }}%"></i></div></div>
            @endforeach
          </div>
        </div>
      </div>
      <div class="tg-float">
        <div class="tg-h"><span class="logo-mark" style="width:26px;height:26px">{!! ic('triangle-alert', '', 14) !!}</span><b>{{ $brand }}</b><small class="muted">14:32</small></div>
        <div class="tg-b"><b>{{ st('Найден перерасход') }}</b><br>{{ st('Бетон B25: цена на 18% выше сметы') }}<br><b>+38 400 000 {{ st('сум') }}</b><div class="tg-btns"><span>{{ st('Открыть') }}</span><span>{{ st('Тендер') }}</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="strip" data-cms="Цифры">
  <div class="wrap">
    <div><b data-count="{{ count(config('oftob.modules')) }}">{{ count(config('oftob.modules')) }}</b><span>{{ st('модулей в одной системе') }}</span></div>
    <div><b data-count="3">3</b><span>{{ st('языка интерфейса') }}</span></div>
    <div><b data-count="14">14</b><span>{{ st('дней бесплатно, без карты') }}</span></div>
    <div><b>24/7</b><span>{{ st('доступ с телефона и компьютера') }}</span></div>
  </div>
</section>

<section class="sec sm" data-cms="Интеграции">
  <div class="wrap">
    <p class="logos-t">{{ st('Работает вместе с привычными сервисами') }}</p>
    <div class="logos">@foreach ([['send', 'Telegram'], ['key-round', 'Google'], ['file-pen-line', 'Didox'], ['sparkles', 'Gemini'], ['sparkles', 'ChatGPT'], ['sparkles', 'DeepSeek'], ['sparkles', 'Claude']] as [$icn, $lg])<span>{!! ic($icn, '', 18) !!}{{ $lg }}</span>@endforeach</div>
  </div>
</section>

<section class="sec" id="leaks" data-cms="Куда уходят деньги">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Где утекают деньги') }}</span><h2>{{ st('Деньги стройки уходят не туда — и это видно слишком поздно') }}</h2><p>{{ st('Шесть утечек, которые встречаются почти в каждой строительной компании. Ни одна из них не видна в Excel сразу.') }}</p></div>
    <div class="leaks">
      @foreach ($leaks as $i => [$icn, $t, $d, $s])
      <div class="leak reveal">
        <div class="leak-top"><span class="leak-ic">{!! ic($icn, '', 20) !!}</span><span class="leak-n">{{ sprintf('%02d', $i + 1) }}</span></div>
        <h3>{{ $t }}</h3><p>{{ $d }}</p>
        <div class="leak-fix">{!! ic('circle-check', '', 17) !!}<span>{{ $s }}</span></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="day" data-cms="Один день с программой">
  <div class="wrap split">
    <div class="reveal">
      <span class="eyebrow">{{ st('Как это выглядит') }}</span>
      <h2>{{ st('Один день компании, где каждый сум на виду') }}</h2>
      <ol class="day">
        @foreach ([['08:30', 'sunrise', st('Прораб'), st('Отправил в Telegram заявку на арматуру и фото объекта с геометкой')],
                   ['10:15', 'calculator', st('Бухгалтер'), st('Сверил заявку с лимитом статьи и договором поставщика')],
                   ['11:00', 'briefcase', st('Директор'), st('Утвердил оплату одной кнопкой, не отрываясь от встречи')],
                   ['14:40', 'warehouse', st('Склад'), st('Металл принят по QR, остаток обновился сам')],
                   ['20:00', 'sunset', st('Директор'), st('Получил итог дня: деньги, расходы по объектам и вывод ИИ')]] as [$tm, $icn, $who, $what])
        <li><span class="d-tm">{{ $tm }}</span><span class="d-ic">{!! ic($icn, '', 17) !!}</span><div><b>{{ $who }}</b><p>{{ $what }}</p></div></li>
        @endforeach
      </ol>
    </div>
    <div class="reveal">
      <div class="gain">
        <span class="gain-ic">{!! ic('target', '', 22) !!}</span>
        <h3>{{ st('Что меняется уже в первый месяц') }}</h3>
        <ul class="checks">
          @foreach ([st('Все объекты и кассы — на одном экране, в реальном времени'), st('Ни одной оплаты без подписи ответственного'), st('Подрядчик получает деньги за принятую работу, а не за обещание'), st('Отчёт по деньгам — за одну кнопку, а не за неделю'), st('Решения по цифрам, а не по ощущениям')] as $c)
          <li>{!! ic('check', '', 16) !!}<span>{{ $c }}</span></li>
          @endforeach
        </ul>
        <a class="btn btn-p btn-block" href="#math">{!! ic('calculator') !!}{{ st('Сколько это в деньгах?') }}</a>
      </div>
    </div>
  </div>
</section>

<section class="sec" id="math" data-cms="Калькулятор потерь">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Посчитайте сами') }}</span><h2>{{ st('Сколько теряет один ваш объект') }}</h2><p>{{ st('Укажите бюджет объекта и поправьте проценты под свою ситуацию. По умолчанию стоят осторожные значения из практики строительных компаний.') }}</p></div>
    <div class="calc reveal" id="calc" data-plan="{{ $calc['plan'] }}" data-rate="{{ $calc['rate'] }}" data-uzs="{{ $calc['uzs'] }}" data-base="{{ $calc['base'] }}">
      <div class="calc-in">
        <div class="calc-budget">
          <label for="cBudget">{{ st('Бюджет объекта') }}</label>
          <div class="cb-row">
            <input id="cBudget" inputmode="numeric" autocomplete="off" value="{{ $calc['base'] === 'tjs' ? '12 000 000' : '12 000 000 000' }}" aria-label="{{ st('Бюджет объекта') }}">
            <div class="seg" id="cCur" role="group"><button type="button" class="on" data-c="{{ $calc['base'] }}">{{ $calc['base'] === 'tjs' ? st('смн') : st('сум') }}</button><button type="button" data-c="usd">$</button></div>
          </div>
          <div class="chips" id="cPresets"></div>
        </div>
        <div class="calc-rows">
          @foreach ($losses as [$k, $icn, $t, $h, $min, $max, $def])
          <div class="lrow" data-k="{{ $k }}">
            <span class="l-ic">{!! ic($icn, '', 18) !!}</span>
            <div class="l-main">
              <div class="l-top"><b>{{ $t }}</b><span class="l-pct"><span data-pct>{{ str_replace('.', ',', $def) }}</span>%</span></div>
              <small>{{ $h }}</small>
              <input type="range" min="{{ $min }}" max="{{ $max }}" step="0.5" value="{{ $def }}" aria-label="{{ $t }}">
            </div>
            <b class="l-sum" data-sum>—</b>
          </div>
          @endforeach
        </div>
      </div>
      <aside class="calc-out">
        <span class="co-l">{{ st('Объект теряет') }}</span>
        <div class="co-big" id="cTotal">—</div>
        <div class="co-pct"><span id="cPct">9%</span> {{ st('бюджета') }}</div>
        <div class="co-bar" id="cBar"></div>
        <div class="co-save">
          {!! ic('piggy-bank', '', 20) !!}
          <div><span>{{ st('Если остановить хотя бы половину') }}</span><b id="cSave">—</b></div>
        </div>
        <p class="co-roi" id="cRoi" hidden>{{ st('Это в') }} <b id="cRoiX"></b> {{ st('раз больше годовой стоимости программы') }}</p>
        <a class="btn btn-p btn-block btn-l" href="#demo" id="cGo">{!! ic('calendar-clock') !!}{{ st('Разобрать мои цифры бесплатно') }}</a>
        <small class="co-note">{{ st('Расчёт ориентировочный. На разборе посчитаем по вашим реальным данным.') }}</small>
      </aside>
    </div>
  </div>
</section>

<section class="sec alt" id="demo" data-cms="Бесплатный разбор">
  <div class="wrap">
    <div class="demo reveal">
      <div class="demo-pitch">
        <span class="eyebrow-pill">{!! ic('clock', '', 15) !!}{{ st('Бесплатно · 20 минут · онлайн') }}</span>
        <h2>{{ st('Покажем, где утекают деньги именно у вас') }}</h2>
        <ul class="checks">
          @foreach ([st('Разберём ваши объекты, кассы и согласования'), st('Покажем программу на примере, похожем на вашу компанию'), st('Посчитаем, сколько можно сэкономить уже в этом году'), st('Честно скажем, подходит ли вам программа — без навязывания')] as $c)
          <li>{!! ic('check', '', 16) !!}<span>{{ $c }}</span></li>
          @endforeach
        </ul>
        @if ($support['phone'] || $support['telegram'])
        <div class="demo-contact">
          <span>{{ st('Удобнее сразу поговорить?') }}</span>
          @if ($support['phone'])<a href="tel:{{ preg_replace('/[^+\d]/', '', $support['phone']) }}">{!! ic('phone', '', 15) !!}{{ $support['phone'] }}</a>@endif
          @if ($support['telegram'])<a href="https://t.me/{{ ltrim($support['telegram'], '@') }}" target="_blank" rel="noopener">{!! ic('send', '', 15) !!}Telegram</a>@endif
        </div>
        @endif
      </div>
      <div class="demo-form">
        <div class="demo-ok" id="demoOk" @if (! $sent) hidden @endif>
          <span class="ok-ic">{!! ic('circle-check', '', 30) !!}</span>
          <h3>{{ st('Спасибо! Заявка принята') }}</h3>
          <p>{{ st('Мы позвоним в течение рабочего дня и договоримся об удобном времени.') }}</p>
          <button type="button" class="btn btn-ghost mt" id="demoAgain">{!! ic('rotate-ccw', '', 16) !!}{{ st('Оставить ещё одну заявку') }}</button>
        </div>
        <form method="post" action="/demo" id="demoForm" novalidate @if ($sent) hidden @endif>
          @csrf
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <input type="hidden" name="budget" id="dBudget" value="{{ old('budget') }}">
          <input type="hidden" name="utm" value="{{ request('utm_source') }}">
          <h3>{{ st('Запись на бесплатный разбор') }}</h3>
          <label class="f">{{ st('Ваше имя') }}<input name="name" required maxlength="80" value="{{ old('name') }}" autocomplete="name" placeholder="{{ st('Например: Алишер') }}"><em data-err="name">{{ $errors->first('name') }}</em></label>
          <label class="f">{{ st('Телефон') }}
            <span class="tel">
              <span class="sel"><select name="code" aria-label="{{ st('Код страны') }}">
                @foreach ([['998', 'UZ', '90 123 45 67'], ['992', 'TJ', '90 123 4567'], ['7', 'RU', '912 345 67 89'], ['996', 'KG', '700 123 456']] as [$code, $flag, $ph])
                <option value="{{ $code }}" data-ph="{{ $ph }}" @selected(old('code', app()->getLocale() === 'tg' ? '992' : '998') === $code)>{{ $flag }} +{{ $code }}</option>
                @endforeach
              </select></span>
              <input name="phone" required inputmode="tel" autocomplete="tel-national" value="{{ old('phone') }}" placeholder="90 123 45 67">
            </span>
            <em data-err="phone">{{ $errors->first('phone') }}</em>
          </label>
          <label class="f"><span>{{ st('Компания') }} <small>{{ st('(необязательно)') }}</small></span><input name="company" maxlength="120" value="{{ old('company') }}" autocomplete="organization"></label>
          <div class="f2">
            <label class="f">{{ st('Что строите') }}<span class="sel"><select name="kind"><option value="">{{ st('Выберите') }}</option>@foreach (\App\Models\SiteLead::KINDS as $k => $n)<option value="{{ $k }}" @selected(old('kind') === $k)>{{ st($n) }}</option>@endforeach</select></span></label>
            <label class="f">{{ st('Сколько объектов сейчас') }}<span class="sel"><select name="objects"><option value="">{{ st('Выберите') }}</option>@foreach (\App\Models\SiteLead::OBJECTS as $k => $n)<option value="{{ $k }}" @selected(old('objects') === $k)>{{ st($n) }}</option>@endforeach</select></span></label>
          </div>
          <label class="f">{{ st('Ваша должность') }}<span class="sel"><select name="role"><option value="">{{ st('Выберите') }}</option>@foreach (\App\Models\SiteLead::ROLES as $k => $n)<option value="{{ $k }}" @selected(old('role') === $k)>{{ st($n) }}</option>@endforeach</select></span></label>
          <button class="btn btn-p btn-l btn-block" type="submit">{!! ic('calendar-clock') !!}<span>{{ st('Записаться на разбор') }}</span></button>
          <p class="demo-priv">{!! ic('lock', '', 14) !!}<span>{{ st('Номер нужен только чтобы связаться с вами. Мы не передаём данные третьим лицам.') }} <a href="/privacy">{{ st('Политика конфиденциальности') }}</a></span></p>
        </form>
      </div>
    </div>
  </div>
</section>

<section class="sec" id="modules" data-cms="Модули">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Модули') }}</span><h2>{{ st('Всё для стройки — в одной платформе') }}</h2><p>{{ st('От заявки прораба до отчёта директору. Включайте нужные модули для каждой роли.') }}</p></div>
    <div class="feats">
      @foreach ($modules as [$icn, $t, $d])
        <div class="feat reveal"><div class="f-ic">{!! ic($icn, '', 20) !!}</div><h3>{{ $t }}</h3><p>{{ $d }}</p></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" data-cms="Как работает согласование">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Контроль платежей') }}</span><h2>{{ st('Ни одна оплата без согласования') }}</h2><p>{{ st('Вы сами задаёте, кто и в каком порядке утверждает платежи. Всё фиксируется: кто, когда и с каким комментарием.') }}</p></div>
    <div class="flow reveal">
      @foreach ([['hard-hat', st('Прораб'), st('создаёт заявку с телефона или в Telegram')], ['calculator', st('Бухгалтер'), st('проверяет лимит статьи и договор')], ['briefcase', st('Директор'), st('утверждает одной кнопкой в Telegram')], ['wallet', st('Кассир'), st('оплачивает из нужной кассы — расход сразу в отчётах')]] as $i => [$icn, $t, $d])
        <div class="step"><span class="n">{{ $i + 1 }}</span><span class="s-ic">{!! ic($icn, '', 22) !!}</span><b>{{ $t }}</b><p>{{ $d }}</p></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec" id="report" data-cms="Отчёт директору">
  <div class="wrap split">
    <div class="reveal">
      <span class="eyebrow">{{ st('Ежедневный отчёт') }}</span>
      <h2>{{ st('Итог дня — в Telegram директора') }}</h2>
      <p class="lead">{{ st('Не нужно никому звонить и открывать программу. В выбранное время руководитель получает короткую сводку по всей компании.') }}</p>
      <ul class="checks">
        @foreach ([st('Деньги во всех кассах и сколько потрачено сегодня — с ростом или снижением к вчера'), st('На что ушли деньги: материалы, зарплата, техника, подрядчики — с долей в процентах'), st('Крупные платежи дня и кому они ушли'), st('Каждый объект: расход, готовность, просроченные этапы'), st('Вывод ИИ-аналитика: на что обратить внимание завтра')] as $c)
        <li>{!! ic('check', '', 16) !!}<span>{{ $c }}</span></li>
        @endforeach
      </ul>
    </div>
    <div class="reveal">
      <div class="phone"><div class="scr">
        <div class="ph-h"><span class="logo-mark">{!! ic('send', '', 16) !!}</span><div><b>{{ $brand }}</b><small>bot</small></div></div>
        <div class="msg"><b>{{ st('Отчёт за день') }} · {{ now()->format('d.m.Y') }}</b><br>{{ st('ООО «Строй Инвест»') }}<br><br>
          <b>{{ st('Деньги во всех кассах') }}: 4 820 000 000 {{ st('сум') }}</b><br>
          {{ st('Потрачено сегодня') }}: <b>186 000 000</b> (+12%)<br>
          {{ st('Поступило сегодня') }}: 320 000 000<br><br>
          <b>{{ st('На что ушли деньги') }}:</b><br>• {{ st('Материалы') }} — 100 000 000 (54%)<br>• {{ st('Зарплата') }} — 41 000 000 (22%)<br>• {{ st('Техника и топливо') }} — 28 000 000 (15%)<br><br>
          <b>{{ st('Вывод ИИ') }}:</b><br>• {{ st('Расход на бетон на 18% выше плана — проверьте поставщика') }}
          <div class="t">20:00</div></div>
      </div></div>
    </div>
  </div>
</section>

<section class="sec alt" data-cms="Telegram-бот и телефон">
  <div class="wrap split rev">
    <div class="reveal">
      <div class="bot-demo">
        <div class="bm me">{{ st('Бетон 25 млн БетонСтрой') }}</div>
        <div class="bm">{!! ic('circle-check', '', 15) !!} {{ st('Расход записан: Материалы · 25 000 000 · БетонСтрой · ЖК «Навруз»') }}</div>
        <div class="bm me ph">{!! ic('camera', '', 18) !!} {{ st('фото чека') }}</div>
        <div class="bm">{!! ic('sparkles', '', 15) !!} {{ st('ИИ прочитал чек: 4 500 000 сум, «Цемент Савдо», 12.10. Записать?') }}</div>
      </div>
    </div>
    <div class="reveal">
      <span class="eyebrow">{{ st('Работа с объекта') }}</span>
      <h2>{{ st('Прорабу не нужен компьютер') }}</h2>
      <p class="lead">{{ st('Расходы, фото чеков, журнал работ и фотоотчёты — прямо из Telegram или браузера телефона. Данные сразу у бухгалтера и директора.') }}</p>
      <ul class="checks">
        @foreach ([st('Расход одной строкой: «Цемент 4,5 млн СементСавдо»'), st('Фото чека — ИИ сам прочитает сумму, продавца и дату'), st('Фотоотчёт с геометкой и временем съёмки'), st('Согласование платежей одной кнопкой')] as $c)
        <li>{!! ic('check', '', 16) !!}<span>{{ $c }}</span></li>
        @endforeach
      </ul>
    </div>
  </div>
</section>

<section class="sec" id="security" data-cms="Безопасность">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Безопасность') }}</span><h2>{{ st('Ваши данные под надёжной защитой') }}</h2><p>{{ st('Финансы компании — самая чувствительная информация. Мы защищаем её как банк.') }}</p></div>
    <div class="sec-grid">
      @foreach ([['lock', st('Шифрование'), st('HTTPS/TLS для всего трафика, пароли — только в виде хеша bcrypt, ключи и токены — AES-256.')],
                 ['monitor-smartphone', st('Активные сеансы'), st('Видно, с каких устройств выполнен вход. Любой сеанс можно завершить одной кнопкой.')],
                 ['bell', st('Оповещение о входе'), st('Вход с нового устройства — мгновенное сообщение в Telegram и на почту.')],
                 ['users', st('Роли и права'), st('Каждый сотрудник видит только свои объекты и разделы. Права настраивает руководитель.')],
                 ['history', st('Журнал изменений'), st('Кто, когда и что изменил — по каждой операции, договору и заявке.')],
                 ['key-round', st('Вход через Google и Telegram'), st('Без лишних паролей: подтверждённый вход через аккаунт Google или Telegram.')]] as [$icn, $t, $d])
        <div class="sg reveal"><span class="sg-ic">{!! ic($icn, '', 20) !!}</span><div><b>{{ $t }}</b><p>{{ $d }}</p></div></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="ai" data-cms="Искусственный интеллект">
  <div class="wrap split rev">
    <div class="chat reveal">
      <div class="u">{{ st('Где у нас перерасход в этом месяце?') }}</div>
      <div class="a"><span class="a-ic">{!! ic('sparkles', '', 15) !!}</span><div>{{ st('По объекту ЖК «Навруз» материалы превышают смету на 18%: бетон B25 закуплен по 1,15 млн за м³ при плане 0,98 млн. Рекомендую запросить 2–3 альтернативных предложения через тендер.') }}</div></div>
      <div class="u">{{ st('Хватит ли денег до конца каркаса?') }}</div>
      <div class="a"><span class="a-ic">{!! ic('sparkles', '', 15) !!}</span><div>{{ st('По прогнозу через 3 недели не хватит около 480 млн. С учётом платежей покупателей по рассрочке разрыв закроется в середине следующего месяца.') }}</div></div>
    </div>
    <div class="reveal">
      <span class="eyebrow">{{ st('Искусственный интеллект') }}</span>
      <h2>{{ st('ИИ-консультант, который знает ваш проект') }}</h2>
      <p class="lead">{{ st('Он видит смету, расходы, график, склад и договоры — и отвечает по вашим данным, а не общими словами.') }}</p>
      <div class="ai-chips"><span>Google Gemini</span><span>ChatGPT</span><span>DeepSeek</span><span>Claude</span></div>
      <ul class="checks">
        @foreach ([st('Находит перерасход, просрочки и ошибки'), st('Читает чеки и накладные по фото'), st('Отвечает на русском, узбекском и таджикском')] as $c)
        <li>{!! ic('check', '', 16) !!}<span>{{ $c }}</span></li>
        @endforeach
      </ul>
    </div>
  </div>
</section>

<section class="sec" data-cms="Сравнение">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Почему выбирают нас') }}</span><h2>{{ st('Больше, чем учёт в Excel или обычная CRM') }}</h2></div>
    <div class="cmp reveal"><table>
      <thead><tr><th></th><th>Excel</th><th>{{ st('Обычная CRM') }}</th><th class="us">{{ $brand }}</th></tr></thead>
      <tbody>
      @foreach ([[st('Кассы в сумах и долларах, курс ЦБ'), 1, 0], [st('Согласование платежей в Telegram'), 0, 0], [st('Склад с партиями и QR-этикетками'), 0, 0], [st('Снабжение и тендеры'), 0, 0], [st('Прогноз кассового разрыва'), 0, 0], [st('Продажи квартир и рассрочка'), 1, 1], [st('Фотоотчёты с GPS'), 0, 0], [st('ИИ-аналитик и чтение чеков'), 0, 0], [st('Узбекский и таджикский языки'), 1, 0], [st('Отчёт директору каждый вечер'), 0, 0]] as [$t, $x, $c])
        <tr><td>{{ $t }}</td><td>{!! $x ? ic('minus', 'mid') : ic('x', 'no') !!}</td><td>{!! $c ? ic('check', 'mid') : ic('x', 'no') !!}</td><td class="us">{!! ic('circle-check', 'yes') !!}</td></tr>
      @endforeach
      </tbody></table></div>
  </div>
</section>

<section class="sec alt" id="roles" data-cms="Для кого">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Для кого') }}</span><h2>{{ st('Каждый в компании получает своё') }}</h2><p>{{ st('Один вход — и каждый видит только то, что нужно для его работы.') }}</p></div>
    <div class="roles">
      @foreach ([['briefcase', st('Владелец и директор'), st('Видит все деньги и объекты, утверждает платежи в Telegram, получает отчёт каждый вечер.')],
                 ['calculator', st('Бухгалтер и финансист'), st('Кассы и счета, лимиты статей, договоры и долги, прогноз кассового разрыва.')],
                 ['wallet', st('Кассир'), st('Оплачивает только утверждённые заявки, переводы и обмен валюты по правилам.')],
                 ['hard-hat', st('Прораб'), st('Заявки, расходы и фотоотчёты с телефона — только по своему объекту.')],
                 ['truck', st('Снабженец и кладовщик'), st('Заявки на материалы, тендеры, приход и выдача по QR, остатки по складам.')],
                 ['layout-grid', st('Отдел продаж'), st('Шахматка, бронь, рассрочка, кабинет покупателя и заявки с сайта и соцсетей.')]] as [$icn, $t, $d])
        <div class="role reveal"><span class="r-ic">{!! ic($icn, '', 18) !!}</span><div><b>{{ $t }}</b><span>{{ $d }}</span></div></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec" id="pricing" data-cms="Тарифы">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Тарифы') }}</span><h2>{{ st('Простые и понятные цены') }}</h2><p>{{ st('14 дней бесплатно на любом тарифе. Без скрытых платежей.') }}</p></div>
    <div style="text-align:center"><div class="period" id="period"><button class="on" data-p="m">{{ st('Помесячно') }}</button><button data-p="y">{{ st('За год') }} <span class="save">−17%</span></button></div></div>
    <div class="plans">
      @foreach ($plans as $p)
      <div class="plan {{ $p->is_featured ? 'feat' : '' }} reveal">
        @if ($p->is_featured)<span class="p-tag">{{ st('Популярный') }}</span>@endif
        <h3 style="font-size:17px">{{ $p->title() }}</h3>
        <div class="muted" style="font-size:14px;margin-top:-6px">{{ $p->about() }}</div>
        <div class="p-price" data-m="{{ money_s($p->price_month, $p->currency) }}" data-y="{{ $p->price_year > 0 ? money_s($p->price_year / 12, $p->currency) : money_s($p->price_month, $p->currency) }}">{{ money_s($p->price_month, $p->currency) }} <small class="muted">/ {{ st('мес') }}</small></div>
        @if ($regOpen && ! $appUrl)
          <a class="btn btn-block {{ $p->is_featured ? 'btn-p' : '' }}" href="/register?plan={{ $p->slug }}">{{ st('Попробовать 14 дней') }}</a>
        @endif
        <ul>
          <li>{!! ic('check') !!}<span>{{ st('Объектов') }}: <b>{{ $p->max_objects ? $p->max_objects : st('без ограничений') }}</b></span></li>
          <li>{!! ic('check') !!}<span>{{ st('Пользователей') }}: <b>{{ $p->max_users ? $p->max_users : st('без ограничений') }}</b></span></li>
          @if ($p->ai_enabled)<li>{!! ic('check') !!}<span>{{ st('ИИ') }}: <b>{{ $p->ai_requests ? __(':n запросов/мес', ['n' => $p->ai_requests]) : st('без лимита') }}</b></span></li>@endif
          @foreach ($p->featureList() as $f)<li>{!! ic('check') !!}<span>{{ $f }}</span></li>@endforeach
        </ul>
      </div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="faq" data-cms="Вопросы и ответы">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Вопросы') }}</span><h2>{{ st('Частые вопросы') }}</h2></div>
    <div class="faq">
      @foreach ($faq as [$q, $a])<details class="reveal"><summary>{{ $q }}{!! ic('chevron-down', 'chev') !!}</summary><p>{{ $a }}</p></details>@endforeach
    </div>
  </div>
</section>

<section class="sec" data-cms="Призыв в конце">
  <div class="wrap">
    <div class="cta-band reveal">
      <div><h2>{{ st('Начните с бесплатного разбора') }}</h2><p>{{ st('20 минут — и вы будете знать, где ваша стройка теряет деньги и сколько можно вернуть.') }}</p></div>
      <div class="cta">
        <a class="btn btn-p btn-l" href="#demo">{!! ic('calendar-clock') !!}{{ st('Записаться на разбор') }}</a>
        @if ($appUrl)<a class="btn btn-l" href="{{ $appUrl }}">{{ st('Открыть программу') }}{!! ic('arrow-right') !!}</a>@elseif ($regOpen)<a class="btn btn-l" href="/register">{{ st('Попробовать 14 дней') }}{!! ic('arrow-right') !!}</a>@endif
      </div>
    </div>
  </div>
</section>
@endsection
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@graph' => [
  ['@type' => 'Organization', 'name' => $brand, 'url' => url('/'), 'logo' => asset('icons/icon-512.png'), 'contactPoint' => array_filter(['@type' => 'ContactPoint', 'telephone' => $support['phone'], 'email' => $support['email'], 'contactType' => 'customer support', 'availableLanguage' => ['Russian', 'Uzbek', 'Tajik']])],
  ['@type' => 'SoftwareApplication', 'name' => $brand, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Android, iOS', 'description' => st('ERP для стройки: контроль денег, склада и подрядчиков'),
   'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'UZS', 'lowPrice' => (float) ($plans->min('price_month') ?? 0), 'offerCount' => $plans->count()]],
  ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush
@push('scripts')
<script>
document.getElementById('period')?.addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  document.querySelectorAll('#period button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.p-price[data-m]').forEach(p => p.firstChild.nodeValue = p.dataset[b.dataset.p] + ' ');
});

/* ===== Калькулятор потерь ===== */
(() => {
  const box = document.getElementById('calc'); if (!box) return;
  const T = {!! json_encode(['uzs' => st('сум'), 'tjs' => st('смн'), 'mln' => st('млн'), 'mlrd' => st('млрд')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
  // BASE — валюта посетителя (сум или сомони), RATE — её курс к доллару; цена тарифа PLAN — в сумах (курс UZS)
  const PLAN = +box.dataset.plan || 0, BASE = box.dataset.base || 'uzs', RATE = +box.dataset.rate || 12800, UZS = +box.dataset.uzs || 12800;
  const inp = document.getElementById('cBudget'), rows = [...box.querySelectorAll('.lrow')];
  let cur = BASE;
  const PRE = { uzs: [5e9, 12e9, 50e9], tjs: [5e6, 12e6, 50e6], usd: [5e5, 1e6, 5e6] };
  const num = s => +String(s).replace(/[^\d]/g, '') || 0;
  const grp = n => Math.round(n).toLocaleString('ru-RU').replace(/ /g, ' ');
  const short = n => { const a = Math.abs(n), u = cur === 'usd';
    if (u) return '$' + grp(n);
    const w = T[cur];
    return grp(n) + ' ' + w; };   // полностью, без «млн/млрд»
  const chips = document.getElementById('cPresets');
  const drawChips = () => { chips.innerHTML = PRE[cur].map(v => `<button type="button" data-v="${v}">${short(v)}</button>`).join(''); };
  const calc = () => {
    const B = num(inp.value); let tot = 0, pct = 0; const parts = [];
    rows.forEach(r => { const rng = r.querySelector('input'), p = +rng.value, s = B * p / 100; tot += s; pct += p; parts.push([r.dataset.k, p]);
      r.querySelector('[data-pct]').textContent = String(p).replace('.', ',');
      r.querySelector('[data-sum]').textContent = B ? short(s) : '—';
      rng.style.setProperty('--p', ((p - rng.min) / (rng.max - rng.min) * 100) + '%'); });
    document.getElementById('cTotal').textContent = B ? short(tot) : '—';
    document.getElementById('cPct').textContent = String(Math.round(pct * 10) / 10).replace('.', ',') + '%';
    document.getElementById('cSave').textContent = B ? short(tot / 2) : '—';
    document.getElementById('cBar').innerHTML = parts.map(([k, p]) => `<i class="b-${k}" style="flex:${p}"></i>`).join('');
    // во сколько раз половина потерь больше годовой цены самого доступного тарифа
    // сравниваем в долларах: половина потерь против годовой цены тарифа
    const half = tot / 2, saveUsd = cur === 'usd' ? half : half / RATE, yearUsd = PLAN * 12 / UZS, x = yearUsd ? Math.floor(saveUsd / yearUsd) : 0;
    const roi = document.getElementById('cRoi'); roi.hidden = !(x >= 2); document.getElementById('cRoiX').textContent = x.toLocaleString('ru-RU').replace(/ /g, ' ');
    document.getElementById('dBudget').value = B ? `${short(B)} → ${short(tot)} (${String(Math.round(pct * 10) / 10).replace('.', ',')}%)` : '';
  };
  inp.addEventListener('input', () => { const p = inp.selectionStart, l = inp.value.length; inp.value = num(inp.value) ? grp(num(inp.value)) : ''; const d = inp.value.length - l; try { inp.setSelectionRange(p + d, p + d); } catch (e) { } calc(); });
  chips.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; inp.value = grp(+b.dataset.v); calc(); });
  document.getElementById('cCur').addEventListener('click', e => { const b = e.target.closest('button'); if (!b || b.dataset.c === cur) return;
    const B = num(inp.value); cur = b.dataset.c; document.querySelectorAll('#cCur button').forEach(x => x.classList.toggle('on', x === b));
    const step = BASE === 'tjs' ? 1e3 : 1e6;
    inp.value = grp(cur === 'usd' ? Math.round(B / RATE / 1000) * 1000 : Math.round(B * RATE / step) * step); drawChips(); calc(); });
  rows.forEach(r => r.querySelector('input').addEventListener('input', calc));
  drawChips(); calc();
})();

/* ===== Запись на разбор: маска телефона, отправка без перезагрузки ===== */
(() => {
  const f = document.getElementById('demoForm'); if (!f) return;
  const code = f.querySelector('[name=code]'), ph = f.querySelector('[name=phone]'), ok = document.getElementById('demoOk');
  // маска номера по стране: «90 123 45 67»; лишние цифры не вводятся, номер с кодом страны (+998…) — код убирается
  const groups = () => code.selectedOptions[0].dataset.ph.split(' ').map(g => g.length);
  const mask = () => {
    const G = groups(), max = G.reduce((a, b) => a + b, 0), c = code.value;
    let d = ph.value.replace(/\D/g, '');
    // код страны убираем, только если номер вставили с «+» или целиком «код + номер» (номер 99 890… сам начинается с 998)
    if (d.startsWith(c) && (ph.value.trim().startsWith('+') || d.length === c.length + max)) d = d.slice(c.length);
    d = d.slice(0, max);
    let out = '', i = 0; for (const g of G) { if (i >= d.length) break; out += (out ? ' ' : '') + d.slice(i, i + g); i += g; }
    ph.value = out;
  };
  const syncPh = () => { ph.placeholder = code.selectedOptions[0].dataset.ph; mask(); };
  code.addEventListener('change', syncPh); syncPh();
  ph.addEventListener('input', mask);
  // ещё одна заявка: снова показать пустую форму
  document.getElementById('demoAgain')?.addEventListener('click', () => {
    f.reset(); syncPh(); f.querySelectorAll('[data-err]').forEach(x => x.textContent = '');
    f.querySelector('[type=submit]').disabled = false; ok.hidden = true; f.hidden = false; f.querySelector('[name=name]').focus();
  });
  f.addEventListener('submit', async e => {
    e.preventDefault();
    f.querySelectorAll('[data-err]').forEach(x => x.textContent = '');
    const btn = f.querySelector('[type=submit]'); btn.disabled = true;
    try {
      const r = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      if (r.status === 422) { const j = await r.json(); Object.entries(j.errors || {}).forEach(([k, v]) => { const el = f.querySelector(`[data-err="${k}"]`); if (el) el.textContent = v[0]; }); btn.disabled = false; return; }
      if (!r.ok) throw new Error(r.status);
      f.hidden = true; ok.hidden = false; btn.disabled = false;
      ok.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (err) { btn.disabled = false; f.submit(); }   // нет связи по fetch — обычная отправка формы
  });
})();
</script>
@endpush
