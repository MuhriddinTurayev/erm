@php
    $u = auth()->user();
    $ws = $u->workspace;
    $path = '/'.ltrim(request()->path(), '/');
    $on = fn ($p) => $path === $p || ($p !== '/admin' && str_starts_with($path, $p.'/')) ? 'on' : '';
    if ($u->isSuperAdmin()) {
        $req = $u->adminCan('companies') ? \App\Models\Workspace::whereNotNull('plan_request')->count() : 0;
        $nav = [
            [__('Платформа'), array_values(array_filter([
                ['/admin', 'layout-dashboard', __('Обзор')],
                $u->adminCan('companies') ? ['/admin/companies', 'building-2', __('Компании'), $req] : null,
                $u->adminCan('payments') ? ['/admin/payments', 'credit-card', __('Оплаты')] : null,
            ]))],
            [__('Сайт'), array_values(array_filter([
                $u->adminCan('site') ? ['/admin/site', 'type', __('Тексты сайта')] : null,
                $u->adminCan('site') ? ['/admin/posts', 'newspaper', __('Блог')] : null,
                ['/', 'globe', __('Открыть сайт')],
            ]))],
            [__('Настройка'), array_values(array_filter([
                $u->adminCan('plans') ? ['/admin/plans', 'gem', __('Тарифы')] : null,
                $u->adminCan('ai') ? ['/admin/ai', 'sparkles', __('ИИ-подключения')] : null,
                $u->adminCan('settings') ? ['/admin/settings', 'settings', __('Настройки платформы')] : null,
                $u->isOwner() ? ['/admin/admins', 'user-cog', __('Администраторы')] : null,
                ['/security', 'shield-check', __('Безопасность')],
            ]))],
        ];
    } else {
        $m = fn (string $k) => $u->canModule($k);
        $myPay = $m('payments') ? \App\Models\PaymentRequest::of($ws->id)->where('status', 'pending')->get()->filter(fn ($r) => \App\Services\Erp\Payments::canDecide($u, $r))->count() : 0;
        $myExp = $u->isAdmin() ? \App\Models\Expense::where('workspace_id', $ws->id)->where('status', 'pending')->count() : 0;
        $myTasks = $m('tasks') ? \App\Models\Task::of($ws->id)->where('status', '!=', 'done')->where('assignee_id', $u->id)->count() : 0;
        $nav = [
            [__('Работа'), array_values(array_filter([
                ['/crm', 'layers', __('Программа')],
                $m('monitoring') ? ['/company/monitor', 'gauge', __('Мониторинг')] : null,
                $m('objects') ? ['/company/objects', 'building-2', __('Объекты и 3D')] : null,
                $m('sales') ? ['/company/sales', 'key-round', __('Продажи квартир')] : null,
                $m('tasks') ? ['/company/tasks', 'list-checks', __('Задачи'), $myTasks] : null,
                $m('journal') ? ['/company/journal', 'camera', __('Журнал и фотоотчёты')] : null,
                $m('services') ? ['/company/services', 'wrench', __('Сервисные заявки')] : null,
            ]))],
            [__('Деньги'), array_values(array_filter([
                $u->seesMoney() ? ['/company/overview', 'chart-pie', __('Сводка по объектам')] : null,
                $m('cash') ? ['/company/cash', 'wallet', __('Кассы и счета')] : null,
                $m('payments') ? ['/company/payments', 'file-check', __('Заявки на оплату'), $myPay] : null,
                $m('approvals') ? ['/company/approvals', 'shield-check', __('Согласование расходов'), $myExp] : null,
                $m('forecast') ? ['/company/forecast', 'trending-up', __('Прогноз денег')] : null,
                $u->seesMoney() ? ['/company/reports', 'send', __('Ежедневные отчёты')] : null,
            ]))],
            [__('Снабжение'), array_values(array_filter([
                $m('counterparties') ? ['/company/parties', 'contact', __('Контрагенты')] : null,
                $m('contracts') ? ['/company/contracts', 'file-pen-line', __('Договоры')] : null,
                $m('warehouse') ? ['/company/stock', 'warehouse', __('Склад')] : null,
                $m('supply') ? ['/company/supply', 'truck', __('Снабжение')] : null,
                $m('tenders') ? ['/company/tenders', 'gavel', __('Тендеры')] : null,
            ]))],
            [__('Компания'), array_values(array_filter([
                $u->isAdmin() ? ['/company/users', 'users', __('Пользователи и роли')] : null,
                $u->isAdmin() ? ['/company/settings', 'settings', __('Настройки')] : null,
                $u->isAdmin() ? ['/company/audit', 'history', __('История изменений')] : null,
                ['/company/guide', 'circle-help', __('Кто что вносит')],
                ['/me/telegram', 'send', __('Мой Telegram')],
                ['/security', 'shield-check', __('Безопасность')],
                $u->isAdmin() ? ['/company/billing', 'credit-card', __('Тариф и оплата')] : null,
            ]))],
        ];
        $nav = array_values(array_filter($nav, fn ($g) => $g[1]));
    }
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
@include('partials.head')
<title>@yield('title') — {{ \App\Models\PlatformSetting::brand() }}</title>
@stack('head')
</head>
<body>
@if (session('impersonator'))
<div class="imp-bar">{!! ic('eye') !!}<span>{{ __('Вы вошли как :n (:c)', ['n' => $u->name, 'c' => $ws?->name]) }}</span><form method="post" action="/impersonate/stop">@csrf<button>{{ __('Вернуться в админку') }}</button></form></div>
@endif
<div class="shell">
  <aside class="sb">
    <div class="sb-top"><a href="{{ $u->isSuperAdmin() ? '/admin' : '/crm' }}" style="text-decoration:none">@include('partials.logo')</a></div>
    @if ($ws)
    <div class="sb-co"><b>{{ $ws->name }}</b>{{ $ws->plan?->title() ?? '—' }} · {{ $ws->stateLabel() }}</div>
    @else
    <div class="sb-co"><b>{{ __('Администратор платформы') }}</b>{{ __('Супер-админ') }}</div>
    @endif
    <nav>
      @foreach ($nav as [$sec, $items])
        <div class="sb-sec">{{ $sec }}</div>
        @foreach ($items as $it)
          <a href="{{ $it[0] }}" class="{{ $on($it[0]) }}">{!! ic($it[1]) !!}<span>{{ $it[2] }}</span>@if (! empty($it[3]))<span class="cnt">{{ $it[3] }}</span>@endif</a>
        @endforeach
      @endforeach
    </nav>
    <div class="sb-foot">
      <span class="avatar">{{ initials($u->name) }}</span>
      <span class="who-t"><b>{{ $u->name }}</b>{{ $u->roleName() }}</span>
      <form method="post" action="/logout">@csrf<button class="icon-btn" title="{{ __('Выйти') }}" style="border:0;background:none">{!! ic('log-out') !!}</button></form>
    </div>
  </aside>
  <div class="sb-shade" onclick="document.body.classList.remove('sb-open')"></div>
  <div class="page">
    <header class="tb">
      <button class="icon-btn menu-btn" onclick="document.body.classList.toggle('sb-open')" aria-label="Menu">{!! ic('menu') !!}</button>
      <h1>@yield('title')</h1>
      @yield('actions')
      @include('partials.lang')
      @include('partials.theme-btn')
    </header>
    <main class="content">
      @if ($ws && $ws->state() === 'trial' && $ws->daysLeft() !== null && $ws->daysLeft() <= 5)
        <div class="alert alert-warn">{!! ic('hourglass') !!}<div>{{ __('Пробный период заканчивается через :n дн.', ['n' => $ws->daysLeft()]) }} <a href="/company/billing">{{ __('Выбрать тариф') }}</a></div></div>
      @endif
      @include('partials.flash')
      @yield('content')
    </main>
  </div>
</div>
<script>
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
