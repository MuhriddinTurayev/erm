<?php

namespace App\Services\Bot;

use App\Models\Item;
use App\Models\StockMove;
use App\Models\Warehouse;
use App\Models\Workspace;

/**
 * Материалы для Telegram-бота: разделы (цемент, металл, кирпич…), остаток на складе объекта,
 * средний расход в день и «хватит на ~3 дня», последний приход. Поиск товара по словам прораба.
 */
class Catalog
{
    /** Разделы: ключ => [название, значок, слова в названии товара] — на трёх языках и как пишут на стройке */
    public const CATS = [
        'fuel' => ['Топливо (бензин, дизель)', '⛽', ['бензин', 'benzin', 'дизел', 'dizel', 'солярк', 'solyarka', 'топлив', "yoqilg'i", 'yoqilgi', 'сӯзишворӣ', 'пропан', 'propan', 'метан', 'moy ', 'масло']],
        'cement' => ['Цемент и смеси', '🪣', ['цемент', 'семент', 'sement', 'tsement', 'смес', 'qorishma', 'қоришма', 'раствор', 'клей', 'yelim', 'гипс', 'gips', 'шпатл', 'шпакл', 'штукат', 'suvoq', 'затирк', 'грунтовк', 'бетон', 'beton', 'ангидрит']],
        'metal' => ['Арматура и металл', '🔩', ['арматур', 'armatur', 'металл', 'metall', 'профил', 'profil', 'швеллер', 'shveller', 'уголок', 'burchak', 'балк', 'электрод', 'elektrod', 'сетк', 'setka', 'проволок', 'болт', 'bolt', 'гвозд', 'mix ', 'анкер', 'ankir']],
        'brick' => ['Кирпич и блоки', '🧱', ['кирпич', "g'isht", 'gisht', 'ғишт', 'хишт', 'блок', 'blok', 'газобл', 'gazobl', 'пенобл', 'penobl', 'шлакобл']],
        'sand' => ['Песок и щебень', '⛰️', ['песок', 'qum', 'рег', 'щеб', "shag'al", 'shagal', 'шағал', 'гравий', 'gravi', 'керамзит', 'keramzit', 'грунт', 'tuproq']],
        'wood' => ['Древесина', '🪵', ['доск', 'taxta', 'тахта', 'брус', 'brus', 'фанер', 'fanera', 'osb', 'дсп', 'древес', "yog'och", 'ёғоч', 'рейк', 'опалуб', 'qolip']],
        'electric' => ['Электрика', '⚡', ['кабел', 'kabel', 'провод', 'sim ', 'автомат', 'avtomat', 'розет', 'rozetka', 'выключ', 'светил', 'lampa', 'лампа', 'электр', 'elektr', 'гофр', 'gofr']],
        'plumbing' => ['Сантехника', '🚰', ['ppr', 'quvur', 'труб', 'кран ', 'смесит', 'унитаз', 'unitaz', 'раковин', 'сантех', 'santex', 'фитинг', 'fiting', 'насос', 'nasos', 'канализ', 'kanaliz', 'radiator', 'радиатор']],
        'other' => ['Прочее', '📦', []],
    ];

    /** Старые названия групп склада → разделы бота */
    private const GROUPS = ['металл' => 'metal', 'стенов' => 'brick', 'электр' => 'electric', 'сантех' => 'plumbing', 'топлив' => 'fuel', 'древес' => 'wood', 'сыпуч' => 'sand'];

    /** Синонимы: прораб пишет «цемент», а в справочнике «M400 sement» */
    private const SYN = [
        'цемент' => 'sement', 'семент' => 'sement', 'арматура' => 'armatura', 'песок' => 'qum', 'рег' => 'qum', 'щебень' => "shag'al", 'шағал' => "shag'al",
        'газоблок' => 'gazoblok', 'кирпич' => "g'isht", 'хишт' => "g'isht", 'ғишт' => "g'isht", 'кабель' => 'kabel', 'труба' => 'quvur', 'электрод' => 'elektrod',
        'клей' => 'yelim', 'солярка' => 'solyarka', 'дизель' => 'dizel', 'бензин' => 'benzin', 'доска' => 'taxta',
    ];

    public static function norm(string $s): string
    {
        return mb_strtolower(str_replace(['ʻ', 'ʼ', '‘', '’', '`', '´'], "'", $s));
    }

