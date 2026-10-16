<?php

namespace App\Services\Bot;

use App\Models\Counterparty;
use App\Models\Item;
use App\Models\SupplyLine;
use App\Models\SupplyRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Erp\Stock;
use App\Services\Erp\Supply;
use App\Services\StateWriter;
use App\Services\Telegram;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Раздел «Материалы» в Telegram-боте.
 * Прораб: разделы → товар (остаток на объекте, «хватит на ~3 дня», последний приход) → количество кнопкой или числом → корзина
 * (дата, «срочно», комментарий или фото) → одна заявка. Дальше видит каждый шаг: согласована → заказана → в пути → принял
 * (фактическое количество и накладная — остаток склада обновляется). «Расход» — списание использованного на объект.
 * Снабжение (руководитель, бухгалтер): очередь заявок, поставщик и цены, «машина в пути». Прораб цен не видит.
 * Корзина хранится в users.tg_cart и не теряется между шагами; шаги ввода — в tg_state['flow'].
 */
class Materials
{
    private Workspace $ws;

    /** @param \Closure $askProject (array $action) — спросить объект и потом продолжить действие */
    public function __construct(private User $u, private string $chat, private \Closure $askProject)
    {
        $this->ws = $u->workspace;
    }

    /* ================================================================ общее */

    public function canOrder(): bool
    {
        return $this->u->canEdit() && $this->u->canModule('supply');
    }

    public function canUse(): bool
    {
        return $this->u->canEdit() && ($this->u->canModule('warehouse') || $this->u->canModule('supply'));
    }

    /** Объект для раздела: выбранный в боте, единственный доступный — или null (спросим) */
    public function pid(): ?string
    {
        $pr = StateWriter::projects($this->ws, $this->u->allowedObjects());
        if ($this->u->tg_project && isset($pr[$this->u->tg_project])) {
            return $this->u->tg_project;
        }

        return count($pr) === 1 ? (string) array_key_first($pr) : null;
    }

    private function pname(?string $pid): string
    {
        return $pid ? (StateWriter::projects($this->ws)[$pid] ?? '') : '';
    }

    /** Отправить новое сообщение или (по кнопке) обновить текущее */
    private function out(string $html, array $buttons = [], ?int $mid = null): void
    {
        try {
            $mid ? Telegram::edit($this->chat, $mid, $html, $buttons) : Telegram::message($this->chat, $html, $buttons);
        } catch (\Throwable $e) {
            Log::warning('Telegram materials: '.$e->getMessage());
        }
    }

    private function flow(?array $f): void
    {
        $this->u->forceFill(['tg_state' => $f ? ['flow' => $f] : null])->save();
    }

    private function unit(?Item $i): string
    {
        return Supply::unit($i?->unit ?: 'шт');
    }

    private function n(float $v): string
    {
        return Catalog::n($v);
    }

    /** Быстрые количества по единице измерения */
    private function quick(?Item $i, bool $use = false): array
    {
        $u = mb_strtolower((string) $i?->unit);

        return match (true) {
            in_array($u, ['т', 'тн', 'tonna', 't'], true) => $use ? [0.5, 1, 2, 5] : [1, 2, 5, 10],
            in_array($u, ['м³', 'м3', 'куб', 'm3'], true) => $use ? [1, 2, 5, 10] : [5, 10, 20, 30],
            in_array($u, ['м', 'm', 'метр', 'пог.м'], true) => $use ? [10, 20, 50, 100] : [50, 100, 200, 500],
            in_array($u, ['л', 'литр', 'l'], true) => $use ? [20, 50, 100, 200] : [100, 200, 500, 1000],
            default => $use ? [2, 5, 10, 20] : [10, 20, 50, 100],
        };
    }

    /** Сколько заказать, чтобы хватило на 2 недели (или до минимума) */
    private function suggest(array $row): ?float
    {
        $need = $row['day'] > 0 ? $row['day'] * 14 - max(0, $row['qty']) : ((float) $row['item']->min_qty - max(0, $row['qty']));
        if ($need <= 0) {
            return null;
        }

        return $need >= 10 ? (float) (ceil($need / 5) * 5) : (float) ceil($need);
    }

    /* ================================================================ корзина */

    public function cartGet(): array
    {
        $c = (array) ($this->u->tg_cart ?? []);
        $pid = $this->pid();
        if (($c['pid'] ?? null) !== $pid) {   // другой объект — своя корзина
            $c = ['pid' => $pid, 'items' => [], 'need' => null, 'urgent' => false, 'note' => '', 'photos' => []];
        }

        return $c + ['items' => [], 'need' => null, 'urgent' => false, 'note' => '', 'photos' => []];
    }

    private function cartPut(array $c): void
    {
        $this->u->forceFill(['tg_cart' => $c])->save();
    }

    private function cartCount(): int
    {
        return count($this->cartGet()['items']);
    }

    /** Положить в корзину (количество задаётся, а не прибавляется: нажал «20» — будет 20) */
    private function cartSet(?Item $i, float $qty, ?string $name = null, ?string $unit = null): void
    {
        $c = $this->cartGet();
        $key = $i ? 'i'.$i->id : 'n'.md5(mb_strtolower((string) $name));
        $c['items'][$key] = ['id' => $i?->id, 'name' => $i?->name ?? (string) $name, 'unit' => $i?->unit ?? ($unit ?: 'шт'), 'qty' => $qty];
        $this->cartPut($c);
    }

    /* ================================================================ экраны прораба */

