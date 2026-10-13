@extends('layouts.site')
@section('title', $brand.' — '.st('ERP и CRM для строительных компаний'))
@section('description', st('Кассы и валюта, заявки на оплату с согласованием, склад с QR, снабжение, тендеры, договоры, задачи, продажи квартир и ежедневный отчёт директору в Telegram. На русском, узбекском и таджикском.'))
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
    $faq = [
        [st('Нужно ли что-то устанавливать?'), st('Нет. Программа работает в браузере на компьютере, планшете и телефоне. Прорабы могут работать через Telegram-бота.')],
        [st('На каких языках работает программа?'), st('На русском, узбекском и таджикском. Каждый сотрудник выбирает свой язык, отчёт руководителю приходит на языке компании.')],
        [st('Кто видит деньги компании?'), st('Только те, кому вы разрешили. Руководитель настраивает доступ к каждому разделу для каждой роли и к каждому объекту.')],
        [st('Безопасны ли наши данные?'), st('Данные каждой компании изолированы, соединение зашифровано, ключи и токены хранятся в зашифрованном виде. Все изменения записываются в журнал, а вы видите все активные сеансы входа.')],
        [st('Можно ли перенести данные из Excel?'), st('Да. Наши специалисты помогут перенести объекты, контрагентов, остатки склада и продажи бесплатно на тарифах «Бизнес» и «Корпорация».')],
        [st('Что будет после пробного периода?'), st('Вы выбираете тариф и оплачиваете удобным способом: перечисление, Click, Payme или наличные. Все данные сохраняются.')],
        [st('Работает ли программа с долларом?'), st('Да. Кассы и договоры можно вести в сумах (сомони) и долларах, программа пересчитывает по курсу ЦБ или по вашему курсу.')],
        [st('Можно ли вести несколько объектов и компаний?'), st('Да. Количество объектов зависит от тарифа, на тарифе «Корпорация» — без ограничений.')],
    ];
@endphp
@section('content')
<section class="hero" data-cms="Первый экран">
  <div class="wrap">
    <div class="hero-copy">
      <span class="eyebrow-pill">{!! ic('badge-check', '', 15) !!}{{ st('ERP-платформа №1 для застройщиков Узбекистана и Таджикистана') }}</span>
      <h1>{!! st('Вся стройка и все деньги компании — <em>в одной системе</em>') !!}</h1>
      <p class="sub">{{ st('Кассы и валюта, согласование платежей, склад, снабжение, тендеры, договоры, задачи и продажи квартир. Прорабы работают с телефона и через Telegram, а итог дня приходит директору каждый вечер.') }}</p>
      <div class="cta">
        @if ($appUrl)
          <a class="btn btn-p btn-l" href="{{ $appUrl }}">{{ st('Открыть программу') }}{!! ic('arrow-right') !!}</a>
        @elseif ($regOpen)
          <a class="btn btn-p btn-l" href="/register">{{ st('Начать бесплатно — 14 дней') }}{!! ic('arrow-right') !!}</a>
        @endif
        <a class="btn btn-l" href="{{ $support['telegram'] ? 'https://t.me/'.ltrim($support['telegram'], '@') : '#modules' }}" @if ($support['telegram']) target="_blank" rel="noopener" @endif>{!! ic('circle-play') !!}{{ st('Заказать демонстрацию') }}</a>
      </div>
      <div class="trust"><span>{!! ic('check', '', 16) !!}{{ st('без карты и договора') }}</span><span>{!! ic('languages', '', 16) !!}{{ st('русский · o‘zbekcha · тоҷикӣ') }}</span><span>{!! ic('headset', '', 16) !!}{{ st('бесплатное внедрение') }}</span></div>
    </div>
    <div class="mock reveal">
      <div class="win">
        <div class="win-bar"><i></i><i></i><i></i><span>{{ st('Мониторинг руководителя') }}</span></div>
        <div class="win-body">
          <div class="m-row">
            <div class="m-k hl"><span>{{ st('Деньги во всех кассах') }}</span><b>4,82 {{ st('млрд') }}</b><small class="ok">+640 {{ st('млн') }} · {{ st('за месяц') }}</small></div>
            <div class="m-k"><span>{{ st('Сегодня ушло') }}</span><b>186 {{ st('млн') }}</b><small><span class="delta up">+12%</span></small></div>
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
        <div class="tg-h"><span class="logo-mark" style="width:26px;height:26px">{!! ic('send', '', 14) !!}</span><b>{{ $brand }}</b><small class="muted">14:32</small></div>
        <div class="tg-b"><b>{{ st('Нужно согласовать оплату') }}</b><br>{{ st('Заявка №248 · Арматура А500, 12 т') }}<br><b>84 600 000 {{ st('сум') }}</b><div class="tg-btns"><span>{{ st('Согласовать') }}</span><span>{{ st('Отклонить') }}</span></div></div>
      </div>
    </div>
  </div>
