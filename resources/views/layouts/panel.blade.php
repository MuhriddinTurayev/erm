@php
    $u = auth()->user();
    $ws = $u->workspace;
    $path = '/'.ltrim(request()->path(), '/');
    $isOn = fn ($p) => $path === $p || ($p !== '/admin' && $p !== '/' && str_starts_with($path, $p.'/'));
    // меню: [заголовок раздела, [пункты]] — пункт: [ссылка, иконка, название, счётчик]; группа с ключом сворачивается
    if ($u->isSuperAdmin()) {
        $req = $u->adminCan('companies') ? \App\Models\Workspace::whereNotNull('plan_request')->count() : 0;
        $newLeads = $u->adminCan('companies') ? \App\Models\SiteLead::where('status', 'new')->count() : 0;
        $main = array_values(array_filter([
            ['/admin', 'layout-dashboard', __('Обзор')],
            $u->adminCan('companies') ? ['/admin/leads', 'inbox', __('Заявки с сайта'), $newLeads] : null,
            $u->adminCan('companies') ? ['/admin/companies', 'building-2', __('Компании'), $req] : null,
            $u->adminCan('payments') ? ['/admin/payments', 'credit-card', __('Оплаты')] : null,
        ]));
        $groups = [
            ['site', 'globe', __('Сайт'), array_values(array_filter([
                $u->adminCan('site') ? ['/admin/site', 'type', __('Тексты сайта')] : null,
                $u->adminCan('site') ? ['/admin/posts', 'newspaper', __('Блог')] : null,
                ['/', 'external-link', __('Открыть сайт')],
            ]))],
            ['setup', 'settings', __('Настройка'), array_values(array_filter([
                $u->adminCan('plans') ? ['/admin/plans', 'gem', __('Тарифы')] : null,
                $u->adminCan('ai') ? ['/admin/ai', 'sparkles', __('ИИ-подключения')] : null,
                $u->adminCan('settings') ? ['/admin/settings', 'sliders-horizontal', __('Настройки платформы')] : null,
                $u->isOwner() ? ['/admin/admins', 'user-cog', __('Администраторы')] : null,
            ]))],
        ];
        $alerts = array_values(array_filter([
            $newLeads ? ['/admin/leads', 'inbox', __('Новые заявки с сайта'), $newLeads, 'info'] : null,
            $req ? ['/admin/companies', 'building-2', __('Компании просят сменить тариф'), $req, 'warn'] : null,
        ]));
    } else {
        $m = fn (string $k) => $u->canModule($k);
        $myPay = $m('payments') ? \App\Models\PaymentRequest::of($ws->id)->where('status', 'pending')->get()->filter(fn ($r) => \App\Services\Erp\Payments::canDecide($u, $r))->count() : 0;
        $myExp = $u->isAdmin() ? \App\Models\Expense::where('workspace_id', $ws->id)->where('status', 'pending')->count() : 0;
        $myTasks = $m('tasks') ? \App\Models\Task::of($ws->id)->where('status', '!=', 'done')->where('assignee_id', $u->id)->count() : 0;
        $mySupply = $m('supply') && \App\Services\Erp\Supply::canDecide($u) ? \App\Models\SupplyRequest::of($ws->id)->where('status', 'new')->count() : 0;
        $main = array_values(array_filter([
            ['/crm', 'layers', __('Программа')],
            $m('monitoring') ? ['/company/monitor', 'layout-dashboard', __('Мониторинг')] : null,
            $m('objects') ? ['/company/objects', 'building-2', __('Объекты и 3D')] : null,
            $m('sales') ? ['/company/sales', 'key-round', __('Продажи квартир')] : null,
            $m('tasks') ? ['/company/tasks', 'list-checks', __('Задачи'), $myTasks] : null,
            $m('journal') ? ['/company/journal', 'camera', __('Журнал и фотоотчёты')] : null,
            $m('services') ? ['/company/services', 'wrench', __('Сервисные заявки')] : null,
        ]));
        $groups = [
            ['money', 'wallet', __('Деньги'), array_values(array_filter([
                $u->seesMoney() ? ['/company/overview', 'chart-pie', __('Сводка по объектам')] : null,
                $m('cash') ? ['/company/cash', 'wallet-cards', __('Кассы и счета')] : null,
                $m('payments') ? ['/company/payments', 'file-check', __('Заявки на оплату'), $myPay] : null,
                $m('approvals') ? ['/company/approvals', 'shield-check', __('Согласование расходов'), $myExp] : null,
                $m('forecast') ? ['/company/forecast', 'trending-up', __('Прогноз денег')] : null,
                $u->seesMoney() ? ['/company/reports', 'send', __('Ежедневные отчёты')] : null,
            ]))],
            ['supply', 'truck', __('Снабжение'), array_values(array_filter([
                $m('supply') ? ['/company/supply', 'package-plus', __('Заявки на материалы'), $mySupply] : null,
                $m('warehouse') ? ['/company/stock', 'warehouse', __('Склад')] : null,
                $m('tenders') ? ['/company/tenders', 'gavel', __('Тендеры')] : null,
                $m('counterparties') ? ['/company/parties', 'contact', __('Контрагенты')] : null,
                $m('contracts') ? ['/company/contracts', 'file-pen-line', __('Договоры')] : null,
            ]))],
            ['company', 'briefcase', __('Компания'), array_values(array_filter([
                $u->isAdmin() ? ['/company/users', 'users', __('Пользователи и роли')] : null,
                $u->isAdmin() ? ['/company/settings', 'settings', __('Настройки')] : null,
                $u->isAdmin() ? ['/company/audit', 'history', __('История изменений')] : null,
                $u->isAdmin() ? ['/company/billing', 'credit-card', __('Тариф и оплата')] : null,
            ]))],
        ];
        $alerts = array_values(array_filter([
            $myPay ? ['/company/payments', 'file-check', __('Заявки на оплату ждут вашего решения'), $myPay, 'warn'] : null,
            $myExp ? ['/company/approvals', 'shield-check', __('Расходы из Telegram ждут согласования'), $myExp, 'warn'] : null,
            $mySupply ? ['/company/supply?status=new', 'package-plus', __('Новые заявки на материалы'), $mySupply, 'info'] : null,
            $myTasks ? ['/company/tasks', 'list-checks', __('Ваши открытые задачи'), $myTasks, 'brand'] : null,
        ]));
    }
    $groups = array_values(array_filter($groups, fn ($g) => $g[3]));
    $help = $u->isSuperAdmin() ? [['/security', 'shield-check', __('Безопасность')]] : [['/company/guide', 'circle-help', __('Кто что вносит')]];
    $alertN = array_sum(array_map(fn ($a) => $a[3], $alerts));
    // для поиска по разделам (Ctrl+K)
    $find = [];
    foreach ($main as $it) {
        $find[] = ['h' => $it[0], 't' => $it[2], 'g' => __('Главное'), 'i' => $it[1]];
    }
    foreach ($groups as [, , $gt, $items]) {
        foreach ($items as $it) {
            $find[] = ['h' => $it[0], 't' => $it[2], 'g' => $gt, 'i' => $it[1]];
        }
    }
    foreach (array_merge($help, $u->isSuperAdmin() ? [] : [['/me/telegram', 'send', __('Мой Telegram')], ['/security', 'shield-check', __('Безопасность')]]) as $it) {
        $find[] = ['h' => $it[0], 't' => $it[2], 'g' => __('Помощь'), 'i' => $it[1]];
    }
    $iconsFor = array_unique(array_column($find, 'i'));
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>@yield('title') — {{ \App\Models\PlatformSetting::brand() }}</title>
<script>try { if (localStorage.getItem('oftob_sb_mini') === '1' && innerWidth > 980) document.documentElement.classList.add('sb-mini') } catch (e) {}</script>
<script src="{{ asset('assets/vendor/chart.umd.js') }}" defer></script>
<script src="{{ asset('assets/charts.js') }}?v={{ @filemtime(public_path('assets/charts.js')) }}" defer></script>
<script src="{{ asset('assets/insights.js') }}?v={{ @filemtime(public_path('assets/insights.js')) }}" defer></script>
@stack('head')
</head>
<body>
@if (session('impersonator'))
<div class="imp-bar">{!! ic('eye') !!}<span>{{ __('Вы вошли как :n (:c)', ['n' => $u->name, 'c' => $ws?->name]) }}</span><form method="post" action="/impersonate/stop">@csrf<button>{{ __('Вернуться в админку') }}</button></form></div>
@endif
<div class="shell">
  <aside class="sb" aria-label="{{ __('Меню') }}">
    <div class="sb-top">
      <a href="{{ $u->isSuperAdmin() ? '/admin' : '/crm' }}" class="sb-logo">@include('partials.logo')</a>
      <button type="button" class="icon-btn sb-close" onclick="document.body.classList.remove('sb-open')" aria-label="{{ __('Закрыть') }}">{!! ic('x') !!}</button>
    </div>
    <div class="sb-co" title="{{ $ws?->name }}">
      <span class="sb-co-av">{{ $ws ? initials($ws->name) : 'SA' }}</span>
      <span class="sb-co-t">
        @if ($ws)<b>{{ $ws->name }}</b><small>{{ $ws->plan?->title() ?? '—' }} · {{ $ws->stateLabel() }}</small>
        @else<b>{{ __('Администратор платформы') }}</b><small>{{ __('Супер-админ') }}</small>@endif
      </span>
    </div>
    <nav class="sb-nav">
      <div class="sb-sec">{{ __('Главное') }}</div>
      @foreach ($main as $it)
        <a href="{{ $it[0] }}" class="sb-a {{ $isOn($it[0]) ? 'on' : '' }}" title="{{ $it[2] }}">{!! ic($it[1]) !!}<span class="sb-t">{{ $it[2] }}</span>@if (! empty($it[3]))<span class="cnt">{{ $it[3] }}</span>@endif</a>
      @endforeach
      @if ($groups)<div class="sb-sec">{{ __('Разделы') }}</div>@endif
      @foreach ($groups as [$gk, $gi, $gt, $items])
        @php $gOn = collect($items)->contains(fn ($it) => $isOn(strtok($it[0], '?'))); $gN = array_sum(array_map(fn ($it) => $it[3] ?? 0, $items)); @endphp
        <details class="sb-grp" data-grp="{{ $gk }}" @if ($gOn) open data-cur @endif>
          <summary class="sb-a {{ $gOn ? 'has-on' : '' }}" title="{{ $gt }}">{!! ic($gi) !!}<span class="sb-t">{{ $gt }}</span>@if ($gN)<span class="cnt cnt-g">{{ $gN }}</span>@endif{!! ic('chevron-right', 'sb-chev') !!}</summary>
          <div class="sb-sub">
            @foreach ($items as $it)
              <a href="{{ $it[0] }}" class="sb-a {{ $isOn(strtok($it[0], '?')) ? 'on' : '' }}" title="{{ $it[2] }}">{!! ic($it[1]) !!}<span class="sb-t">{{ $it[2] }}</span>@if (! empty($it[3]))<span class="cnt">{{ $it[3] }}</span>@endif</a>
            @endforeach
          </div>
        </details>
      @endforeach
      <div class="sb-sec">{{ __('Помощь') }}</div>
      @foreach ($help as $it)
        <a href="{{ $it[0] }}" class="sb-a {{ $isOn($it[0]) ? 'on' : '' }}" title="{{ $it[2] }}">{!! ic($it[1]) !!}<span class="sb-t">{{ $it[2] }}</span></a>
      @endforeach
    </nav>
  </aside>
  <div class="sb-shade" onclick="document.body.classList.remove('sb-open')"></div>
  <div class="page">
    <header class="tb">
      <button type="button" class="icon-btn tb-menu" onclick="sbToggle()" aria-label="{{ __('Меню') }}" title="{{ __('Свернуть меню') }}">{!! ic('panel-left') !!}</button>
      <div class="tb-find" id="tbFind">
        {!! ic('search') !!}
        <input type="search" id="tbFindIn" placeholder="{{ __('Поиск раздела…') }}" autocomplete="off" aria-label="{{ __('Поиск раздела') }}">
        <kbd>Ctrl K</kbd>
        <div class="tb-drop tb-find-r" id="tbFindR" role="listbox"></div>
      </div>
      <div class="tb-r">
        <div class="tb-pop" id="tbBell">
          <button type="button" class="icon-btn tb-bell" onclick="tbPop('tbBell')" aria-label="{{ __('Уведомления') }}" title="{{ __('Уведомления') }}">{!! ic('bell') !!}@if ($alertN)<span class="dot-n">{{ $alertN > 99 ? '99+' : $alertN }}</span>@endif</button>
          <div class="tb-drop tb-bell-d">
            <div class="tb-drop-h"><b>{{ __('Уведомления') }}</b>@if ($alertN)<span class="badge b-brand plain">{{ $alertN }}</span>@endif</div>
            @forelse ($alerts as [$href, $icon, $text, $n, $tone])
              <a href="{{ $href }}" class="tb-note"><span class="tn-ic tn-{{ $tone }}">{!! ic($icon) !!}</span><span class="tn-t">{{ $text }}</span><b>{{ $n }}</b></a>
            @empty
              <div class="tb-empty">{!! ic('circle-check-big') !!}<span>{{ __('Всё сделано — новых дел нет') }}</span></div>
            @endforelse
          </div>
        </div>
        @include('partials.lang')
        @include('partials.theme-btn')
        <div class="tb-pop" id="tbUser">
          <button type="button" class="tb-user" onclick="tbPop('tbUser')" aria-label="{{ __('Профиль') }}">
            <span class="avatar">{{ initials($u->name) }}</span>
            <span class="tb-user-t"><b>{{ $u->name }}</b><small>{{ $u->roleName() }}</small></span>
            {!! ic('chevron-down', 'tb-user-ch') !!}
          </button>
          <div class="tb-drop tb-user-d">
            <div class="tb-drop-h tb-user-h"><span class="avatar">{{ initials($u->name) }}</span><span><b>{{ $u->name }}</b><small>{{ $u->email }}</small></span></div>
            <div class="tb-drop-m">@include('partials.lang')<button type="button" class="icon-btn" onclick="toggleTheme()" title="{{ __('Светлая / тёмная тема') }}">{!! ic('moon', 'only-light') !!}{!! ic('sun', 'only-dark') !!}</button></div>
            @unless ($u->isSuperAdmin())<a href="/me/telegram">{!! ic('send') !!}{{ __('Мой Telegram') }}</a>@endunless
            <a href="/security">{!! ic('shield-check') !!}{{ __('Безопасность') }}</a>
            @if (! $u->isSuperAdmin() && $u->isAdmin())<a href="/company/billing">{!! ic('credit-card') !!}{{ __('Тариф и оплата') }}</a>@endif
            <form method="post" action="/logout">@csrf<button type="submit">{!! ic('log-out') !!}{{ __('Выйти') }}</button></form>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
      <div class="ph">
        <div class="ph-t"><h1>@yield('title')</h1>@hasSection('subtitle')<p>@yield('subtitle')</p>@endif</div>
        @hasSection('actions')<div class="ph-act">@yield('actions')</div>@endif
      </div>
      @if ($ws && $ws->state() === 'trial' && $ws->daysLeft() !== null && $ws->daysLeft() <= 5)
        <div class="alert alert-warn">{!! ic('hourglass') !!}<div>{{ __('Пробный период заканчивается через :n дн.', ['n' => $ws->daysLeft()]) }} <a href="/company/billing">{{ __('Выбрать тариф') }}</a></div></div>
      @endif
      @include('partials.flash')
      @yield('content')
    </main>
  </div>
