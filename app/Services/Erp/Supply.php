<?php

namespace App\Services\Erp;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\SupplyRequest;
use App\Models\SupplyLine;
use App\Models\User;
use App\Models\Workspace;
use App\Services\StateWriter;
use App\Services\Telegram;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Заявки на снабжение: с сайта и из Telegram-бота — одни правила.
 * Прораб пишет, что нужно → руководитель или бухгалтер согласует (в том числе кнопкой в Telegram) →
 * снабжение заказывает у поставщика → склад принимает. Автор заявки получает в Telegram каждый шаг.
 */
class Supply
{
    public const STATUS = ['new' => 'новая', 'approved' => 'согласована', 'ordered' => 'заказана', 'partial' => 'принята частично', 'received' => 'принята', 'rejected' => 'отклонена'];

    /** Единицы, которые узнаём после количества: «50 qop», «2 т», «3 kamaz», «20 kv.m», «50ta» */
    private const UNITS = ['шт', 'штук', 'штуки', 'штука', 'dona', 'ta', 'qop', 'мешок', 'мешка', 'мешков', 'т', 'тн', 'тонна', 'тонны', 'тонн', 'tonna', 't', 'кг', 'kg',
        'г', 'gr', 'м', 'метр', 'метра', 'метров', 'metr', 'm', 'м2', 'м²', 'кв.м', 'kv.m', 'kvm', 'm2', 'm²', 'м3', 'м³', 'куб', 'куба', 'кубов', 'kub', 'm3', 'm³',
        'л', 'литр', 'литра', 'литров', 'l', 'litr', 'пачка', 'пачки', 'пачек', 'pachka', 'рулон', 'рулона', 'рулонов', 'rulon', 'лист', 'листа', 'листов', 'list',
        'комплект', 'комплекта', 'komplekt', 'рейс', 'рейса', 'рейсов', 'reys', 'машина', 'машины', 'машин', 'mashina', 'kamaz', 'камаз', 'камаза', 'упак', 'упаковка',
        'упаковки', 'quti', 'коробка', 'коробки', 'korobka', 'бочка', 'bochka', 'ведро', 'ведра', 'chelak', 'пог.м', 'п.м', 'пм', 'pog.m', 'paket', 'пакет', 'пакета',
        'балон', 'баллон', 'ballon', 'banka', 'банка', 'банки', 'juft', 'пара', 'пары', 'm.p', 'mp',
        // таджикский
        'халта', 'халтаи', 'дона', 'кило', 'мошин', 'мошина', 'мошинаи', 'қуттӣ', 'варақ', 'ҷуфт', 'литр', 'бочка'];

