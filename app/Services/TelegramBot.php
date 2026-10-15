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
            if (preg_match('/^\/(start|til|lang|язык|забон)\b/u', $text)) {
                $this->askLang($chat, 'hello');

                return;
            }
            $this->as(null, fn () => $this->hello($chat), self::chatLang($chat));

            return;
        }
        $this->as($user, function () use ($user, $chat, $text, $m) {
            $ws = $user->workspace;
            if (! $ws->hasAccess()) {
                $this->say($chat, '⚠️ '.__('Срок подписки истёк. Продлите тариф, чтобы продолжить работу.'));

                return;
            }
            if (preg_match('/^\/(start|til|lang|язык|забон)\b/u', $text)) {
                $this->askLang($chat, 'help');

                return;
            }
            if (preg_match('/^\/(help|yordam|помощь|ёрдам)\b/u', $text)) {
                $this->help($chat, $user);

                return;
            }
            if (preg_match('/^\/(menu|menyu|меню)\b/u', $text)) {
                $this->sendMenu($user, $chat, '👇 '.__('Разделы — в меню внизу экрана.'));

                return;
            }
            // кнопка меню внизу экрана
            if ($key = \App\Services\Bot\Sections::match($text)) {
                $user->forceFill(['tg_state' => null])->save();
                $this->section($user, $chat, $key);

                return;
            }
            // шаг диалога: количество, поиск, комментарий, приёмка, цены
            $st0 = (array) ($user->tg_state ?? []);
            if (! empty($st0['flow']) && ! str_starts_with($text, '/')) {
                $photo0 = $m['photo'] ?? null;
                $doc0 = $m['document'] ?? null;
                $path0 = null;
                if (($photo0 || ($doc0 && str_starts_with((string) ($doc0['mime_type'] ?? ''), 'image/'))) && in_array($st0['flow']['k'] ?? '', ['note', 'note_ph', 'rcv_photo'], true)) {
                    $path0 = $this->store($user, $photo0 ? end($photo0)['file_id'] : $doc0['file_id'], 'inbox');
                }
                if (($text !== '' || $path0) && $this->flowInput($user, $chat, $st0['flow'], $text, $path0)) {
                    return;
                }
            }
            if (preg_match('/^\/(material|materiallar|homashyo|материалы)\b/u', $text)) {
                $this->canSupply($user, $chat) && $this->materials($user, $chat)->home();

                return;
            }
            if (preg_match('/^\/(sarf|rasxod|расход)\b/u', $text)) {
                $M = $this->materials($user, $chat);
                $M->canUse() ? $M->useHome(null) : $this->say($chat, __('Нет доступа к этому разделу'));

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
            // без команды: «sement 20 qop kerak» — заявка на материалы, «20 qop sement ishlatildi» — списание; всё — после подтверждения кнопкой
            $need = '/(?<!\p{L})(kerak|керак|нужно|надо|нужен|нужна|лозим|даркор|zarur)(?!\p{L})/iu';
            if (empty($state['await_amount']) && $user->canEdit() && $user->canModule('supply') && preg_match($need, $text)) {
                $clean = trim(preg_replace($need, '', $text));
                $d = Supply::parse($clean, $user->workspace->localNow());
                if ($d['lines'] && ! Supply::expenseLike($d, $clean) && collect($d['lines'])->contains(fn ($l) => empty($l['guess']))) {
                    $this->supplyDraft($user, $chat, $clean);

                    return;
                }
            }
            $M = $this->materials($user, $chat);
            if (empty($state['await_amount']) && $M->canUse() && preg_match('/(ishlatil|ishlatdi|sarflan|sarfladi|израсход|использовал|истифода|ушло на)/iu', $text)) {
                $d = Supply::parse(preg_replace('/(?<!\p{L})\p{L}*(ishlatil|ishlatdi|sarflan|sarfladi|израсход|использовал|истифода)\p{L}*/iu', '', $text), $user->workspace->localNow());
                if ($d['lines'] && $M->proposeUse($d['lines'][0])) {
                    return;
                }
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
            if (! $user->tg_locale && ($l = self::chatLang($chat))) {   // язык уже выбрали до привязки
                $user->forceFill(['tg_locale' => $l])->save();
            }
            $this->as($user, function () use ($user, $chat) {
                $this->say($chat, '✅ '.__('Telegram привязан: :n', ['n' => e($user->name)]));
                $this->askLang($chat, 'help');
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

    /* ---------------- язык бота ---------------- */

    private const LANGS = ['uz' => "🇺🇿 O'zbekcha", 'ru' => '🇷🇺 Русский', 'tg' => '🇹🇯 Тоҷикӣ'];

    /** Язык, выбранный в чате до привязки к сотруднику */
    private static function chatLang(string $chat): ?string
    {
        $l = \Illuminate\Support\Facades\Cache::get('tg-lang:'.$chat);

        return isset(self::LANGS[$l]) ? $l : null;
    }

    /** Выбор языка: вопрос сразу на трёх языках — поймёт любой */
    private function askLang(string $chat, string $then): void
    {
        $this->say($chat, "🌐 <b>Tilni tanlang · Выберите язык · Забонро интихоб кунед</b>",
            [array_map(fn ($l, $name) => ['text' => $name, 'data' => 'lang:'.$l.':'.$then], array_keys(self::LANGS), self::LANGS)]);
    }

    /** Нажали язык: запомнить (у сотрудника — в профиле бота, у незнакомого чата — по чату) и продолжить на нём */
    private function setLang(array $q, string $chat, string $data, ?User $user): void
    {
        [, $l] = array_pad(explode(':', $data, 3), 3, '');   // третья часть (help / hello) — что показать после выбора; решает наличие сотрудника
        if (! isset(self::LANGS[$l])) {
            Telegram::answer($q['id']);

            return;
        }
        \Illuminate\Support\Facades\Cache::put('tg-lang:'.$chat, $l, now()->addYear());
        if ($user) {
            $user->forceFill(['tg_locale' => $l])->save();
        }
        $this->as($user, function () use ($q, $chat, $user, $l) {
            Telegram::answer($q['id'], '✅ '.self::LANGS[$l]);
            if (! empty($q['message']['message_id'])) {
                Telegram::edit($chat, (int) $q['message']['message_id'], '🌐 '.__('Язык бота').': <b>'.self::LANGS[$l].'</b>');
            }
            if ($user && $user->workspace?->hasAccess()) {
                $this->help($chat, $user);
            } elseif (! $user) {
                $this->hello($chat);
            }
        }, $l);
    }

    /** Чат ещё не привязан к сотруднику */
    private function hello(string $chat): void
    {
        $this->say($chat, '👋 '.__('Здравствуйте! Чтобы работать с ботом, привяжите Telegram на сайте: меню пользователя → «Telegram».')."\n".url('/me/telegram'));
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
            $lines[] = '🧱 '.__('Материалы — остаток на объекте, корзина и заявка в пару нажатий. Или просто напишите: «sement 20 qop kerak».');
            $lines[] = '📤 '.__('Расход — «сегодня использовали 20 мешков цемента», остаток склада обновится.');
            $lines[] = '📥 '.__('Материал привезли — нажмите «Принял», укажите количество и пришлите накладную.');
        }
        if (\App\Services\Erp\Supply::canDecide($user)) {
            $lines[] = '✅ '.__('Заявки на материалы и оплату согласуете кнопками, поставщика и цены выбираете прямо здесь.');
        }
        array_push($lines, '', '/obyekt — '.__('выбрать объект').($proj ? ' ('.e($proj).')' : ''), '/hisobot — '.__('как сдать отчёт за день'));
        if ($user->canEdit() && $user->canModule('supply')) {
            $lines[] = '/material — '.__('материалы и корзина');
            $lines[] = '/zayavka — '.__('заявка на материалы списком');
            $lines[] = '/sarf — '.__('расход материала');
        }
        if ($user->seesMoney()) {
            $lines[] = '/kassa — '.__('деньги компании сейчас');
        }
        $lines[] = '/bekor — '.__('отменить');
        $lines[] = '/til — '.__('язык бота: узбекский, русский, таджикский');
        $lines[] = '';
        $lines[] = '👇 '.__('Разделы — в меню внизу экрана.');
        $this->sendMenu($user, $chat, implode("\n", $lines));
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
        $L = ['💰 <b>'.__('Деньги на счету (касса)').': '.CompanyStats::money((float) $T['balance']).$c.'</b>',
            '📉 '.__('Потрачено сегодня').': '.$m($T['out_day']), '📉 '.__('Потрачено вчера').': '.$m($T['out_yest']), '📈 '.__('Поступило сегодня').': '.$m($T['in_day'])];
        foreach (array_slice($s['cats_day'], 0, 4, true) as $cat => $v) {
            $L[] = '• '.e(__($cat)).' — '.$m($v);
        }
        $pending = Expense::where('workspace_id', $user->workspace_id)->where('status', 'pending')->count();
        if ($pending) {
            $L[] = '';
            $L[] = '⏳ '.__('Ждут согласования').': '.$pending;
        }
        // заявки на оплату утверждены, но ещё не оплачены: деньги ещё на счету, в «Потрачено» их нет
        $ws = $user->workspace;
        $allowed = $user->allowedObjects();
        $toPay = \App\Models\PaymentRequest::of($ws->id)->where('status', 'approved')->when($allowed !== null, fn ($q) => $q->whereIn('project_id', $allowed))->get();
        $buttons = [];
        if ($toPay->count()) {
            $sum = $toPay->sum(fn ($pr) => \App\Services\Erp\Money::toBase($ws, (float) $pr->amount, (string) $pr->currency));
            $next = $toPay->whereNotNull('due_date')->sortBy('due_date')->first();
            $L[] = '';
            $L[] = '💳 '.__('Утверждено к оплате, ещё не оплачено').': '.$toPay->count().' · <b>'.$m($sum).'</b>'.($next ? ' · '.__('ближайший срок').' '.$next->due_date->format('d.m') : '');
            $L[] = '<i>'.(\App\Services\Erp\Payments::canPay($user) ?__('В «Потрачено сегодня» попадёт, когда оплатите — кнопкой ниже или на сайте.') : __('В «Потрачено сегодня» попадёт, когда кассир оплатит.')).'</i>';
            if (\App\Services\Erp\Payments::canPay($user)) {
                $buttons[] = [['text' => '💵 '.__('Оплатить').' ('.$toPay->count().')', 'data' => 'pl:0']];
            }
        }
        $this->say($chat, implode("\n", $L), $buttons);
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
        // строки — к товарам справочника («sement» → «M400 sement, 50 kg qop»): заявка ляжет на те же позиции склада
        foreach ($d['lines'] as &$l) {
            if ($hit = \App\Services\Bot\Catalog::find($user->workspace, $l['name'])) {
                $l['item_id'] = $hit->id;
                $l['match'] = $hit->name;
            }
        }
        unset($l);
        $user->forceFill(['tg_state' => ['supply_draft' => $d + ['exp' => $exp]]])->save();
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
        $L = ['📦 <b>'.__('Проверьте заявку').'</b>'];
        foreach ($d['lines'] as $i => $l) {
            $L[] = ($i + 1).'. '.e($l['name']).' — <b>'.$n($l['qty']).' '.e(Supply::unit($l['unit'])).'</b>'.(! empty($l['match']) && $l['match'] !== $l['name'] ? ' → <i>'.e($l['match']).'</i>' : '');
        }
        if ($d['need_by']) {
            $L[] = '📅 '.__('Нужно к').': <b>'.Carbon::parse($d['need_by'])->format('d.m.Y').'</b>';
        }
        if ($d['note'] !== '') {
            $L[] = '✏️ '.e($d['note']);
        }
        $rows = [[['text' => '✅ '.__('Отправить'), 'data' => 'sr:send'], ['text' => '🛒 '.__('В корзину'), 'data' => 'sr:cart']],
            [['text' => '✏️ '.__('Исправить'), 'data' => 'sr:edit'], ['text' => '✖ '.__('Отмена'), 'data' => 'sr:cancel']]];
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
        if (in_array($action['do'], ['materials', 'cart', 'use'], true)) {   // объект выбран — продолжаем раздел
            $M = $this->materials($user, $chat);
            match ($action['do']) { 'materials' => $M->home(), 'cart' => $M->cart(), default => $M->useHome(null) };

            return;
        }
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
        if (str_starts_with($data, 'lang:')) {
            $this->setLang($q, $chat, $data, $user);

            return;
        }
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
            // материалы, расход, приёмка, снабжение — свои кнопки
            if (in_array($cmd, ['m', 'u', 'rc', 'sq', 'so', 'sp', 'spx', 'st', 'sec'], true)) {
                $this->materialsCallback($user, $chat, $cmd, $arg, $q);

                return;
            }
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
                if ($arg === 'cart') {   // в корзину: можно добавить ещё материалы, дату, «срочно», фото
                    $user->forceFill(['tg_state' => null])->save();
                    Telegram::answer($q['id'], '🛒');
                    $this->materials($user, $chat)->addLines((array) $draft['lines'], (int) $q['message']['message_id']);

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
                // согласовал — сразу можно выбрать поставщика и заказать
                Telegram::edit($chat, (int) $q['message']['message_id'], Supply::card($sr->fresh(), true), array_values(array_filter([
                    $ok && $cmd === 'sa' ? [['text' => '🏢 '.__('Поставщик и цены → заказать'), 'data' => 'so:'.$sr->id]] : null,
                    [['text' => __('Открыть'), 'url' => url('/company/supply')]],
                ])));

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
                if ($ok && $pr->fresh()->status === 'approved' && \App\Services\Erp\Payments::canPay($user)) {
                    $this->say($chat, '✅ '.__('Заявка №:n утверждена. Деньги уйдут из кассы, когда её оплатят.', ['n' => $pr->number]), [[['text' => '💵 '.__('Оплатить сейчас'), 'data' => 'pp:'.$pr->id]]]);
                }

                return;
            }
            if (in_array($cmd, ['pl', 'pp', 'pd', 'pc', 'px'], true)) {   // оплата утверждённой заявки: список → касса → подтверждение
                $this->payCallback($user, $chat, $cmd, $arg, $q);

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

    /* ---------------- материалы и разделы по ролям ---------------- */

    private function materials(User $user, string $chat): \App\Services\Bot\Materials
    {
        return new \App\Services\Bot\Materials($user, $chat, fn (array $action) => $this->askProject($user, $chat, $action));
    }

    /** Меню внизу экрана — разделы по роли сотрудника */
    private function sendMenu(User $user, string $chat, string $html): void
    {
        try {
            Telegram::menu($chat, $html, \App\Services\Bot\Sections::menu($user), __('Напишите или выберите раздел'));
        } catch (\Throwable $e) {
            Log::warning('Telegram menu: '.$e->getMessage());
            $this->say($chat, $html);
        }
    }

    /** Нажата кнопка меню */
    /** Оплата утверждённой заявки из Telegram: та же проверка и та же операция, что «Оплатить» на сайте */
    private function payCallback(User $user, string $chat, string $cmd, string $arg, array $q): void
    {
        $ws = $user->workspace;
        $mid = (int) ($q['message']['message_id'] ?? 0);
        if (! \App\Services\Erp\Payments::canPay($user)) {
            Telegram::answer($q['id'], __('Оплачивают руководитель, бухгалтер или кассир'));

            return;
        }
        if ($cmd === 'pl') {
            Telegram::answer($q['id']);
            (new \App\Services\Bot\Sections($user, $chat))->payToday();

            return;
        }
        if ($cmd === 'px') {
            Telegram::answer($q['id'], __('Отменено'));
            if ($mid) {
                Telegram::edit($chat, $mid, '✖ '.__('Оплата отменена'));
            }

            return;
        }
        [$id, $deskId] = array_map('intval', array_pad(explode(':', $arg), 2, 0));
        $pr = \App\Models\PaymentRequest::of($ws->id)->find($id);
        if (! $pr || $pr->status !== 'approved') {
            Telegram::answer($q['id'], __('Заявка уже оплачена или ещё не утверждена'));

            return;
        }
        $desks = \App\Services\Erp\Payments::desksFor($user);
        $money = fn ($v, $c) => \App\Services\Erp\Money::fmt((float) $v, (string) $c);
        if ($cmd === 'pp') {
            Telegram::answer($q['id']);
            if (! $desks->count()) {
                $this->say($chat, __('Нет кассы, из которой вы можете платить.'));

                return;
            }
            $bal = \App\Services\Erp\Cash::balances($ws);
            $rows = $desks->map(fn ($d) => [['text' => '🏦 '.__($d->name).' · '.$money($bal[$d->id] ?? 0, $d->currency), 'data' => 'pd:'.$pr->id.':'.$d->id]])->values()->all();
            $rows[] = [['text' => '✖ '.__('Отмена'), 'data' => 'px:'.$pr->id]];
            $this->say($chat, '💵 <b>'.__('Оплата').'</b>'."\n\n".\App\Services\Erp\Payments::card($pr)."\n\n".__('Из какой кассы платим?'), $rows);

            return;
        }
        $desk = $desks->firstWhere('id', $deskId);
        if (! $desk) {
            Telegram::answer($q['id'], __('Нет доступа к этому разделу'));

            return;
        }
        if ($cmd === 'pd') {
            Telegram::answer($q['id']);
            Telegram::edit($chat, $mid, '💵 <b>'.__('Оплатить заявку №:n?', ['n' => $pr->number]).'</b>'."\n".$money($pr->amount, $pr->currency).' · '.e(__($desk->name))."\n".e($pr->purpose),
                [[['text' => '✅ '.__('Да, оплатить'), 'data' => 'pc:'.$pr->id.':'.$desk->id], ['text' => '✖ '.__('Отмена'), 'data' => 'px:'.$pr->id]]]);

            return;
        }
        try {
            \App\Services\Erp\Payments::pay($ws, $user, $pr, (int) $desk->id, $ws->localNow()->toDateString());
        } catch (\DomainException $e) {
            Telegram::answer($q['id'], $e->getMessage());
            $this->say($chat, '⚠️ '.e($e->getMessage()));

            return;
        }
        Telegram::answer($q['id'], '💵 '.__('Оплачено'));
        if ($mid) {
            Telegram::edit($chat, $mid, '💵 <b>'.__('Оплачено').'</b>: '.__('заявка').' №'.$pr->number.' · '.$money($pr->amount, $pr->currency).' · '.e(__($desk->name)));
        }
        $user->unsetRelation('workspace');   // данные компании изменились (оплата записана в бухгалтерию объекта) — читаем заново
        $this->cash($user, $chat);   // касса после оплаты — сумма уже в «Потрачено сегодня»
    }

    private function section(User $user, string $chat, string $key): void
    {
        $M = $this->materials($user, $chat);
        $S = new \App\Services\Bot\Sections($user, $chat);
        $sup = fn () => $this->canSupply($user, $chat);
        match ($key) {
            'materials' => $sup() && $M->home(),
            'cart' => $sup() && $M->cart(),
            'myorders' => $M->orders(null),
            'orders' => \App\Services\Erp\Supply::canDecide($user) ? $M->queue(null) : $M->orders(null),
            'use' => $M->canUse() ? $M->useHome(null) : $this->say($chat, __('Нет доступа к этому разделу')),
            'report' => $this->say($chat, '📓 <b>'.__('Отчёт за день').'</b>'."\n".__('Пришлите фото с объекта (можно сразу несколько, альбомом) и напишите, что сделано: какие работы, сколько людей работало, что мешает. Всё попадёт в журнал работ, руководитель увидит это в ежедневном отчёте.')),
            'status' => $S->status(),
            'queue' => $S->queue(),
            'cash' => $this->cash($user, $chat),
            'pay' => $S->payToday(),
            'flats' => $S->flats(),
            'leads' => $S->leads(),
            default => $this->help($chat, $user),
        };
    }

    /**
     * Ввод на шаге диалога (количество, поиск, комментарий, приёмка, цены). true — сообщение обработано.
     * Если ввод не подходит к шагу (например, вместо числа написали расход), шаг сбрасывается и сообщение идёт дальше.
     */
    private function flowInput(User $user, string $chat, array $f, string $text, ?string $photo): bool
    {
        $M = $this->materials($user, $chat);
        $num = fn (string $t) => preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*[\p{L}.²³]*\s*$/u', $t, $x) ? (float) str_replace(',', '.', $x[1]) : null;
        switch ($f['k'] ?? '') {
            case 'qty':
                if ($text !== '' && ($q = $num($text)) !== null) {
                    $M->setQty((int) $f['item'], $q);

                    return true;
                }
                break;
            case 'use':
                if ($text !== '' && ($q = $num($text)) !== null) {
                    $M->useConfirm((int) $f['item'], $q, null);

                    return true;
                }
                break;
            case 'newitem':
                if ($text !== '' && ! $photo && ! str_starts_with($text, '/')) {
                    $M->newItem((string) ($f['cat'] ?? 'other'), $text, null);

                    return true;
                }
                break;
            case 'search':
                // слово без цифр — ищем материал; «Бетон 25 млн …» и прочее идёт дальше (расход, журнал)
                if ($text !== '' && ! $photo && ! preg_match('/\d/', $text) && mb_strlen($text) <= 40) {
                    $M->search($text);

                    return true;
                }
                break;
            case 'use_search':
                if ($text !== '' && ! $photo) {
                    if (preg_match('/\d/', $text)) {
                        $d = Supply::parse($text, $user->workspace->localNow());
                        if ($d['lines'] && $M->proposeUse($d['lines'][0])) {
                            return true;
                        }
                    } elseif ($i = \App\Services\Bot\Catalog::find($user->workspace, $text)) {
                        $M->useItem($i->id, null);

                        return true;
                    }
                }
                break;
            case 'note':
                if ($text !== '' || $photo) {
                    $M->addNote($text !== '' ? $text : null, $photo);

                    return true;
                }
                break;
            case 'note_ph':
                if ($photo) {
                    $M->addNote(null, $photo);

                    return true;
                }
                break;
            case 'rcv_qty':
                if ($text !== '') {
                    $M->receiveQtyText((int) $f['sr'], $text);

                    return true;
                }
                break;
            case 'rcv_photo':
                if ($photo) {
                    $M->receivePhoto($photo);

                    return true;
                }
                $this->say($chat, '📄 '.__('Пришлите фото накладной или нажмите «Без накладной — принять».'));

                return true;
            case 'price':
                if ($text !== '') {
                    $M->orderWithPrices((int) $f['sr'], $text, null);

                    return true;
                }
                break;
        }
        $user->forceFill(['tg_state' => null])->save();

        return false;
    }

    /** Кнопки разделов материалов, приёмки и снабжения */
    private function materialsCallback(User $user, string $chat, string $cmd, string $arg, array $q): void
    {
        $M = $this->materials($user, $chat);
        $mid = (int) ($q['message']['message_id'] ?? 0) ?: null;
        $a = explode(':', $arg);
        Telegram::answer($q['id']);
        $orderOnly = ['q', 'k', 'r', 'd', 'u', 'n', 's', 'z', 'p', 'pp', 'a', 'l', 'f'];
        if ($cmd === 'm' && in_array($a[0], $orderOnly, true) && ! ($user->canEdit() && $user->canModule('supply'))) {
            $this->say($chat, __('Нет доступа к этому разделу'));

            return;
        }
        match (true) {
            $cmd === 'm' && $a[0] === 'h' => $M->home($mid),
            $cmd === 'm' && $a[0] === 'c' => $M->category($a[1] ?? 'other', (int) ($a[2] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'i' => $M->item((int) ($a[1] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'q' => $M->setQty((int) ($a[1] ?? 0), (float) ($a[2] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'k' => $M->cart($mid),
            $cmd === 'm' && in_array($a[0], ['r', 'd', 'u', 'z'], true) => $M->cartAction($a[0], (string) ($a[1] ?? ''), $mid),
            $cmd === 'm' && $a[0] === 'n' => $M->askNote(),
            $cmd === 'm' && $a[0] === 's' => $M->submit($mid),
            $cmd === 'm' && $a[0] === 'f' => $M->favorites($mid),
            $cmd === 'm' && $a[0] === 'pp' => $M->repeatList($mid),
            $cmd === 'm' && $a[0] === 'p' => $M->repeat((int) ($a[1] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'o' => $M->orders($mid),
            $cmd === 'm' && $a[0] === 'oo' => $M->order((int) ($a[1] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'a' => $M->proposeAccept($mid),
            $cmd === 'm' && $a[0] === 'l' => $M->lowOrder((int) ($a[1] ?? 0), $mid),
            $cmd === 'm' && $a[0] === 'new' => $M->newItem((string) ($a[1] ?? 'other'), null, $mid),
            $cmd === 'm' && $a[0] === 'nq' => $M->newItem('other', (string) ((((array) $user->tg_state)['flow'] ?? [])['q'] ?? ''), $mid),
            $cmd === 'm' && $a[0] === 'nu' => $M->newItemUnit((int) ($a[1] ?? -1), $mid),
            $cmd === 'm' && $a[0] === 'sq' => $user->forceFill(['tg_state' => ['flow' => ['k' => 'search']]])->save() && $this->say($chat, '🔍 '.__('Напишите название материала — например «цемент» или «арматура 12».')),
            $cmd === 'm' => $M->home($mid),
            $cmd === 'u' && $a[0] === 'h' => $M->useHome($mid),
            $cmd === 'u' && $a[0] === 'hp' => $M->useHome($mid, (int) ($a[1] ?? 0)),
            $cmd === 'u' && $a[0] === 'i' => $M->useItem((int) ($a[1] ?? 0), $mid),
            $cmd === 'u' && $a[0] === 'q' => $M->useConfirm((int) ($a[1] ?? 0), (float) ($a[2] ?? 0), $mid),
            $cmd === 'u' && $a[0] === 'ok' => $M->useOk((int) ($a[1] ?? 0), (float) ($a[2] ?? 0), $mid),
            $cmd === 'rc' && $a[0] === 'a' => $M->receiveAll((int) ($a[1] ?? 0), $mid),
            $cmd === 'rc' && $a[0] === 'e' => $M->receiveAskQty((int) ($a[1] ?? 0), $mid),
            $cmd === 'rc' && $a[0] === 'n' => $M->receiveFinish((int) ($a[1] ?? 0), $mid),
            $cmd === 'rc' => $M->receiveStart((int) $a[0], null),
            $cmd === 'sq' => (int) $a[0] ? $M->queueItem((int) $a[0], $mid) : $M->queue($mid),
            $cmd === 'so' => $M->suppliers((int) $a[0], $mid),
            $cmd === 'sp' => $M->pickSupplier((int) $a[0], (int) ($a[1] ?? 0), $mid),
            $cmd === 'spx' => $M->orderWithPrices((int) $a[0], null, $mid),
            $cmd === 'st' => $M->transit((int) $a[0], $mid),
            $cmd === 'sec' && $a[0] === 'queue' => (new \App\Services\Bot\Sections($user, $chat))->queue(),
            default => null,
        };
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
        App::setLocale($user ? $user->botLocale() : ($locale ?: 'ru'));
        try {
            return $fn();
        } finally {
            App::setLocale($prev);
        }
    }
}