</section>

<section class="strip" data-cms="Цифры доверия">
  <div class="wrap">
    <div><b data-count="{{ (float) preg_replace('/[^\d.]/', '', st('150+')) }}" data-suf="+">{{ st('150+') }}</b><span>{{ st('строительных компаний') }}</span></div>
    <div><b data-count="{{ (float) preg_replace('/[^\d.]/', '', st('1200+')) }}" data-suf="+">{{ st('1200+') }}</b><span>{{ st('объектов под управлением') }}</span></div>
    <div><b>{{ st('99,9%') }}</b><span>{{ st('доступность сервиса') }}</span></div>
    <div><b>{{ st('24/7') }}</b><span>{{ st('поддержка на вашем языке') }}</span></div>
  </div>
</section>

<section class="sec sm" data-cms="Логотипы клиентов">
  <div class="wrap">
    <p class="logos-t">{{ st('Нам доверяют застройщики и подрядчики') }}</p>
    <div class="logos">@foreach (array_filter(array_map('trim', explode(',', st('Navruz Qurilish, Samarqand Invest, Dushanbe City, Toshkent Build, Pamir Stroy, Fergana Group')))) as $lg)<span>{!! ic('building-2', '', 18) !!}{{ $lg }}</span>@endforeach</div>
  </div>
</section>

<section class="sec" data-cms="Проблемы">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Знакомо?') }}</span><h2>{{ st('Деньги уходят, а куда — непонятно') }}</h2><p>{{ st('Типичные проблемы строительной компании, которые решает программа') }}</p></div>
    <div class="pains">
      @foreach ([[st('Сколько у нас сейчас денег?'), st('Бухгалтер считает полдня, цифры в кассе и в банке расходятся.'), st('Все кассы и счета в сумах и долларах — на одном экране.')],
                 [st('Кто разрешил этот платёж?'), st('Деньги уходят по звонку, потом никто не помнит, кто согласовал.'), st('Каждая оплата — через заявку и цепочку согласования с историей.')],
                 [st('Куда делись материалы?'), st('Цемент закупили, а на объекте его нет. Склад в тетради.'), st('Склад с партиями и QR: видно, что, когда и на какой объект выдано.')]] as [$q, $a, $s])
      <div class="pain reveal"><div class="q">«{{ $q }}»</div><p class="a">{{ $a }}</p><div class="s">{!! ic('circle-check', '', 17) !!}<span>{{ $s }}</span></div></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="modules" data-cms="Модули">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Модули') }}</span><h2>{{ st('Всё для стройки — в одной платформе') }}</h2><p>{{ st('От заявки прораба до отчёта директору. Включайте нужные модули для каждой роли.') }}</p></div>
    <div class="feats">
      @foreach ($modules as [$icn, $t, $d])
        <div class="feat reveal"><div class="f-ic">{!! ic($icn, '', 20) !!}</div><h3>{{ $t }}</h3><p>{{ $d }}</p></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec" data-cms="Как работает согласование">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Контроль платежей') }}</span><h2>{{ st('Ни одна оплата без согласования') }}</h2><p>{{ st('Вы сами задаёте, кто и в каком порядке утверждает платежи. Всё фиксируется: кто, когда и с каким комментарием.') }}</p></div>
    <div class="flow reveal">
      @foreach ([['hard-hat', st('Прораб'), st('создаёт заявку с телефона или в Telegram')], ['calculator', st('Бухгалтер'), st('проверяет лимит статьи и договор')], ['briefcase', st('Директор'), st('утверждает одной кнопкой в Telegram')], ['wallet', st('Кассир'), st('оплачивает из нужной кассы — расход сразу в отчётах')]] as $i => [$icn, $t, $d])
        <div class="step"><span class="n">{{ $i + 1 }}</span><span class="s-ic">{!! ic($icn, '', 22) !!}</span><b>{{ $t }}</b><p>{{ $d }}</p></div>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="report" data-cms="Отчёт директору">
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
          <b>{{ st('Деньги во всех кассах') }}: 4,82 {{ st('млрд') }} {{ st('сум') }}</b><br>
          {{ st('Потрачено сегодня') }}: <b>186 {{ st('млн') }}</b> (+12%)<br>
          {{ st('Поступило сегодня') }}: 320 {{ st('млн') }}<br><br>
          <b>{{ st('На что ушли деньги') }}:</b><br>• {{ st('Материалы') }} — 100 {{ st('млн') }} (54%)<br>• {{ st('Зарплата') }} — 41 {{ st('млн') }} (22%)<br>• {{ st('Техника и топливо') }} — 28 {{ st('млн') }} (15%)<br><br>
          <b>{{ st('Вывод ИИ') }}:</b><br>• {{ st('Расход на бетон на 18% выше плана — проверьте поставщика') }}
          <div class="t">20:00</div></div>
      </div></div>
    </div>
  </div>