    public function home(?int $mid = null): void
    {
        $pid = $this->pid();
        if (! $pid) {
            ($this->askProject)(['do' => 'materials']);

            return;
        }
        $wh = Catalog::warehouseOrMain($this->ws, $pid);
        $rows = Catalog::stock($this->ws, $pid, $wh);
        $L = ['🧱 <b>'.__('Материалы').' · '.e($this->pname($pid)).'</b>'];
        $L[] = '🏬 '.e(__($wh->name)).($wh->project_id ? '' : ' <i>('.__('у объекта нет своего склада').')</i>');
        $low = array_filter($rows, fn ($r) => $r['low']);
        if ($low) {
            $L[] = '';
            $L[] = '⚠️ <b>'.__('Заканчивается').':</b>';
            foreach (array_slice($low, 0, 6) as $r) {
                $L[] = '• '.e($r['item']->name).' — '.$this->n($r['qty']).' '.e($this->unit($r['item'])).($r['days'] !== null ? ' (~'.__(':n дн.', ['n' => $r['days']]).')' : '');
            }
        }
        $L[] = '';
        $L[] = __('Выберите раздел или напишите название — например «цемент».');
        $btn = [];
        foreach (Catalog::CATS as $k => [$label, $icon]) {
            $inCat = array_filter($rows, fn ($r) => $r['cat'] === $k);
            if (! $inCat) {
                continue;
            }
            $btn[] = ['text' => $icon.' '.__($label).' ('.count($inCat).')'.(array_filter($inCat, fn ($r) => $r['low']) ? ' ⚠️' : ''), 'data' => 'm:c:'.$k.':0'];
        }
        $b = array_chunk($btn, 2);
        $b[] = [['text' => '🔍 '.__('Поиск'), 'data' => 'm:sq'], ['text' => '⭐ '.__('Частые'), 'data' => 'm:f']];
        $b[] = [['text' => '🔁 '.__('Повторить заявку'), 'data' => 'm:pp'], ['text' => '🛒 '.__('Корзина').' ('.$this->cartCount().')', 'data' => 'm:k']];
        if ($this->canUse()) {
            $b[] = [['text' => '📤 '.__('Расход материала'), 'data' => 'u:h'], ['text' => '📦 '.__('Мои заявки'), 'data' => 'm:o']];
        }
        if ($this->canAdd()) {
            $b[] = [['text' => '➕ '.__('Новый материал'), 'data' => 'm:new:other']];
        }
        $this->flow(['k' => 'search']);   // любое слово — поиск товара
        $this->out(implode("\n", $L), $b, $mid);
    }

    /* ---------------- новый материал в справочнике ---------------- */

    /** Добавлять материалы в справочник могут те, кто ведёт склад (как на сайте: «Склад → + Товар») */
    public function canAdd(): bool
    {
        return $this->u->canEdit() && $this->u->canModule('warehouse');
    }

    /** Единицы для нового материала — справочник компании (Настройки → Справочники), не больше 12 кнопок */
    private function units(): array
    {
        return array_slice(\App\Services\Lists::get($this->ws, 'units'), 0, 12);
    }
    /** Группа склада для раздела бота (по ней материал и попадёт в свой раздел, если название не подскажет) */
    private const CAT_GROUP = ['fuel' => 'Топливо', 'cement' => 'Цемент и смеси', 'metal' => 'Металл', 'brick' => 'Стеновые', 'sand' => 'Сыпучие',
        'wood' => 'Древесина', 'electric' => 'Электрика', 'plumbing' => 'Сантехника', 'other' => null];

    /** «➕ Новый материал»: спросить название; если название уже есть (из поиска) — сразу единицу измерения */
    public function newItem(string $cat, ?string $name, ?int $mid): void
    {
        if (! $this->canAdd()) {
            $this->out(__('Новые материалы добавляет тот, кто ведёт склад: руководитель, бухгалтер или прораб.'), [], null);

            return;
        }
        $cat = isset(Catalog::CATS[$cat]) ? $cat : 'other';
        $name = trim((string) $name);
        if ($name === '') {
            $this->flow(['k' => 'newitem', 'cat' => $cat]);
            $this->out('➕ <b>'.__('Новый материал').'</b>'.($cat !== 'other' ? ' · '.Catalog::CATS[$cat][1].' '.__(Catalog::CATS[$cat][0]) : '')."\n"
                .__('Напишите название — например «Дизельное топливо» или «Цемент М500».'), [[['text' => '✖ '.__('Отмена'), 'data' => 'm:h']]], $mid);

            return;
        }
        $name = Str::limit($name, 160, '');
        if ($same = Item::of($this->ws->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()) {   // уже есть — открываем его
            $this->flow(null);
            $this->item($same->id, $mid);

            return;
        }
        $this->flow(['k' => 'newunit', 'cat' => $cat, 'name' => $name]);
        $rows = array_chunk(array_map(fn ($u, $i) => ['text' => Supply::unit($u), 'data' => 'm:nu:'.$i], $this->units(), array_keys($this->units())), 3);
        $rows[] = [['text' => '✖ '.__('Отмена'), 'data' => 'm:h']];
        $this->out('➕ <b>'.e($name).'</b>'."\n".__('В чём считаем?'), $rows, $mid);
    }

    /** Единица выбрана — материал в справочнике; сразу его карточка: сколько заказать */
    public function newItemUnit(int $i, ?int $mid): void
    {
        $f = (array) (((array) ($this->u->tg_state ?? []))['flow'] ?? []);
        if (($f['k'] ?? '') !== 'newunit' || ! $this->canAdd() || ! isset($this->units()[$i])) {
            $this->home($mid);

            return;
        }
        $name = (string) $f['name'];
        $item = Item::of($this->ws->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Item::create(['workspace_id' => $this->ws->id, 'name' => $name, 'unit' => $this->units()[$i], 'group' => self::CAT_GROUP[$f['cat'] ?? 'other'] ?? null, 'min_qty' => 0, 'price' => 0]);
        \App\Models\AuditLog::create(['workspace_id' => $this->ws->id, 'user_id' => $this->u->id, 'action' => 'stock.add', 'summary' => 'Новый материал (Telegram): '.$item->name.', '.$item->unit]);
        $this->flow(null);
        $this->item($item->id, $mid, __('Материал добавлен в справочник').': '.e($item->name));
    }

    public function category(string $cat, int $page, ?int $mid): void
    {
        $pid = $this->pid();
        if (! $pid || ! isset(Catalog::CATS[$cat])) {
            $this->home($mid);

            return;
        }
        $rows = array_values(array_filter(Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid)), fn ($r) => $r['cat'] === $cat));
        usort($rows, fn ($a, $b) => [$b['low'], $a['item']->name] <=> [$a['low'], $b['item']->name]);
        $this->list($rows, Catalog::CATS[$cat][1].' <b>'.__(Catalog::CATS[$cat][0]).'</b>', 'm:c:'.$cat, $page, $mid, 'm:i:',
            $this->canAdd() ? ['text' => '➕ '.__('Новый материал'), 'data' => 'm:new:'.$cat] : null);
    }

