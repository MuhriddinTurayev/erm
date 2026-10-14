<?php

namespace App\Services;

use App\Models\BuyerLink;
use App\Models\Expense;
use App\Models\SiteLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiService;
use App\Services\Erp\Supply;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Бот платформы для сотрудников компаний:
 *  — «Бетон 25 млн БетонСтрой» → расход в кассу объекта (или на согласование руководителю);
 *  — фото чека → ИИ читает сумму → расход с приложенным чеком;
 *  — фото и текст с объекта → журнал работ;
 *  — /kassa — деньги компании сейчас; /obyekt — выбрать объект; кнопки «Согласовать / Отклонить».
 */
class TelegramBot
{
    public function __construct(private ExpenseService $expenses, private AiService $ai) {}

    /**
     * Обновление пришло впервые? Telegram повторяет доставку вебхука при медленном ответе,
     * опрос мог получить то же самое — один update_id обрабатываем только один раз (сутки помним).
     */
    public static function firstTime(array $u): bool
    {
        $id = $u['update_id'] ?? null;

        return $id === null || \Illuminate\Support\Facades\Cache::add('tg-upd:'.$id, 1, now()->addDay());
    }

    public function handle(array $u): void
    {
        try {
            if (isset($u['callback_query'])) {
                $this->onCallback($u['callback_query']);
            } elseif (isset($u['message'])) {
                $this->onMessage($u['message']);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram bot: '.$e->getMessage(), ['update' => $u['update_id'] ?? null]);
        }
    }

    /* ---------------- сообщения ---------------- */

    private function onMessage(array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private') {
            return;
        }
        $chat = (string) $m['chat']['id'];
        $text = trim((string) (($m['text'] ?? '') !== '' ? $m['text'] : ($m['caption'] ?? '')));

        if (preg_match('/^\/start(?:\s+(\S+))?/u', $text, $mm) && ! empty($mm[1]) && $this->link($chat, $mm[1], $m)) {
            return;
        }
        $user = User::where('telegram_chat_id', $chat)->where('is_active', true)->whereNotNull('workspace_id')->first();
        if (! $user) {
            $this->say($chat, "👋 ".__('Здравствуйте! Чтобы работать с ботом, привяжите Telegram на сайте: меню пользователя → «Telegram».')."\n".url('/me/telegram'));

            return;
        }
        $this->as($user, function () use ($user, $chat, $text, $m) {
            $ws = $user->workspace;
            if (! $ws->hasAccess()) {
                $this->say($chat, '⚠️ '.__('Срок подписки истёк. Продлите тариф, чтобы продолжить работу.'));

                return;
            }
            if (preg_match('/^\/(start|help|yordam)\b/u', $text)) {
                $this->help($chat, $user);

                return;
            }
            if (preg_match('/^\/(obyekt|object|объект)\b/u', $text)) {
                $this->askProject($user, $chat, null);

                return;
            }
            if (preg_match('/^\/(kassa|касса|cash)\b/u', $text)) {
                $this->cash($user, $chat);

                return;
            }
            if (preg_match('/^\/(bekor|cancel|отмена)\b/u', $text)) {
                $user->forceFill(['tg_state' => null])->save();
                $this->say($chat, '👌 '.__('Отменено'));

                return;
            }
            // заявка на материалы: /zayavka, затем список
            if (preg_match('/^\/(zayavka|buyurtma|zakaz|заявка|заказ|order)\b/u', $text, $zm)) {
                if (! $this->canSupply($user, $chat)) {
                    return;
                }
                $rest = trim(mb_substr($text, mb_strlen($zm[0])));
                if ($rest !== '') {
                    $this->supplyDraft($user, $chat, $rest);

                    return;
                }
                $user->forceFill(['tg_state' => ['supply' => true]])->save();
                $this->say($chat, '📦 <b>'.__('Заявка на материалы').'</b>'."\n".__('Напишите, что нужно — каждую позицию с новой строки, с количеством:')
                    ."\n\n<i>".__('Цемент М400 — 50 мешков')."\n".__('Песок 3 камаза')."\n".__('Арматура 12 мм 2 т')."\n".__('завтра')."</i>\n\n"
                    .__('Срок можно указать словом «завтра» или датой «15.10». /bekor — отменить'));

                return;
            }
            // как сдать отчёт за день
            if (preg_match('/^\/(hisobot|otchet|отчет|отчёт|report)\b/u', $text)) {
                $this->say($chat, '📓 <b>'.__('Отчёт за день').'</b>'."\n".__('Пришлите фото с объекта (можно сразу несколько, альбомом) и напишите, что сделано: какие работы, сколько людей работало, что мешает. Всё попадёт в журнал работ, руководитель увидит это в ежедневном отчёте.'));

                return;
            }
            // фото
            $photo = $m['photo'] ?? null;
            $doc = $m['document'] ?? null;
            if ($photo || ($doc && str_starts_with((string) ($doc['mime_type'] ?? ''), 'image/'))) {
                $fileId = $photo ? end($photo)['file_id'] : $doc['file_id'];
                $path = $this->store($user, $fileId, 'inbox');
                $state = (array) ($user->tg_state ?? []);
                $group = $m['media_group_id'] ?? null;
                // альбом: остальные фото того же альбома добавляем к первому, второй раз не спрашиваем
                if ($group && ($state['group'] ?? null) === $group && ! empty($state['photo'])) {
                    $state['photos'] = array_values(array_merge((array) ($state['photos'] ?? [$state['photo']]), [$path]));
                    if ($text !== '' && empty($state['text'])) {
                        $state['text'] = $text;
                    }
                    $user->forceFill(['tg_state' => $state])->save();

                    return;
                }
                $parsed = $text !== '' ? ExpenseService::parse($text) : null;
                if ($parsed && ! $group) {
                    $this->withProject($user, $chat, ['do' => 'expense', 'data' => $parsed, 'receipt' => $path]);
                } else {
                    $user->forceFill(['tg_state' => ['photo' => $path, 'photos' => [$path], 'group' => $group, 'text' => $text]])->save();
                    $this->say($chat, '📷 '.__('Что это за фото?'), [[
                        ['text' => '🧾 '.__('Чек — расход'), 'data' => 'ph:exp'], ['text' => '📓 '.__('Журнал работ'), 'data' => 'ph:log'],
                    ]]);
                }

                return;
            }
            if ($text === '') {
                return;
            }
            $state = (array) ($user->tg_state ?? []);
            // ждали список для заявки — или сообщение начинается со слова «заявка:» / «kerak:»
            if (! empty($state['supply'])) {
                $this->supplyDraft($user, $chat, $text);

                return;
            }
            if (preg_match('/^(zayavka|buyurtma|заявка|заказ|kerak|нужно)\s*[:\n]\s*(.+)$/isu', $text, $zm)) {
                if ($this->canSupply($user, $chat)) {
                    $this->supplyDraft($user, $chat, $zm[2]);
                }

                return;
            }
            $parsed = ExpenseService::parse($text);
            if (! empty($state['await_amount'])) {     // ждали сумму к фото чека
                if (! $parsed) {
                    $this->say($chat, __('Напишите сумму, например: «2 500 000» или «2,5 млн». /bekor — отменить'));

                    return;
                }
                $user->forceFill(['tg_state' => null])->save();
                $this->withProject($user, $chat, ['do' => 'expense', 'data' => $parsed, 'receipt' => $state['await_amount']]);

                return;
            }
            if ($parsed) {
                $this->withProject($user, $chat, ['do' => 'expense', 'data' => $parsed]);

                return;
            }
            $this->withProject($user, $chat, ['do' => 'log', 'text' => $text]);
        });
    }