    public static function catOf(Item $i): string
    {
        $name = ' '.self::norm($i->name).' ';
        foreach (self::CATS as $k => [, , $words]) {
            foreach ($words as $w) {
                if ($w !== '' && str_contains($name, $w)) {
                    return $k;
                }
            }
        }
        $g = self::norm((string) $i->group);
        foreach (self::GROUPS as $w => $k) {
            if ($g !== '' && str_contains($g, $w)) {
                return $k;
            }
        }

        return 'other';
    }

    /** Склад объекта (кладовая на площадке); если у объекта своего склада нет — null */
    public static function warehouse(Workspace $ws, ?string $pid): ?Warehouse
    {
        return $pid ? Warehouse::of($ws->id)->where('project_id', $pid)->where('is_active', true)->orderBy('id')->first() : null;
    }

    /** Склад для бота: кладовая объекта, иначе центральный склад компании */
    public static function warehouseOrMain(Workspace $ws, ?string $pid): Warehouse
    {
        \App\Services\Erp\Stock::ensureDefaults($ws);

        return self::warehouse($ws, $pid) ?? Warehouse::of($ws->id)->whereNull('project_id')->where('is_active', true)->orderBy('id')->first() ?? Warehouse::of($ws->id)->orderBy('id')->first();
    }

    /**
     * Материалы объекта: остаток на его складе, расход в день (14 дней), на сколько дней хватит, последний приход.
     *
     * @return array<int, array{item: Item, cat: string, qty: float, day: float, days: ?int, last: ?string, low: bool}>
     */
    public static function stock(Workspace $ws, ?string $pid, ?Warehouse $wh = null): array
    {
        $wh ??= self::warehouse($ws, $pid);
        $from = $ws->localNow()->subDays(14)->toDateString();
        $qty = $wh ? StockMove::of($ws->id)->where('warehouse_id', $wh->id)->selectRaw('item_id, sum(qty) q')->groupBy('item_id')->pluck('q', 'item_id') : collect();
        // расход: выдачи на объект и со склада объекта
        $use = StockMove::of($ws->id)->where('type', 'out')->where('date', '>=', $from)
            ->where(fn ($q) => $q->when($pid, fn ($x) => $x->where('project_id', $pid))->when($wh, fn ($x) => $x->orWhere('warehouse_id', $wh->id)))
            ->selectRaw('item_id, sum(-qty) q')->groupBy('item_id')->pluck('q', 'item_id');
        $last = $wh ? StockMove::of($ws->id)->where('warehouse_id', $wh->id)->where('qty', '>', 0)->selectRaw('item_id, max(date) d')->groupBy('item_id')->pluck('d', 'item_id') : collect();
        $out = [];
        foreach (Item::of($ws->id)->orderBy('name')->get() as $i) {
            $q = round((float) ($qty[$i->id] ?? 0), 3);
            $day = round((float) ($use[$i->id] ?? 0) / 14, 3);
            $days = $day > 0 ? (int) floor(max(0, $q) / $day) : null;
            $low = $days !== null ? $days <= 3 : ($i->min_qty > 0 && $q > 0 && $q < $i->min_qty * 0.3);
            $out[$i->id] = ['item' => $i, 'cat' => self::catOf($i), 'qty' => $q, 'day' => $day, 'days' => $days, 'last' => $last[$i->id] ?? null, 'low' => $low];
        }

        return $out;
    }

    /** Товар по словам («sement», «цемент м400», «armatura 12») — лучший по совпавшим словам, иначе null */
    public static function find(Workspace $ws, string $text): ?Item
    {
        $q = self::norm($text);
        $tokens = array_values(array_filter(preg_split("/[^\p{L}\p{N}']+/u", $q), fn ($t) => mb_strlen($t) >= 2));
        foreach ($tokens as $t) {
            if (isset(self::SYN[$t])) {
                $tokens[] = self::SYN[$t];
            }
        }
        if (! $tokens) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach (Item::of($ws->id)->get() as $i) {
            $name = self::norm($i->name);
            $score = 0;
            foreach (array_unique($tokens) as $t) {
                if (mb_strlen($t) >= 3 && str_contains($name, mb_substr($t, 0, max(3, mb_strlen($t) - 2)))) {
                    $score += mb_strlen($t) >= 4 ? 2 : 1;
                } elseif (preg_match('/\d/', $t) && str_contains($name, $t)) {
                    $score += 2;   // «М400», «12» — марка и диаметр
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$i, $score];
            }
        }

        return $bestScore >= 2 ? $best : null;
    }

    /** Число без лишних нулей: 12 / 2,5 */
    public static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }
}