    /** Список товаров кнопками, по 12 на страницу */
    private function list(array $rows, string $title, string $base, int $page, ?int $mid, string $open = 'm:i:', ?array $add = null): void
    {
        $per = 12;
        $pages = max(1, (int) ceil(count($rows) / $per));
        $page = max(0, min($page, $pages - 1));
        $L = [$title.($pages > 1 ? ' · '.($page + 1).'/'.$pages : '')];
        $L[] = $rows ? __('Нажмите на материал.') : __('Ничего не найдено. Напишите название по-другому или выберите раздел.');
        $b = [];
        foreach (array_slice($rows, $page * $per, $per) as $r) {
            $t = ($r['low'] ? '⚠️ ' : '').Str::limit($r['item']->name, 30).' — '.$this->n($r['qty']).' '.$this->unit($r['item']);
            $b[] = [['text' => $t, 'data' => $open.$r['item']->id]];
        }
        $nav = [];
        if ($page > 0) {
            $nav[] = ['text' => '⬅️', 'data' => $base.':'.($page - 1)];
        }
        if ($page < $pages - 1) {
            $nav[] = ['text' => '➡️', 'data' => $base.':'.($page + 1)];
        }
        if ($nav) {
            $b[] = $nav;
        }
        if ($add) {
            $b[] = [$add];
        }
        $b[] = [['text' => '🏠 '.__('Разделы'), 'data' => 'm:h'], ['text' => '🛒 '.__('Корзина').' ('.$this->cartCount().')', 'data' => 'm:k']];
        $this->out(implode("\n", $L), $b, $mid);
    }