    /** /start КОД: сотрудник, чат отчёта руководителя или покупатель */
    private function link(string $chat, string $code, array $m): bool
    {
        if (str_starts_with($code, 'b_')) {
            $bl = BuyerLink::where('token', substr($code, 2))->first();
            if (! $bl) {
                return false;
            }
            $bl->forceFill(['tg_chat_id' => $chat])->save();
            $this->as(null, fn () => $this->say($chat, '✅ '.__('Готово! Сюда будут приходить напоминания об оплате и новости стройки. Ваш кабинет:')."\n".$bl->url()), $bl->workspace?->locale);

            return true;
        }
        $user = User::where('tg_link_code', strtoupper($code))->first();
        if ($user) {
            User::where('telegram_chat_id', $chat)->whereKeyNot($user->id)->update(['telegram_chat_id' => null]);
            $user->forceFill(['telegram_chat_id' => $chat, 'tg_link_code' => null, 'tg_state' => null])->save();
            $this->as($user, function () use ($user, $chat) {
                $this->say($chat, '✅ '.__('Telegram привязан: :n', ['n' => e($user->name)]));
                $this->help($chat, $user);
            });

            return true;
        }
        $ws = Workspace::where('report_link_code', strtoupper($code))->first();
        if ($ws) {
            $chats = array_filter(explode(',', (string) $ws->report_chats));
            if (! in_array($chat, $chats, true)) {
                $chats[] = $chat;
            }
            $ws->forceFill(['report_chats' => implode(',', $chats), 'report_link_code' => strtoupper(Str::random(6))])->save();
            $this->as(null, fn () => $this->say($chat, '✅ '.__('Telegram подключён. Ежедневный отчёт компании «:c» будет приходить сюда.', ['c' => e($ws->name)])), $ws->locale);

            return true;
        }

        return false;
    }