    /**
     * Разобрать список из сообщения: по строке на позицию («Цемент М400 — 50 мешков», «qum 3 kamaz»), строки без количества —
     * в примечание, «завтра / ertaga / 15.10» — срок. Возвращает [lines => [[name, qty, unit]], need_by => Y-m-d|null, note => string].
     */
    public static function parse(string $text, Carbon $today): array
    {
        $lines = [];
        $note = [];
        $need = null;
        $when = ['ertaga' => 1, 'завтра' => 1, 'пагоҳ' => 1, 'indinga' => 2, 'послезавтра' => 2, 'пасфардо' => 2, 'bugun' => 0, 'сегодня' => 0, 'имрӯз' => 0];
        // строки; строку с запятыми делим, только если это список («цемент 50 мешков, песок 3 камаза»), а не описание («Цемент М500, мешок 50 кг — 120 мешков»)
        $qtyRx = '/(?<![\p{L}\d.,])\d/u';
        $parts = [];
        foreach (preg_split('/[\r\n;]+/u', $text) as $row) {
            $bits = preg_split('/,\s+(?=\D)/u', $row);
            $list = count($bits) > 1 && count(array_filter($bits, fn ($b) => preg_match($qtyRx, $b))) === count($bits);
            array_push($parts, ...($list ? $bits : [$row]));
        }
        foreach ($parts as $raw) {
            $line = self::cut($raw);
            if ($line === '') {
                continue;
            }
            // срок внутри строки: «… ertaga», «завтра нужно»
            if (preg_match('/(?<![\p{L}])(ertaga|завтра|пагоҳ|indinga|послезавтра|пасфардо|bugun|сегодня|имрӯз)(?![\p{L}])(\s+(kerak|нужно|надо|gacha|до|лозим|даркор))?/iu', $line, $w)) {
                $need = $today->copy()->addDays($when[mb_strtolower($w[1])] ?? 1)->toDateString();
                $line = self::cut(preg_replace('/'.preg_quote($w[0], '/').'/u', '', $line, 1));
                if ($line === '') {
                    continue;
                }
            }
            // строка-дата: «15.10», «до 15.10.2026», «20/10 gacha»
            if (preg_match('/^(?:до\s+|by\s+)?(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?(?:\s+(?:gacha|kerak|до|нужно))?$/iu', $line, $d)) {
                $y = isset($d[3]) ? ((int) $d[3] < 100 ? 2000 + (int) $d[3] : (int) $d[3]) : (int) $today->year;
                if (checkdate((int) $d[2], (int) $d[1], $y)) {
                    $need = sprintf('%04d-%02d-%02d', $y, $d[2], $d[1]);
                }

                continue;
            }
            preg_match_all('/(?<![\p{L}\d.,])(\d{1,3}(?:[ \x{00A0}]\d{3})+|\d+(?:[.,]\d+)?)\s*([\p{L}²³][\p{L}²³.]*)?/u', $line, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            if (! $mm) {
                $note[] = $line;

                continue;
            }
            // количество — последнее число с понятной единицей, иначе просто последнее число
            $pick = null;
            foreach (array_reverse($mm) as $m) {
                $u = isset($m[2]) ? mb_strtolower($m[2][0]) : '';
                if ($u !== '' && (in_array($u, self::UNITS, true) || in_array(rtrim($u, '.'), self::UNITS, true))) {
                    $pick = $m;
                    break;
                }
            }
            $known = (bool) $pick;
            $pick ??= end($mm);
            $qty = (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $pick[1][0]);
            $unit = $known ? rtrim($pick[2][0], '.') : '';
            $cut = $known ? $pick[0][0] : $pick[1][0];
            $start = $known ? $pick[0][1] : $pick[1][1];
            $name = self::cut(preg_replace('/\s{2,}/u', ' ', substr($line, 0, $start).' '.substr($line, $start + strlen($cut))), '×');
            if ($name === '' || $qty <= 0) {
                $note[] = $line;

                continue;
            }
            $lines[] = ['name' => mb_substr($name, 0, 160), 'qty' => $qty, 'unit' => mb_substr($unit ?: 'шт', 0, 16)];
        }

        return ['lines' => array_slice($lines, 0, 60), 'need_by' => $need, 'note' => mb_substr(implode('; ', $note), 0, 300)];
    }

    /**
     * Убрать по краям пробелы, тире, точки, маркеры списка. Не trim(): он режет по байтам, и «—», «·», «×» в списке символов
     * обрезали бы хвост кириллической буквы («камаз» → «кама�»).
     */
    private static function cut(string $s, string $extra = ''): string
    {
        return preg_replace('/^[\s\-–—•*·.:,'.preg_quote($extra, '/').']+|[\s\-–—•*·.:,'.preg_quote($extra, '/').']+$/u', '', $s);
    }

    /** Создать заявку. $lines — [[name, qty, unit]]. Руководитель создаёт сразу согласованной. */
    public static function create(Workspace $ws, User $u, ?string $pid, array $lines, ?string $needBy, ?string $note, string $source = 'site'): SupplyRequest
    {
        $sr = DB::transaction(function () use ($ws, $u, $pid, $lines, $needBy, $note) {
            $sr = SupplyRequest::create(['workspace_id' => $ws->id, 'number' => (int) SupplyRequest::of($ws->id)->lockForUpdate()->max('number') + 1, 'project_id' => $pid,
                'user_id' => $u->id, 'need_by' => $needBy, 'note' => $note ?: null, 'status' => $u->isAdmin() ? 'approved' : 'new']);
            foreach ($lines as $l) {
                $name = trim((string) $l['name']);
                // тот же материал, как бы его ни написали («цемент м400» = «Цемент М400»)
                $item = Item::of($ws->id)->where('name', $name)->first() ?? Item::of($ws->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                    ?? Item::create(['workspace_id' => $ws->id, 'name' => $name, 'unit' => ($l['unit'] ?? '') ?: 'шт']);
                SupplyLine::create(['supply_request_id' => $sr->id, 'item_id' => $item->id, 'qty' => (float) $l['qty'], 'price' => (float) $item->price]);
            }

            return $sr;
        });
        AuditLog::create(['workspace_id' => $ws->id, 'user_id' => $u->id, 'project' => StateWriter::projects($ws)[$pid] ?? null, 'action' => 'supply.add',
            'summary' => 'Заявка на снабжение №'.$sr->number.' · позиций: '.count($lines).($source === 'telegram' ? ' · Telegram' : '')]);
        if ($sr->status === 'new') {
            self::askApproval($sr);
        }

        return $sr;
    }

    /** Кто согласует заявки: руководитель и бухгалтер */
    public static function canDecide(User $u): bool
    {
        return $u->canEdit() && ($u->isAdmin() || $u->role === 'accountant');
    }

    /** Согласовать / отклонить. false — уже решено или нет права */
    public static function decide(SupplyRequest $sr, User $u, bool $ok): bool
    {
        if (! self::canDecide($u) || (int) $sr->workspace_id !== (int) $u->workspace_id) {
            return false;
        }
        $done = SupplyRequest::whereKey($sr->id)->where('status', 'new')->update(['status' => $ok ? 'approved' : 'rejected', 'updated_at' => now()]);
        if (! $done) {
            return false;
        }
        $sr->refresh();
        AuditLog::create(['workspace_id' => $sr->workspace_id, 'user_id' => $u->id, 'action' => 'supply.edit', 'summary' => 'Заявка на снабжение №'.$sr->number.': '.$sr->status]);
        self::notifyAuthor($sr, $u);

        return true;
    }

    /** Текст заявки для Telegram */
    public static function card(SupplyRequest $sr): string
    {
        $sr->loadMissing('lines.item', 'user', 'counterparty');
        $ws = Workspace::find($sr->workspace_id);
        $n = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
        $L = ['📦 <b>'.__('Заявка на снабжение').' №'.$sr->number.'</b>'.($sr->project_id && $ws ? ' · '.e(StateWriter::projects($ws)[$sr->project_id] ?? '') : '')];
        foreach ($sr->lines as $l) {
            $L[] = '• '.e($l->item?->name ?? '—').' — <b>'.$n($l->qty).' '.e($l->item?->unit ?? '').'</b>'.($l->received > 0 ? ' ('.__('принято').' '.$n($l->received).')' : '');
        }
        if ($sr->need_by) {
            $L[] = '📅 '.__('Нужно к').': <b>'.$sr->need_by->format('d.m.Y').'</b>';
        }
        if ($sr->note) {
            $L[] = '✏️ '.e($sr->note);
        }
        if ($sr->counterparty) {
            $L[] = '🏢 '.__('Поставщик').': '.e($sr->counterparty->name);
        }
        $L[] = '👤 '.e($sr->user?->name ?? '—').' · '.__('статус').': <b>'.__(self::STATUS[$sr->status] ?? $sr->status).'</b>';

        return implode("\n", $L);
    }

    /** Руководителю и бухгалтеру: новая заявка — кнопки «Согласовать / Отклонить» прямо в Telegram */
    private static function askApproval(SupplyRequest $sr): void
    {
        if (! Telegram::enabled()) {
            return;
        }
        $ws = Workspace::find($sr->workspace_id);
        foreach ($ws->users()->whereIn('role', ['admin', 'accountant'])->where('is_active', true)->whereNotNull('telegram_chat_id')->get() as $a) {
            self::as($a, fn () => self::send($a->telegram_chat_id, self::card($sr), [
                [['text' => '✅ '.__('Согласовать'), 'data' => 'sa:'.$sr->id], ['text' => '❌ '.__('Отклонить'), 'data' => 'sn:'.$sr->id]],
                [['text' => __('Открыть'), 'url' => url('/company/supply')]],
            ]));
        }
    }

    /** Автору заявки: что с ней произошло (согласована, отклонена, заказана, принята) */
    public static function notifyAuthor(SupplyRequest $sr, ?User $by = null): void
    {
        $author = $sr->user;
        if (! Telegram::enabled() || ! $author || ! $author->telegram_chat_id || ($by && $by->id === $author->id)) {
            return;
        }
        $icon = ['approved' => '✅', 'rejected' => '❌', 'ordered' => '🚚', 'partial' => '📥', 'received' => '📥'][$sr->status] ?? '📦';
        self::as($author, fn () => self::send($author->telegram_chat_id, $icon.' '.self::card($sr).($by ? "\n".__('Решение').': '.e($by->name) : '')));
    }

    private static function send(string $chat, string $html, array $buttons = []): void
    {
        try {
            Telegram::message($chat, $html, $buttons);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** На языке получателя */
    private static function as(User $u, callable $fn): void
    {
        $prev = app()->getLocale();
        app()->setLocale($u->locale ?: $u->workspace?->locale ?: 'ru');
        try {
            $fn();
        } finally {
            app()->setLocale($prev);
        }
    }
}