    public function search(string $q, ?int $mid = null): void
    {
        $pid = $this->pid();
        if (! $pid) {
            ($this->askProject)(['do' => 'materials']);

            return;
        }
        $nq = Catalog::norm($q);
        $hit = Catalog::find($this->ws, $q);
        $rows = array_values(array_filter(Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid)),
            fn ($r) => ($hit && $r['item']->id === $hit->id) || str_contains(Catalog::norm($r['item']->name), mb_substr($nq, 0, max(3, mb_strlen($nq) - 2)))));
        if (count($rows) === 1) {
            $this->item($rows[0]['item']->id, $mid);

            return;
        }
        $add = null;
        if ($this->canAdd() && mb_strlen(trim($q)) >= 2) {
            $this->flow(['k' => 'search', 'q' => Str::limit(trim($q), 60, '')]);   // «➕ Добавить …» возьмёт это название
            $add = ['text' => '➕ '.__('Добавить «:q» в справочник', ['q' => Str::limit(trim($q), 24)]), 'data' => 'm:nq'];
        }
        $this->list($rows, '🔍 <b>'.e($q).'</b>', 'm:sr', 0, $mid, 'm:i:', $add);
    }

    public function item(int $id, ?int $mid = null, string $done = ''): void
    {
        $pid = $this->pid();
        $rows = $pid ? Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid)) : [];
        $r = $rows[$id] ?? null;
        if (! $r) {
            $this->home($mid);

            return;
        }
        $i = $r['item'];
        $u = e($this->unit($i));
        $inCart = $this->cartGet()['items']['i'.$i->id]['qty'] ?? null;
        $L = $done !== '' ? ['✅ '.$done, ''] : [];
        $L[] = Catalog::CATS[$r['cat']][1].' <b>'.e($i->name).'</b>';
        $L[] = '🏬 '.__('На складе объекта').': <b>'.$this->n($r['qty']).' '.$u.'</b>'.($r['low'] ? ' ⚠️' : '');
        if ($r['day'] > 0) {
            $L[] = '📉 '.__('Расходуется').': ~'.$this->n(round($r['day'], 1)).' '.$u.' '.__('в день').($r['days'] !== null ? ' · '.__('хватит на ~:n дн.', ['n' => $r['days']]) : '');
        }
        if ($r['last']) {
            $L[] = '📥 '.__('Последний приход').': '.\Illuminate\Support\Carbon::parse($r['last'])->format('d.m.Y');
        }
        if ($inCart) {
            $L[] = '🛒 '.__('В корзине').': <b>'.$this->n($inCart).' '.$u.'</b>';
        }
        $L[] = '';
        $L[] = __('Сколько нужно? Нажмите кнопку или напишите число.');
        $b = [];
        $sug = $this->suggest($r);
        $q = array_map(fn ($v) => ['text' => $this->n($v), 'data' => 'm:q:'.$i->id.':'.$v], $this->quick($i));
        if ($sug) {
            $b[] = [['text' => '💡 '.__('На 2 недели').': '.$this->n($sug).' '.$this->unit($i), 'data' => 'm:q:'.$i->id.':'.$sug]];
        }
        $b[] = $q;
        $back = [['text' => '⬅️ '.__('Назад'), 'data' => 'm:c:'.$r['cat'].':0'], ['text' => '🛒 '.__('Корзина').' ('.$this->cartCount().')', 'data' => 'm:k']];
        if ($inCart) {
            $b[] = [['text' => '❌ '.__('Убрать из корзины'), 'data' => 'm:r:i'.$i->id]];
        }
        $b[] = $back;
        $this->flow(['k' => 'qty', 'item' => $i->id]);
        $this->out(implode("\n", $L), $b, $mid);
    }

    public function setQty(int $id, float $qty, ?int $mid = null): void
    {
        $i = Item::of($this->ws->id)->find($id);
        if (! $i || $qty <= 0 || $qty > 1e7) {
            $this->out('🤔 '.__('Напишите количество числом, например 20.'));

            return;
        }
        $this->cartSet($i, $qty);
        $this->item($id, $mid, __('В корзине').': '.e($i->name).' — '.$this->n($qty).' '.e($this->unit($i)));
    }

    public function cart(?int $mid = null, string $done = ''): void
    {
        $pid = $this->pid();
        if (! $pid) {
            ($this->askProject)(['do' => 'cart']);

            return;
        }
        $c = $this->cartGet();
        if (! $c['items']) {
            $this->out(($done !== '' ? '✅ '.$done."\n\n" : '').'🛒 '.__('Корзина пуста.')."\n".__('Выберите материалы в разделе «Материалы» или повторите прошлую заявку.'), [
                [['text' => '🧱 '.__('Материалы'), 'data' => 'm:h'], ['text' => '🔁 '.__('Повторить заявку'), 'data' => 'm:pp']],
                [['text' => '⭐ '.__('Частые'), 'data' => 'm:f']],
            ], $mid);

            return;
        }
        $L = $done !== '' ? ['✅ '.$done, ''] : [];
        $L[] = '🛒 <b>'.__('Корзина').' · '.e($this->pname($pid)).'</b>';
        $k = 0;
        foreach ($c['items'] as $it) {
            $L[] = (++$k).'. '.e($it['name']).' — <b>'.$this->n($it['qty']).' '.e(Supply::unit($it['unit'])).'</b>';
        }
        $L[] = '';
        $L[] = '📅 '.__('Нужно к').': <b>'.($c['need'] !== null ? $this->ws->localNow()->addDays((int) $c['need'])->format('d.m.Y') : __('не указано')).'</b>';
        $L[] = ($c['urgent'] ? '🔥 <b>'.__('Срочно').'</b>' : '⏱ '.__('Обычная'));
        if ($c['note'] !== '' || $c['photos']) {
            $L[] = '✏️ '.e($c['note']).($c['photos'] ? ' · 📷 '.count($c['photos']) : '');
        }
        $b = [];
        $del = [];
        $k = 0;
        foreach ($c['items'] as $key => $it) {
            $del[] = ['text' => '❌ '.(++$k).'. '.Str::limit($it['name'], 16), 'data' => 'm:r:'.$key];
        }
        foreach (array_chunk($del, 2) as $row) {
            $b[] = $row;
        }
        $d = fn ($n, $t) => ['text' => ($c['need'] === $n ? '✓ ' : '').$t, 'data' => 'm:d:'.$n];
        $b[] = [$d(0, __('Сегодня')), $d(1, __('Завтра')), $d(2, __('2 дня')), $d(7, __('Неделя'))];
        $b[] = [['text' => ($c['urgent'] ? '✓ ' : '').'🔥 '.__('Срочно'), 'data' => 'm:u'], ['text' => '✏️ '.__('Комментарий / фото'), 'data' => 'm:n']];
        $b[] = [['text' => '➕ '.__('Ещё материал'), 'data' => 'm:h'], ['text' => '🗑 '.__('Очистить'), 'data' => 'm:z']];
        $b[] = [['text' => '✅ '.__('Отправить заявку'), 'data' => 'm:s']];
        $this->flow(null);
        $this->out(implode("\n", $L), $b, $mid);
    }

    public function cartAction(string $act, string $arg, ?int $mid): void
    {
        $c = $this->cartGet();
        if ($act === 'r') {
            unset($c['items'][$arg]);
        } elseif ($act === 'd') {
            $c['need'] = $c['need'] === (int) $arg ? null : (int) $arg;
        } elseif ($act === 'u') {
            $c['urgent'] = ! $c['urgent'];
        } elseif ($act === 'z') {
            $c = ['pid' => $c['pid'], 'items' => [], 'need' => null, 'urgent' => false, 'note' => '', 'photos' => []];
        }
        $this->cartPut($c);
        $this->cart($mid);
    }

    public function askNote(): void
    {
        $this->flow(['k' => 'note']);
        $this->out('✏️ '.__('Напишите комментарий к заявке или пришлите фото (можно несколько). /bekor — отменить'));
    }

    public function addNote(?string $text, ?string $photo): void
    {
        $c = $this->cartGet();
        if ($text !== null && $text !== '') {
            $c['note'] = mb_substr($text, 0, 300);
        }
        if ($photo) {
            $c['photos'][] = $photo;
            $c['photos'] = array_slice($c['photos'], -6);
        }
        $this->cartPut($c);
        $this->cart(null, $photo ? __('Фото добавлено') : __('Комментарий сохранён'));
        $this->flow(['k' => 'note_ph']);   // следом можно прислать ещё фото; любой текст пойдёт как обычно
    }

    /** Отправить корзину одной заявкой */
    public function submit(?int $mid): void
    {
        $pid = $this->pid();
        $c = $this->cartGet();
        if (! $this->canOrder() || ! $pid || ! $c['items']) {
            $this->cart($mid);

            return;
        }
        $photos = array_values(array_filter(array_map(fn ($p) => $this->moveFile($p, 'supply/'.$this->ws->id), (array) $c['photos'])));
        $lines = array_map(fn ($it) => ['item_id' => $it['id'], 'name' => $it['name'], 'unit' => $it['unit'], 'qty' => $it['qty']], array_values($c['items']));
        $need = $c['need'] !== null ? $this->ws->localNow()->addDays((int) $c['need'])->toDateString() : null;
        $this->cartPut(['pid' => $pid, 'items' => [], 'need' => null, 'urgent' => false, 'note' => '', 'photos' => []]);   // повторное нажатие не создаст вторую заявку
        $sr = Supply::create($this->ws, $this->u, $pid, $lines, $need, $c['note'] ?: null, 'telegram', ['urgent' => $c['urgent'], 'photos' => $photos]);
        $this->out(Supply::card($sr)."\n\n".($sr->status === 'new' ? '⏳ '.__('Отправлено на согласование. Каждый шаг придёт сюда.') : '✅ '.__('Заявка согласована — снабжение получит её сразу.')),
            [[['text' => '📦 '.__('Мои заявки'), 'data' => 'm:o'], ['text' => '🧱 '.__('Материалы'), 'data' => 'm:h']]], $mid);
    }

    /** ⭐ Что этот прораб заказывает чаще всего */
    public function favorites(?int $mid): void
    {
        $pid = $this->pid();
        $ids = SupplyLine::query()->join('supply_requests', 'supply_requests.id', '=', 'supply_lines.supply_request_id')
            ->where('supply_requests.workspace_id', $this->ws->id)->where(fn ($q) => $q->where('supply_requests.user_id', $this->u->id)->when($pid, fn ($x) => $x->orWhere('supply_requests.project_id', $pid)))
            ->selectRaw('supply_lines.item_id, count(*) n')->groupBy('supply_lines.item_id')->orderByDesc('n')->limit(10)->pluck('item_id')->all();
        $rows = $pid ? Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid)) : [];
        $this->list(array_values(array_filter(array_map(fn ($id) => $rows[$id] ?? null, $ids))), '⭐ <b>'.__('Частые материалы').'</b>', 'm:f', 0, $mid);
    }

    /** 🔁 Последние заявки — повторить одной кнопкой */
    public function repeatList(?int $mid): void
    {
        $list = SupplyRequest::of($this->ws->id)->where('user_id', $this->u->id)->with('lines.item')->latest('id')->limit(6)->get();
        if (! $list->count()) {
            $this->out('🔁 '.__('Прошлых заявок пока нет.'), [[['text' => '🧱 '.__('Материалы'), 'data' => 'm:h']]], $mid);

            return;
        }
        $b = $list->map(fn ($sr) => [['text' => '№'.$sr->number.' · '.$sr->created_at->format('d.m').' · '.Str::limit($sr->lines->map(fn ($l) => $l->item?->name)->filter()->implode(', '), 32), 'data' => 'm:p:'.$sr->id]])->all();
        $b[] = [['text' => '🛒 '.__('Корзина').' ('.$this->cartCount().')', 'data' => 'm:k']];
        $this->out('🔁 <b>'.__('Повторить заявку').'</b>'."\n".__('Позиции выбранной заявки лягут в корзину — количество можно поменять.'), $b, $mid);
    }

    public function repeat(int $srId, ?int $mid): void
    {
        $sr = SupplyRequest::of($this->ws->id)->with('lines.item')->find($srId);
        if (! $sr || (int) $sr->user_id !== $this->u->id && ! Supply::canDecide($this->u)) {
            $this->cart($mid);

            return;
        }
        foreach ($sr->lines as $l) {
            if ($l->item) {
                $this->cartSet($l->item, (float) $l->qty);
            }
        }
        $this->cart($mid, __('Позиции заявки №:n в корзине', ['n' => $sr->number]));
    }

    /** 📦 Мои заявки (снабжению — общая очередь) */
    public function orders(?int $mid): void
    {
        if (Supply::canDecide($this->u)) {
            $this->queue($mid);

            return;
        }
        $list = SupplyRequest::of($this->ws->id)->where('user_id', $this->u->id)->withCount('lines')->latest('id')->limit(8)->get();
        $L = ['📦 <b>'.__('Мои заявки').'</b>', __('Новая → Согласована → Заказана → В пути → Принята')];
        $b = [];
        foreach ($list as $sr) {
            $b[] = [['text' => Supply::statusIcon($sr->status).' №'.$sr->number.' · '.$sr->created_at->format('d.m').' · '.__(Supply::STATUS[$sr->status] ?? $sr->status).($sr->urgent ? ' 🔥' : ''), 'data' => 'm:oo:'.$sr->id]];
        }
        if (! $b) {
            $L[] = '';
            $L[] = __('Заявок пока нет.');
        }
        $b[] = [['text' => '🧱 '.__('Материалы'), 'data' => 'm:h'], ['text' => '🛒 '.__('Корзина').' ('.$this->cartCount().')', 'data' => 'm:k']];
        $this->out(implode("\n", $L), $b, $mid);
    }

    public function order(int $srId, ?int $mid): void
    {
        $sr = $this->own($srId);
        if (! $sr) {
            $this->orders($mid);

            return;
        }
        $b = [];
        if (in_array($sr->status, Supply::RECEIVABLE, true) && $this->canUse()) {
            $b[] = [['text' => '📥 '.__('Принял на объекте'), 'data' => 'rc:'.$sr->id]];
        }
        $b[] = [['text' => '🔁 '.__('Повторить'), 'data' => 'm:p:'.$sr->id], ['text' => '⬅️ '.__('Мои заявки'), 'data' => 'm:o']];
        $this->out(Supply::card($sr, Supply::canDecide($this->u)), $b, $mid);
    }

    /** Заявка своей компании и своего объекта (прораб одного объекта не видит чужие) */
    private function own(int $srId): ?SupplyRequest
    {
        $sr = SupplyRequest::of($this->ws->id)->find($srId);
        $only = $this->u->allowedObjects();

        return $sr && ($only === null || $sr->project_id === null || in_array((string) $sr->project_id, $only, true)) ? $sr : null;
    }

    /* ================================================================ приёмка */

    public function receiveStart(int $srId, ?int $mid): void
    {
        $sr = $this->own($srId);
        if (! $sr || ! $this->canUse() || ! in_array($sr->status, Supply::RECEIVABLE, true)) {
            $this->out(__('Эту заявку уже нельзя принять.'), [], $mid);

            return;
        }
        $sr->load('lines.item');
        $L = ['📥 <b>'.__('Приёмка по заявке №:n', ['n' => $sr->number]).'</b>', __('Должно прийти:')];
        foreach ($sr->lines as $k => $l) {
            $L[] = ($k + 1).'. '.e($l->item?->name).' — <b>'.$this->n(max(0, $l->qty - $l->received)).' '.e($this->unit($l->item)).'</b>';
        }
        $L[] = '';
        $L[] = __('Всё пришло полностью? Если нет — напишите, сколько пришло фактически.');
        $this->out(implode("\n", $L), [
            [['text' => '✅ '.__('Всё пришло полностью'), 'data' => 'rc:a:'.$sr->id]],
            [['text' => '✏️ '.__('Пришло другое количество'), 'data' => 'rc:e:'.$sr->id]],
        ], $mid);
    }

    public function receiveAll(int $srId, ?int $mid): void
    {
        $sr = $this->own($srId);
        if (! $sr) {
            return;
        }
        $q = [];
        foreach ($sr->lines as $l) {
            $q[$l->id] = max(0, (float) $l->qty - (float) $l->received);
        }
        $this->askInvoice($sr, $q, $mid);
    }

    public function receiveAskQty(int $srId, ?int $mid): void
    {
        $sr = $this->own($srId);
        if (! $sr) {
            return;
        }
        $this->flow(['k' => 'rcv_qty', 'sr' => $sr->id]);
        $L = ['✏️ '.__('Напишите, сколько пришло, через запятую — по порядку позиций:')];
        foreach ($sr->lines()->with('item')->get() as $k => $l) {
            $L[] = ($k + 1).'. '.e($l->item?->name).' ('.$this->unit($l->item).')';
        }
        $L[] = '<i>'.__('Например: 50, 2,5, 0').'</i>';
        $this->out(implode("\n", $L), [], $mid);
    }

    public function receiveQtyText(int $srId, string $text): void
    {
        $sr = $this->own($srId);
        if (! $sr) {
            $this->flow(null);

            return;
        }
        $lines = $sr->lines()->get();
        // «50, 2,5, 0» — десятичная запятая без пробела после неё, разделитель — запятая с пробелом, точка с запятой или перевод строки
        $parts = preg_split('/\s*[;\n]\s*|,\s+|\s{2,}/u', trim($text));
        $nums = array_map(fn ($p) => (float) str_replace([' ', ','], ['', '.'], $p), array_values(array_filter($parts, fn ($p) => preg_match('/\d/', $p))));
        if (count($nums) !== $lines->count()) {
            $this->out('🤔 '.__('Нужно :n чисел — по одному на каждую позицию. Например: 50, 20', ['n' => $lines->count()]));

            return;
        }
        $q = [];
        foreach ($lines->values() as $k => $l) {
            $q[$l->id] = max(0, $nums[$k]);
        }
        $this->askInvoice($sr, $q, null);
    }

    private function askInvoice(SupplyRequest $sr, array $q, ?int $mid): void
    {
        if (! array_filter($q, fn ($v) => $v > 0)) {
            $this->flow(null);
            $this->out(__('Количество — ноль, принимать нечего.'), [], $mid);

            return;
        }
        $this->flow(['k' => 'rcv_photo', 'sr' => $sr->id, 'q' => $q]);
        $this->out('📄 '.__('Пришлите фото накладной (можно несколько) — или нажмите «Без накладной».'), [[['text' => '✅ '.__('Без накладной — принять'), 'data' => 'rc:n:'.$sr->id]]], $mid);
    }

    public function receivePhoto(string $path): void
    {
        $f = (array) ($this->u->tg_state['flow'] ?? []);
        $f['ph'][] = $path;
        $this->u->forceFill(['tg_state' => ['flow' => $f]])->save();
        $this->out('📄 '.__('Накладная получена (:n фото).', ['n' => count($f['ph'])]), [[['text' => '✅ '.__('Принять на склад'), 'data' => 'rc:n:'.$f['sr']]]]);
    }

    public function receiveFinish(int $srId, ?int $mid): void
    {
        $f = (array) ($this->u->tg_state['flow'] ?? []);
        $sr = $this->own($srId);
        if (! $sr || ($f['k'] ?? '') !== 'rcv_photo' || (int) ($f['sr'] ?? 0) !== $sr->id) {
            $this->out(__('Приёмка уже проведена.'), [], $mid);

            return;
        }
        $this->flow(null);
        $photos = array_values(array_filter(array_map(fn ($p) => $this->moveFile($p, 'supply/'.$this->ws->id), (array) ($f['ph'] ?? []))));
        try {
            $ok = Supply::receive($sr, $this->u, array_map('floatval', (array) $f['q']), $photos);
        } catch (\Throwable $e) {
            Log::warning('Bot receive: '.$e->getMessage());
            $ok = false;
        }
        if (! $ok) {
            $this->out(__('Не удалось принять — проверьте заявку на сайте.'), [], $mid);

            return;
        }
        $this->out('📥 '.__('Принято на склад — остатки обновлены.')."\n\n".Supply::card($sr->fresh()), [[['text' => '🧱 '.__('Материалы'), 'data' => 'm:h']]], $mid);
    }

    /* ================================================================ расход */

    public function useHome(?int $mid, int $page = 0): void
    {
        $pid = $this->pid();
        if (! $pid) {
            ($this->askProject)(['do' => 'use']);

            return;
        }
        $rows = array_values(array_filter(Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid)), fn ($r) => $r['qty'] > 0));
        usort($rows, fn ($a, $b) => strcmp($a['item']->name, $b['item']->name));
        if (! $rows) {
            $this->out('📤 '.__('На складе объекта пока ничего нет — списывать нечего.'), [[['text' => '🧱 '.__('Материалы'), 'data' => 'm:h']]], $mid);

            return;
        }
        $this->flow(['k' => 'use_search']);
        $this->list($rows, '📤 <b>'.__('Расход материала').'</b> · '.e($this->pname($pid))."\n".__('Что использовали? Нажмите или напишите, например: «20 мешков цемента»'), 'u:hp', $page, $mid, 'u:i:');
    }

    public function useItem(int $id, ?int $mid): void
    {
        $pid = $this->pid();
        $r = $pid ? (Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid))[$id] ?? null) : null;
        if (! $r) {
            $this->useHome($mid);

            return;
        }
        $i = $r['item'];
        $this->flow(['k' => 'use', 'item' => $i->id]);
        $q = array_map(fn ($v) => ['text' => $this->n($v), 'data' => 'u:q:'.$i->id.':'.$v], array_filter($this->quick($i, true), fn ($v) => $v <= $r['qty']));
        $this->out('📤 <b>'.e($i->name).'</b>'."\n".__('На складе').': <b>'.$this->n($r['qty']).' '.e($this->unit($i)).'</b>'."\n\n".__('Сколько использовали? Нажмите или напишите число.'),
            array_values(array_filter([$q ?: null, [['text' => '⬅️ '.__('Назад'), 'data' => 'u:h']]])), $mid);
    }

    public function useConfirm(int $id, float $qty, ?int $mid): void
    {
        $pid = $this->pid();
        $r = $pid ? (Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid))[$id] ?? null) : null;
        if (! $r || $qty <= 0) {
            $this->useHome($mid);

            return;
        }
        $u = e($this->unit($r['item']));
        if ($qty > $r['qty'] + 0.0005) {
            $this->out('⚠️ '.__('На складе только :q :u', ['q' => $this->n($r['qty']), 'u' => $u]), [[['text' => '⬅️ '.__('Назад'), 'data' => 'u:i:'.$id]]], $mid);

            return;
        }
        $this->flow(null);
        $this->out('📤 '.__('Списать на «:o»', ['o' => e($this->pname($pid))]).":\n<b>".e($r['item']->name).' — '.$this->n($qty).' '.$u.'</b>'."\n".__('Останется').': '.$this->n($r['qty'] - $qty).' '.$u, [
            [['text' => '✅ '.__('Да, списать'), 'data' => 'u:ok:'.$id.':'.$qty], ['text' => '✖ '.__('Отмена'), 'data' => 'u:h']],
        ], $mid);
    }

    public function useOk(int $id, float $qty, ?int $mid): void
    {
        $pid = $this->pid();
        if (! $pid || ! $this->canUse()) {
            return;
        }
        $wh = Catalog::warehouseOrMain($this->ws, $pid);
        // одно нажатие — одно списание (повтор той же кнопки в течение минуты не пройдёт)
        if (! Cache::add('tg-use:'.$this->u->id.':'.$mid.':'.$id.':'.$qty, 1, 60)) {
            return;
        }
        try {
            Stock::issue($this->ws, $this->u, ['item_id' => $id, 'warehouse_id' => $wh->id, 'qty' => $qty, 'project_id' => $pid, 'note' => 'Telegram']);
        } catch (\DomainException $e) {
            $this->out('⚠️ '.e($e->getMessage()), [[['text' => '⬅️ '.__('Назад'), 'data' => 'u:h']]], $mid);

            return;
        }
        $r = Catalog::stock($this->ws, $pid, $wh)[$id] ?? null;
        $u = $r ? e($this->unit($r['item'])) : '';
        $this->out('✅ '.__('Списано').': '.e($r['item']->name ?? '').' — '.$this->n($qty).' '.$u."\n".__('Осталось').': <b>'.$this->n($r['qty'] ?? 0).' '.$u.'</b>'
            .($r && $r['days'] !== null ? ' · '.__('хватит на ~:n дн.', ['n' => $r['days']]) : ''), [
                [['text' => '📤 '.__('Ещё расход'), 'data' => 'u:h'], ['text' => '🧱 '.__('Материалы'), 'data' => 'm:h']],
            ], $mid);
        if ($r && $r['low']) {
            self::lowAlert($this->ws, $pid, $id);
        }
    }

    /* ================================================================ предложения из свободного текста */

    /** «sement 20 qop kerak» → найденные товары в корзину (после подтверждения) */
    public function propose(array $lines): void
    {
        $items = [];
        foreach ($lines as $l) {
            $i = Catalog::find($this->ws, $l['name']);
            $items[] = ['id' => $i?->id, 'name' => $i?->name ?? $l['name'], 'unit' => $i?->unit ?? $l['unit'], 'qty' => (float) $l['qty'], 'found' => (bool) $i];
        }
        $this->u->forceFill(['tg_state' => ['flow' => ['k' => 'propose', 'items' => $items]]])->save();
        $L = ['🛒 <b>'.__('Положить в корзину?').'</b>'];
        foreach ($items as $k => $it) {
            $L[] = ($k + 1).'. '.e($it['name']).' — <b>'.$this->n($it['qty']).' '.e(Supply::unit($it['unit'])).'</b>'.($it['found'] ? '' : ' <i>('.__('новый материал').')</i>');
        }
        $this->out(implode("\n", $L), [[['text' => '✅ '.__('Да, в корзину'), 'data' => 'm:a'], ['text' => '✖ '.__('Отмена'), 'data' => 'm:h']]]);
    }

    public function proposeAccept(?int $mid): void
    {
        $f = (array) ($this->u->tg_state['flow'] ?? []);
        if (($f['k'] ?? '') !== 'propose') {
            $this->cart($mid);

            return;
        }
        foreach ((array) $f['items'] as $it) {
            $this->cartSet($it['id'] ? Item::of($this->ws->id)->find($it['id']) : null, (float) $it['qty'], $it['name'], $it['unit']);
        }
        $this->cart($mid, __('Добавлено в корзину'));
    }

    /** Строки черновика заявки («/zayavka» и список) — в корзину */
    public function addLines(array $lines, ?int $mid): void
    {
        foreach ($lines as $l) {
            $i = ! empty($l['item_id']) ? Item::of($this->ws->id)->find((int) $l['item_id']) : Catalog::find($this->ws, (string) $l['name']);
            $this->cartSet($i, (float) $l['qty'], (string) $l['name'], (string) ($l['unit'] ?? 'шт'));
        }
        $this->cart($mid, __('Добавлено в корзину'));
    }

    /** «20 qop sement ishlatildi» → подтверждение списания */
    public function proposeUse(array $line): bool
    {
        $i = Catalog::find($this->ws, $line['name']);
        if (! $i || ! $this->pid()) {
            return false;
        }
        $this->useConfirm($i->id, (float) $line['qty'], null);

        return true;
    }

    /* ================================================================ снабжение */

    /** Очередь заявок для снабжения: новые, согласованные, заказанные, в пути */
    public function queue(?int $mid): void
    {
        $list = SupplyRequest::of($this->ws->id)->whereIn('status', ['new', 'approved', 'ordered', 'transit', 'partial'])->with('user')->withCount('lines')
            ->orderByDesc('urgent')->orderByRaw("case status when 'new' then 0 when 'approved' then 1 when 'ordered' then 2 when 'transit' then 3 else 4 end")->orderBy('id')->limit(12)->get();
        $L = ['📦 <b>'.__('Заявки снабжения').'</b>'];
        foreach (['new', 'approved', 'ordered', 'transit', 'partial'] as $st) {
            $n = $list->where('status', $st)->count();
            if ($n) {
                $L[] = Supply::statusIcon($st).' '.__(Supply::STATUS[$st]).': <b>'.$n.'</b>';
            }
        }
        if (! $list->count()) {
            $L[] = '✅ '.__('Очередь пуста.');
        }
        $b = $list->map(fn ($sr) => [['text' => Supply::statusIcon($sr->status).($sr->urgent ? '🔥' : '').' №'.$sr->number.' · '.Str::limit($this->pname($sr->project_id), 18).' · '.Str::limit($sr->user?->name ?? '', 14), 'data' => 'sq:'.$sr->id]])->all();
        $b[] = [['text' => __('Открыть на сайте'), 'url' => url('/company/supply')]];
        $this->out(implode("\n", $L), $b, $mid);
    }

    public function queueItem(int $srId, ?int $mid): void
    {
        $sr = SupplyRequest::of($this->ws->id)->find($srId);
        if (! $sr || ! Supply::canDecide($this->u)) {
            $this->queue($mid);

            return;
        }
        $b = match ($sr->status) {
            'new' => [[['text' => '✅ '.__('Согласовать'), 'data' => 'sa:'.$sr->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'sn:'.$sr->id]]],
            'approved' => [[['text' => '🏢 '.__('Поставщик и цены → заказать'), 'data' => 'so:'.$sr->id]]],
            'ordered' => [[['text' => '🚚 '.__('Машина в пути'), 'data' => 'st:'.$sr->id]], [['text' => '🏢 '.__('Сменить поставщика'), 'data' => 'so:'.$sr->id]]],
            default => [],
        };
        $b[] = [['text' => '⬅️ '.__('Очередь'), 'data' => 'sq:0'], ['text' => __('Открыть'), 'url' => url('/company/supply')]];
        $this->out(Supply::card($sr, true), $b, $mid);
    }

    public function suppliers(int $srId, ?int $mid): void
    {
        $sr = SupplyRequest::of($this->ws->id)->find($srId);
        if (! $sr || ! Supply::canDecide($this->u)) {
            return;
        }
        // сначала те, у кого уже заказывали
        $used = SupplyRequest::of($this->ws->id)->whereNotNull('counterparty_id')->selectRaw('counterparty_id, count(*) n')->groupBy('counterparty_id')->pluck('n', 'counterparty_id');
        $list = Counterparty::of($this->ws->id)->where('status', '!=', 'blocked')->whereIn('kind', ['supplier', 'contractor'])->get()
            ->sortByDesc(fn ($c) => $used[$c->id] ?? 0)->take(10);
        $b = $list->map(fn ($c) => [['text' => Str::limit($c->name, 40), 'data' => 'sp:'.$sr->id.':'.$c->id]])->values()->all();
        $b[] = [['text' => '➕ '.__('Другой поставщик — на сайте'), 'url' => url('/company/supply')]];
        $b[] = [['text' => '⬅️ '.__('Назад'), 'data' => 'sq:'.$sr->id]];
        $this->out('🏢 '.__('У кого заказываем по заявке №:n?', ['n' => $sr->number]), $b, $mid);
    }

    public function pickSupplier(int $srId, int $cpId, ?int $mid): void
    {
        $sr = SupplyRequest::of($this->ws->id)->with('lines.item')->find($srId);
        $cp = Counterparty::of($this->ws->id)->find($cpId);
        if (! $sr || ! $cp || ! Supply::canDecide($this->u)) {
            return;
        }
        $this->flow(['k' => 'price', 'sr' => $sr->id, 'cp' => $cp->id]);
        $L = ['🏢 <b>'.e($cp->name).'</b>', __('Напишите цены за единицу через запятую — по порядку позиций:')];
        foreach ($sr->lines as $k => $l) {
            $L[] = ($k + 1).'. '.e($l->item?->name).' — '.$this->n($l->qty).' '.e($this->unit($l->item)).($l->price > 0 ? ' <i>('.__('было').' '.$this->n($l->price).')</i>' : '');
        }
        $L[] = '<i>'.__('Например: 85000; 120000').'</i>';
        $this->out(implode("\n", $L), [[['text' => '⏭ '.__('Заказать без цен'), 'data' => 'spx:'.$sr->id]]], $mid);
    }

    public function orderWithPrices(int $srId, ?string $text, ?int $mid): void
    {
        $f = (array) ($this->u->tg_state['flow'] ?? []);
        $sr = SupplyRequest::of($this->ws->id)->find($srId);
        if (! $sr || ($f['k'] ?? '') !== 'price' || (int) $f['sr'] !== $sr->id) {
            return;
        }
        $prices = [];
        if ($text !== null) {
            $parts = preg_split('/\s*[;\n]\s*|,\s+/u', trim($text));
            $nums = array_map(fn ($p) => (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $p), array_values(array_filter($parts, fn ($p) => preg_match('/\d/', $p))));
            foreach ($sr->lines()->get()->values() as $k => $l) {
                if (isset($nums[$k])) {
                    $prices[$l->id] = $nums[$k];
                }
            }
            if (! $prices) {
                $this->out('🤔 '.__('Не нашёл цен. Напишите числа через точку с запятой: 85000; 120000'));

                return;
            }
        }
        $this->flow(null);
        $ok = Supply::order($sr, $this->u, (int) $f['cp'], $prices);
        $this->out(($ok ? '🛒 '.__('Заказано. Прораб получил уведомление.') : __('Не удалось — заявка уже в другом статусе.'))."\n\n".Supply::card($sr->fresh(), true),
            $ok ? [[['text' => '🚚 '.__('Машина в пути'), 'data' => 'st:'.$sr->id]], [['text' => '⬅️ '.__('Очередь'), 'data' => 'sq:0']]] : [], $mid);
    }

    public function transit(int $srId, ?int $mid): void
    {
        $sr = SupplyRequest::of($this->ws->id)->find($srId);
        $ok = $sr && Supply::transit($sr, $this->u);
        $this->out(($ok ? '🚚 '.__('Отмечено: в пути. Прораб получил кнопку «Принял».') : __('Не удалось — заявка уже в другом статусе.'))."\n\n".($sr ? Supply::card($sr->fresh(), true) : ''),
            [[['text' => '⬅️ '.__('Очередь'), 'data' => 'sq:0']]], $mid);
    }

    /* ================================================================ уведомления */

    /**
     * Материал заканчивается на объекте: прорабам объекта (кнопка «Заказать») и снабжению — раз в день по товару.
     */
    public static function lowAlert(Workspace $ws, string $pid, int $itemId): void
    {
        if (! Telegram::enabled() || ! Cache::add('tg-low:'.$ws->id.':'.$pid.':'.$itemId.':'.$ws->localNow()->toDateString(), 1, now()->addDay())) {
            return;
        }
        $r = Catalog::stock($ws, $pid, Catalog::warehouseOrMain($ws, $pid))[$itemId] ?? null;
        if (! $r) {
            return;
        }
        $name = StateWriter::projects($ws)[$pid] ?? '';
        foreach ($ws->users()->where('is_active', true)->whereNotNull('telegram_chat_id')->get() as $u) {
            $site = $u->role === 'foreman' && ($u->allowedObjects() === null || in_array($pid, $u->allowedObjects(), true));
            if (! $site && ! Supply::canDecide($u)) {
                continue;
            }
            self::inLocale($u, function () use ($u, $r, $name, $site, $itemId) {
                $unit = Supply::unit($r['item']->unit);
                $txt = '⚠️ <b>'.e($name).'</b>: '.__('заканчивается').' <b>'.e($r['item']->name).'</b> — '.Catalog::n($r['qty']).' '.e($unit)
                    .($r['days'] !== null ? ' (~'.__(':n дн.', ['n' => $r['days']]).')' : '');
                try {
                    Telegram::message($u->telegram_chat_id, $txt, $site ? [[['text' => '🛒 '.__('Заказать'), 'data' => 'm:l:'.$itemId]]] : [[['text' => __('Открыть'), 'url' => url('/company/stock/items/'.$itemId)]]]);
                } catch (\Throwable $e) {
                    Log::warning('Telegram low alert: '.$e->getMessage());
                }
            });
        }
    }

    /** По кнопке из предупреждения: в корзину — сколько нужно на 2 недели */
    public function lowOrder(int $itemId, ?int $mid): void
    {
        $pid = $this->pid();
        $r = $pid ? (Catalog::stock($this->ws, $pid, Catalog::warehouseOrMain($this->ws, $pid))[$itemId] ?? null) : null;
        if (! $r) {
            $this->home($mid);

            return;
        }
        $q = $this->suggest($r) ?? (float) $this->quick($r['item'])[1];
        $this->cartSet($r['item'], $q);
        $this->cart($mid, __('В корзине').': '.e($r['item']->name).' — '.$this->n($q).' '.e($this->unit($r['item'])));
    }

    private static function inLocale(User $u, callable $fn): void
    {
        $prev = app()->getLocale();
        app()->setLocale($u->botLocale());
        try {
            $fn();
        } finally {
            app()->setLocale($prev);
        }
    }

    private function moveFile(string $path, string $dir): ?string
    {
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        if (str_starts_with($path, $dir.'/')) {
            return $path;
        }
        $to = $dir.'/'.basename($path);
        Storage::disk('local')->move($path, $to);

        return $to;
    }
}