    private function help(string $chat, User $user): void
    {
        $proj = $user->tg_project ? (StateWriter::projects($user->workspace)[$user->tg_project] ?? null) : null;
        $lines = [
            '🤖 <b>'.__('Что умеет бот').'</b>',
            '',
            '💸 '.__('Расход — просто напишите: «Бетон 25 млн БетонСтрой» или «солярка 850 000 АЗС».'),
            '🧾 '.__('Чек — пришлите фото, ИИ сам прочитает сумму.'),
            '📓 '.__('Журнал работ — пришлите фото с объекта или напишите, что сделано за день.'),
        ];
        if ($user->canEdit() && $user->canModule('supply')) {
            $lines[] = '📦 '.__('Заявка на материалы — /zayavka и список: «Цемент М400 50 мешков», «Песок 3 камаза», «завтра».');
        }
        array_push($lines, '', '/obyekt — '.__('выбрать объект').($proj ? ' ('.e($proj).')' : ''), '/hisobot — '.__('как сдать отчёт за день'));
        if ($user->canEdit() && $user->canModule('supply')) {
            $lines[] = '/zayavka — '.__('заявка на материалы');
        }
        if ($user->seesMoney()) {
            $lines[] = '/kassa — '.__('деньги компании сейчас');
        }
        $lines[] = '/bekor — '.__('отменить');
        $this->say($chat, implode("\n", $lines));
    }

    private function cash(User $user, string $chat): void
    {
        if (! $user->seesMoney()) {
            $this->say($chat, __('Нет доступа к этому разделу'));

            return;
        }
        $s = CompanyStats::build($user->workspace, null, $user->allowedObjects());
        $T = $s['totals'];
        $c = ' '.__($s['currency']);
        $m = fn ($v) => CompanyStats::short((float) $v).$c;
        $L = ['💰 <b>'.__('Деньги на счету (касса)').': '.$m($T['balance']).'</b>',
            '📉 '.__('Потрачено сегодня').': '.$m($T['out_day']), '📉 '.__('Потрачено вчера').': '.$m($T['out_yest']), '📈 '.__('Поступило сегодня').': '.$m($T['in_day'])];
        foreach (array_slice($s['cats_day'], 0, 4, true) as $cat => $v) {
            $L[] = '• '.e(__($cat)).' — '.$m($v);
        }
        $pending = Expense::where('workspace_id', $user->workspace_id)->where('status', 'pending')->count();
        if ($pending) {
            $L[] = '';
            $L[] = '⏳ '.__('Ждут согласования').': '.$pending;
        }
        $this->say($chat, implode("\n", $L));
    }