</section>

<section class="sec" data-cms="Telegram-бот и телефон">
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

<section class="sec alt" id="security" data-cms="Безопасность">
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

<section class="sec" id="ai" data-cms="Искусственный интеллект">
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

<section class="sec alt" data-cms="Сравнение">
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

<section class="sec" data-cms="Отзывы">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Отзывы') }}</span><h2>{{ st('Что говорят руководители') }}</h2></div>
    <div class="quotes">
      @foreach ([[st('Раньше я узнавал о проблемах с деньгами, когда было уже поздно. Теперь каждый вечер вижу всё в Telegram, а платежи без моего согласования не проходят.'), st('Шерзод Каримов'), st('директор, Navruz Qurilish')],
                 [st('Склад и снабжение навели порядок за месяц: перестали терять материалы, а тендеры сэкономили нам около 12% на бетоне.'), st('Фарход Назаров'), st('коммерческий директор, Pamir Stroy')],
                 [st('Прорабы отправляют расходы и фото прямо в Telegram, бухгалтерии больше не нужно собирать чеки по объектам.'), st('Малика Юсупова'), st('главный бухгалтер, Samarqand Invest')]] as [$q, $n, $r])
        <figure class="quote reveal"><div class="stars">@for ($i = 0; $i < 5; $i++){!! ic('star', '', 15) !!}@endfor</div><blockquote>«{{ $q }}»</blockquote><figcaption><span class="avatar">{{ initials($n) }}</span><div><b>{{ $n }}</b><small>{{ $r }}</small></div></figcaption></figure>
      @endforeach
    </div>
  </div>
</section>

<section class="sec alt" id="pricing" data-cms="Тарифы">
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

<section class="sec" id="faq" data-cms="Вопросы и ответы">
  <div class="wrap">
    <div class="sec-h reveal"><span class="eyebrow">{{ st('Вопросы') }}</span><h2>{{ st('Частые вопросы') }}</h2></div>
    <div class="faq">
      @foreach ($faq as [$q, $a])<details class="reveal"><summary>{{ $q }}{!! ic('chevron-down', 'chev') !!}</summary><p>{{ $a }}</p></details>@endforeach
    </div>
  </div>
</section>

<section class="sec" style="padding-top:0" data-cms="Призыв в конце">
  <div class="wrap">
    <div class="cta-band reveal">
      <div><h2>{{ st('Наведите порядок в деньгах стройки') }}</h2><p>{{ st('Регистрация — 2 минуты. Бесплатно поможем перенести данные и обучим сотрудников.') }}</p></div>
      @if ($appUrl)<a class="btn btn-p btn-l" href="{{ $appUrl }}">{{ st('Открыть программу') }}{!! ic('arrow-right') !!}</a>@elseif ($regOpen)<a class="btn btn-p btn-l" href="/register">{{ st('Начать бесплатно') }}{!! ic('arrow-right') !!}</a>@endif
    </div>
  </div>
</section>
@endsection
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@graph' => [
  ['@type' => 'Organization', 'name' => $brand, 'url' => url('/'), 'logo' => asset('icons/icon-512.png'), 'contactPoint' => array_filter(['@type' => 'ContactPoint', 'telephone' => $support['phone'], 'email' => $support['email'], 'contactType' => 'customer support', 'availableLanguage' => ['Russian', 'Uzbek', 'Tajik']])],
  ['@type' => 'SoftwareApplication', 'name' => $brand, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, Android, iOS', 'description' => st('ERP и CRM для строительных компаний'),
   'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'UZS', 'lowPrice' => (float) ($plans->min('price_month') ?? 0), 'offerCount' => $plans->count()]],
  ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
@push('scripts')
<script>
document.getElementById('period')?.addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  document.querySelectorAll('#period button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.p-price[data-m]').forEach(p => p.firstChild.nodeValue = p.dataset[b.dataset.p] + ' ');
});
</script>
@endpush