</div>
<template id="tbIcons">@foreach ($iconsFor as $i)<span data-i="{{ $i }}">{!! ic($i) !!}</span>@endforeach</template>
<script>
window.OFTOB_FIND = @json($find);
window.OFTOB_T = { nothing: @json(__('Ничего не найдено')) };
// меню: свернуть (компьютер) / открыть (телефон); раскрытые группы запоминаем
function sbToggle() {
  if (innerWidth <= 980) { document.body.classList.toggle('sb-open'); return; }
  const on = document.documentElement.classList.toggle('sb-mini');
  try { localStorage.setItem('oftob_sb_mini', on ? '1' : '0'); } catch (e) {}
}
document.querySelectorAll('.sb-grp').forEach(d => {
  let saved = null; try { saved = localStorage.getItem('oftob_grp_' + d.dataset.grp); } catch (e) {}
  if (!d.hasAttribute('data-cur') && saved === '1') d.open = true;
  d.addEventListener('toggle', () => {
    // открыт один раздел: остальные сворачиваем, чтобы меню не разрасталось
    if (d.open) document.querySelectorAll('.sb-grp[open]').forEach(x => { if (x !== d) x.open = false; });
    try { localStorage.setItem('oftob_grp_' + d.dataset.grp, d.open ? '1' : '0'); } catch (e) {}
  });
});
// выпадающие окна в шапке
function tbPop(id) {
  const el = document.getElementById(id), was = el.classList.contains('open');
  document.querySelectorAll('.tb-pop.open').forEach(x => x.classList.remove('open'));
  if (!was) el.classList.add('open');
}
document.addEventListener('click', e => { if (!e.target.closest('.tb-pop')) document.querySelectorAll('.tb-pop.open').forEach(x => x.classList.remove('open')); if (!e.target.closest('#tbFind')) document.getElementById('tbFind').classList.remove('open'); });
// поиск раздела: Ctrl+K или «/»
(function () {
  const box = document.getElementById('tbFind'), inp = document.getElementById('tbFindIn'), out = document.getElementById('tbFindR');
  const icons = {}; document.getElementById('tbIcons').content.querySelectorAll('[data-i]').forEach(s => icons[s.dataset.i] = s.innerHTML);
  const norm = s => (s || '').toLowerCase().replace(/[ʻʼ‘’`']/g, "'").replace(/ё/g, 'е');
  let sel = 0, list = [];
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  function draw() {
    const q = norm(inp.value.trim());
    list = window.OFTOB_FIND.filter(x => !q || norm(x.t).includes(q) || norm(x.g).includes(q)).slice(0, 12);
    sel = Math.min(sel, Math.max(0, list.length - 1));
    out.innerHTML = list.length ? list.map((x, i) => `<a href="${esc(x.h)}" class="${i === sel ? 'sel' : ''}" role="option">${icons[x.i] || ''}<span>${esc(x.t)}</span><small>${esc(x.g)}</small></a>`).join('') : `<div class="tb-empty">${esc(OFTOB_T.nothing)}</div>`;
    box.classList.add('open');
  }
  inp.addEventListener('focus', draw);
  inp.addEventListener('input', () => { sel = 0; draw(); });
  inp.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { sel = Math.min(sel + 1, list.length - 1); draw(); e.preventDefault(); }
    else if (e.key === 'ArrowUp') { sel = Math.max(sel - 1, 0); draw(); e.preventDefault(); }
    else if (e.key === 'Enter' && list[sel]) { location.href = list[sel].h; e.preventDefault(); }
    else if (e.key === 'Escape') { box.classList.remove('open'); inp.blur(); }
  });
  document.addEventListener('keydown', e => {
    if ((e.key === 'k' || e.key === 'K' || e.key === 'л' || e.key === 'Л') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); inp.focus(); inp.select(); }
    else if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) { e.preventDefault(); inp.focus(); }
    else if (e.key === 'Escape') { document.querySelectorAll('.tb-pop.open').forEach(x => x.classList.remove('open')); document.body.classList.remove('sb-open'); }
  });
})();
// модальные окна: data-open="id" открывает <dialog id>, data-close закрывает; data-fill — заполнить поля формы
document.addEventListener('click', e => {
  const o = e.target.closest('[data-open]');
  if (o) {
    e.preventDefault();
    const d = document.getElementById(o.dataset.open); if (!d) return;
    if (o.dataset.fill) { const v = JSON.parse(o.dataset.fill); const f = d.querySelector('form'); f && f.reset();
      for (const k in v) { const el = d.querySelector('[name="' + k + '"]'); if (!el) continue; if (el.type === 'checkbox') el.checked = !!v[k]; else el.value = v[k] ?? ''; el.dispatchEvent(new Event('change', {bubbles: true})); } }
    d.showModal(); const first = d.querySelector('input:not([type=hidden]),select,textarea'); first && first.focus();
    return;
  }
  if (e.target.closest('[data-close]')) { e.target.closest('dialog').close(); return; }
  if (e.target.tagName === 'DIALOG') e.target.close();
  const r = e.target.closest('tr[data-href]'); if (r && !e.target.closest('a,button,input,form,label')) location.href = r.dataset.href;
});
// отметить все
document.addEventListener('change', e => { if (e.target.matches('[data-all]')) document.querySelectorAll(e.target.dataset.all).forEach(c => c.checked = e.target.checked); });
// подтверждение (data-confirm, data-prompt) — свои диалоги: assets/dialog.js
</script>
@stack('scripts')
</body>
</html>