    /* ---------------- заявка на материалы ---------------- */

    private function canSupply(User $user, string $chat): bool
    {
        if ($user->canEdit() && $user->canModule('supply')) {
            return true;
        }
        $this->say($chat, __('Нет доступа к этому разделу'));

        return false;
    }

    /** Разобрать список и показать, что получилось: «Отправить» / «Исправить» / «Отмена» */
    private function supplyDraft(User $user, string $chat, string $text): void
    {
        $d = Supply::parse($text, $user->workspace->localNow());
        if (! $d['lines']) {
            $user->forceFill(['tg_state' => ['supply' => true]])->save();
            $this->say($chat, '🤔 '.__('Не нашёл позиций с количеством. Напишите каждую с новой строки, например: «Цемент М400 — 50 мешков». /bekor — отменить'));

            return;
        }
        // «solyarka 850 000 AYoQSh» — это расход, а не материал: предложим записать его расходом
        $exp = Supply::expenseLike($d, $text);
        $user->forceFill(['tg_state' => ['supply_draft' => $d + ['exp' => $exp]]])->save();
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
        $L = ['📦 <b>'.__('Проверьте заявку').'</b>'];
        foreach ($d['lines'] as $i => $l) {
            $L[] = ($i + 1).'. '.e($l['name']).' — <b>'.$n($l['qty']).' '.e(Supply::unit($l['unit'])).'</b>';
        }
        if ($d['need_by']) {
            $L[] = '📅 '.__('Нужно к').': <b>'.Carbon::parse($d['need_by'])->format('d.m.Y').'</b>';
        }
        if ($d['note'] !== '') {
            $L[] = '✏️ '.e($d['note']);
        }
        $rows = [[['text' => '✅ '.__('Отправить'), 'data' => 'sr:send'], ['text' => '✏️ '.__('Исправить'), 'data' => 'sr:edit'], ['text' => '✖ '.__('Отмена'), 'data' => 'sr:cancel']]];
        if ($exp) {
            $L[] = '';
            $L[] = '💸 '.__('Похоже на расход: :s. Если вы потратили деньги, а не заказываете материал, нажмите «Это расход».', ['s' => '<b>'.CompanyStats::money($exp['amount']).'</b>']);
            array_unshift($rows, [['text' => '💸 '.__('Это расход'), 'data' => 'sr:exp']]);
        }
        $this->say($chat, implode("\n", $L), $rows);
    }

    /* ---------------- объект ---------------- */

    /** Выполнить действие для объекта сотрудника; если объект не выбран — спросить */
    private function withProject(User $user, string $chat, array $action): void
    {
        $projects = StateWriter::projects($user->workspace, $user->allowedObjects());
        $pid = $user->tg_project && isset($projects[$user->tg_project]) ? $user->tg_project : (count($projects) === 1 ? array_key_first($projects) : null);
        if (! $pid) {
            $this->askProject($user, $chat, $action);

            return;
        }
        $this->run($user, $chat, $pid, $action);
    }

    private function askProject(User $user, string $chat, ?array $action): void
    {
        $projects = StateWriter::projects($user->workspace, $user->allowedObjects());
        if (! $projects) {
            $this->say($chat, __('В компании ещё нет объектов'));

            return;
        }
        $user->forceFill(['tg_state' => $action ? ['pending' => $action] : null])->save();
        $rows = array_map(fn ($id, $name) => [['text' => ($id === $user->tg_project ? '✓ ' : '').mb_substr($name, 0, 40), 'data' => 'obj:'.$id]], array_keys($projects), $projects);
        $this->say($chat, '🏗 '.__('Выберите объект:'), array_slice($rows, 0, 30));
    }

    private function run(User $user, string $chat, string $pid, array $action): void
    {
        $ws = $user->workspace;
        if ($action['do'] === 'expense') {
            if (! $user->canEdit()) {
                $this->say($chat, __('Только просмотр'));

                return;
            }
            $d = $action['data'];
            $receipt = $action['receipt'] ?? null;
            if ($receipt) {
                $receipt = $this->move($receipt, "receipts/{$ws->id}");
            }
            $e = $this->expenses->create($ws, $user, $pid, ['amount' => $d['amount'], 'desc' => $d['desc'] ?? '', 'who' => $d['who'] ?? '', 'date' => $d['date'] ?? null], 'telegram', $receipt);
            $note = $e->status === 'pending' ? "\n\n⏳ ".__('Сумма больше :l — отправлено руководителю на согласование.', ['l' => CompanyStats::money($ws->approval_limit)]) : '';
            $this->say($chat, ExpenseService::card($e, $ws).$note, [[['text' => '🏷 '.__('Изменить статью'), 'data' => 'cat:'.$e->id]]]);

            return;
        }
        if ($action['do'] === 'supply') {
            $d = (array) $action['draft'];
            $sr = Supply::create($ws, $user, $pid, (array) $d['lines'], $d['need_by'] ?? null, ($d['note'] ?? '') ?: null, 'telegram');
            $this->say($chat, Supply::card($sr)."\n\n".($sr->status === 'new' ? '⏳ '.__('Отправлено на согласование. Ответ придёт сюда.') : '✅ '.__('Заявка согласована — снабжение получит её сразу.')));

            return;
        }
        if ($action['do'] === 'log') {
            // одно фото или весь альбом
            $photos = array_values(array_unique(array_filter(array_merge([$action['photo'] ?? null], (array) ($action['photos'] ?? [])))));
            $photos = array_map(fn ($ph) => $this->move($ph, "logs/{$ws->id}"), $photos);
            SiteLog::create(['workspace_id' => $ws->id, 'project_id' => $pid, 'user_id' => $user->id, 'date' => $ws->localNow()->toDateString(),
                'text' => ($action['text'] ?? '') ?: null, 'photos' => $photos ?: null, 'source' => 'telegram']);
            $proj = StateWriter::projects($ws)[$pid] ?? '';
            $this->say($chat, '📓 '.__('Записано в журнал работ').' · '.e($proj).($photos ? ' · 📷 '.count($photos) : ''));
        }
    }

    /* ---------------- кнопки ---------------- */

    private function onCallback(array $q): void
    {
        $chat = (string) ($q['message']['chat']['id'] ?? '');
        $data = (string) ($q['data'] ?? '');
        $user = User::where('telegram_chat_id', $chat)->where('is_active', true)->whereNotNull('workspace_id')->first();
        if (! $user) {
            Telegram::answer($q['id'], __('Сначала привяжите Telegram на сайте'));

            return;
        }
        $this->as($user, function () use ($user, $chat, $data, $q) {
            $ws = $user->workspace;
            // подписка закончилась или компания заблокирована — кнопки (согласования, оплаты) не работают
            if (! $ws || ! $ws->hasAccess()) {
                Telegram::answer($q['id'], __('Срок подписки истёк. Продлите тариф, чтобы продолжить работу.'));

                return;
            }
            [$cmd, $arg] = array_pad(explode(':', $data, 2), 2, '');
            if ($cmd === 'obj') {
                if (! isset(StateWriter::projects($ws, $user->allowedObjects())[$arg])) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $pending = $user->tg_state['pending'] ?? null;
                $user->forceFill(['tg_project' => $arg, 'tg_state' => null])->save();
                Telegram::answer($q['id'], '✓');
                Telegram::edit($chat, (int) $q['message']['message_id'], '🏗 '.__('Объект').': <b>'.e(StateWriter::projects($ws)[$arg]).'</b>');
                if ($pending) {
                    $this->run($user, $chat, $arg, $pending);
                }

                return;
            }
            if ($cmd === 'ph') {
                $st = (array) ($user->tg_state ?? []);
                if (empty($st['photo'])) {
                    Telegram::answer($q['id'], __('Фото уже обработано'));

                    return;
                }
                Telegram::answer($q['id']);
                Telegram::edit($chat, (int) $q['message']['message_id'], $arg === 'exp' ? '🧾 '.__('Читаю чек…') : '📓 '.__('Журнал работ'));
                if ($arg === 'log') {
                    $user->forceFill(['tg_state' => null])->save();
                    $this->withProject($user, $chat, ['do' => 'log', 'photo' => $st['photo'], 'photos' => (array) ($st['photos'] ?? []), 'text' => $st['text'] ?? '']);

                    return;
                }
                $full = Storage::disk('local')->path($st['photo']);
                $r = is_file($full) ? $this->ai->readReceipt($ws, $user, file_get_contents($full), mime_content_type($full) ?: 'image/jpeg') : null;
                if ($r) {
                    $user->forceFill(['tg_state' => null])->save();
                    $this->withProject($user, $chat, ['do' => 'expense', 'data' => $r + ['cat' => null], 'receipt' => $st['photo']]);
                } else {
                    $user->forceFill(['tg_state' => ['await_amount' => $st['photo']]])->save();
                    $this->say($chat, __('Не удалось прочитать сумму на чеке. Напишите сумму и что купили, например: «Цемент 4 500 000 СементСавдо».'));
                }

                return;
            }
            if ($cmd === 'sr') {   // черновик заявки: отправить / исправить / отмена
                $draft = $user->tg_state['supply_draft'] ?? null;
                if ($arg === 'cancel' || ! $draft) {
                    $user->forceFill(['tg_state' => null])->save();
                    Telegram::answer($q['id'], $draft ? '👌' : __('Заявка уже отправлена'));
                    Telegram::edit($chat, (int) $q['message']['message_id'], $draft ? '✖ '.__('Заявка отменена') : '📦 '.__('Заявка уже отправлена'));

                    return;
                }
                if ($arg === 'edit') {
                    $user->forceFill(['tg_state' => ['supply' => true]])->save();
                    Telegram::answer($q['id']);
                    $this->say($chat, '✏️ '.__('Напишите список заново — каждую позицию с новой строки.'));

                    return;
                }
                if ($arg === 'exp') {   // это был расход, а не заявка
                    if (empty($draft['exp'])) {
                        Telegram::answer($q['id']);

                        return;
                    }
                    $user->forceFill(['tg_state' => null])->save();
                    Telegram::answer($q['id'], '✓');
                    Telegram::edit($chat, (int) $q['message']['message_id'], '💸 '.__('Записываю как расход…'));
                    $this->withProject($user, $chat, ['do' => 'expense', 'data' => $draft['exp']]);

                    return;
                }
                if (! $user->canEdit() || ! $user->canModule('supply')) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $user->forceFill(['tg_state' => null])->save();   // повторное нажатие не создаст вторую заявку
                Telegram::answer($q['id'], '✓');
                Telegram::edit($chat, (int) $q['message']['message_id'], '📦 '.__('Отправляю заявку…'));
                $this->withProject($user, $chat, ['do' => 'supply', 'draft' => $draft]);

                return;
            }
            if ($cmd === 'sa' || $cmd === 'sn') {   // заявка на снабжение: согласовать / отклонить
                $sr = \App\Models\SupplyRequest::of($ws->id)->find((int) $arg);
                if (! $sr || ! Supply::canDecide($user)) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $ok = Supply::decide($sr, $user, $cmd === 'sa');
                Telegram::answer($q['id'], $ok ? ($cmd === 'sa' ? '✅ '.__('Согласовано') : '❌ '.__('Отклонено')) : __('Уже решено'));
                Telegram::edit($chat, (int) $q['message']['message_id'], Supply::card($sr->fresh()), [[['text' => __('Открыть'), 'url' => url('/company/supply')]]]);

                return;
            }
            if ($cmd === 'ap' || $cmd === 'rj') {
                $e = Expense::where('workspace_id', $ws->id)->find((int) $arg);
                if (! $e || ! $user->isAdmin()) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $ok = $cmd === 'ap' ? $this->expenses->approve($e, $user) : $this->expenses->reject($e, $user);
                Telegram::answer($q['id'], $ok ? ($cmd === 'ap' ? '✅ '.__('Согласовано') : '❌ '.__('Отклонено')) : __('Уже решено'));

                return;
            }
            if ($cmd === 'pa' || $cmd === 'pn') {   // заявка на оплату: согласовать / отклонить
                $pr = \App\Models\PaymentRequest::of($ws->id)->find((int) $arg);
                if (! $pr) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $ok = \App\Services\Erp\Payments::decide($pr, $user, $cmd === 'pa');
                Telegram::answer($q['id'], $ok ? ($cmd === 'pa' ? '✅ '.__('Согласовано') : '❌ '.__('Отклонено')) : __('Сейчас решение по заявке принимает другой сотрудник'));

                return;
            }
            if ($cmd === 'cat' || $cmd === 'setcat') {
                [$id, $ci] = array_pad(explode('|', $arg, 2), 2, null);
                $e = Expense::where('workspace_id', $ws->id)->find((int) $id);
                if (! $e || ($e->user_id !== $user->id && ! $user->isAdmin())) {
                    Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

                    return;
                }
                $cats = array_keys(ExpenseService::CATS);
                $cats[] = 'Офис и прочее';
                if ($cmd === 'cat') {
                    Telegram::answer($q['id']);
                    $rows = array_chunk(array_map(fn ($c, $i) => ['text' => __($c), 'data' => 'setcat:'.$e->id.'|'.$i], $cats, array_keys($cats)), 2);
                    Telegram::edit($chat, (int) $q['message']['message_id'], ExpenseService::card($e, $ws), $rows);

                    return;
                }
                $cat = $cats[(int) $ci] ?? $e->cat;
                $e->forceFill(['cat' => $cat])->save();
                if ($e->tx_id) {
                    StateWriter::mutate($ws->id, function (array &$d) use ($e, $cat) {
                        foreach ($d['projects'] as &$p) {
                            if (($p['id'] ?? '') === $e->project_id) {
                                foreach ($p['tx'] as &$t) {
                                    if (($t['id'] ?? '') === $e->tx_id) {
                                        $t['cat'] = $cat;
                                    }
                                }
                            }
                        }
                    });
                }
                Telegram::answer($q['id'], '✓ '.__($cat));
                Telegram::edit($chat, (int) $q['message']['message_id'], ExpenseService::card($e->fresh(), $ws), [[['text' => '🏷 '.__('Изменить статью'), 'data' => 'cat:'.$e->id]]]);
            }
        });
    }

    /* ---------------- служебное ---------------- */

    private function say(string $chat, string $html, array $buttons = []): void
    {
        try {
            Telegram::message($chat, $html, $buttons);
        } catch (\Throwable $e) {
            Log::warning('Telegram send: '.$e->getMessage());
        }
    }

    private function store(User $user, string $fileId, string $dir): string
    {
        [$bytes, $ext] = Telegram::download($fileId);
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
        $path = "$dir/{$user->workspace_id}/".now()->format('Ymd_His').'_'.Str::random(6).'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    private function move(string $path, string $dir): string
    {
        if (str_starts_with($path, $dir.'/')) {
            return $path;
        }
        $to = $dir.'/'.basename($path);
        Storage::disk('local')->move($path, $to);

        return $to;
    }

    private function as(?User $user, callable $fn, ?string $locale = null)
    {
        $prev = App::getLocale();
        App::setLocale($user?->locale ?: $user?->workspace?->locale ?: $locale ?: 'ru');
        try {
            return $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
