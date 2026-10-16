<?php

namespace Database\Seeders;

use App\Models\BuyerLink;
use App\Models\CashDesk;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Counterparty;
use App\Models\FinCategory;
use App\Models\Item;
use App\Models\LeadInbox;
use App\Models\ObjectMedia;
use App\Models\Plan;
use App\Models\ServiceOrder;
use App\Models\SiteLog;
use App\Models\SupplyLine;
use App\Models\SupplyRequest;
use App\Models\Task;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Workspace;
use App\Services\Apartments;
use App\Services\Erp\Cash;
use App\Services\Erp\Payments;
use App\Services\Erp\Stock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Демо-компании для проверки всей системы как в жизни — каждая на своём языке:
 *  • «Navruz Qurilish» (Ташкент, узбекский, сумы) — 3 объекта, все роли, деньги, склад, снабжение, продажи, 3D;
 *  • «МосГрад Девелопмент» (Москва, русский, рубли) — ЖК «Северный парк» с подробной 3D-моделью квартала, интерьерами и панорамами 360°;
 *  • «Pamir Stroy» (Душанбе, таджикский) — новая компания на пробном периоде: проверка, что компании не видят друг друга.
 * Запуск: php artisan oftob:demo  (пересоздаёт только демо-компании, ваши данные не трогает)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo2026!';

    private array $creds = [];

    private int $shift = 0;

    private string $today;

    public function run(): void
    {
        $this->today = now()->toDateString();
        $this->cleanup();
        $src = json_decode(file_get_contents(__DIR__.'/demo/projects.json'), true);
        $this->shift = (int) Carbon::parse($src['generated'])->diffInDays(now()->startOfDay(), false);

        $a = $this->company('Navruz Qurilish', 'business', 'active', $src['navruz'], 'uz', 'Asia/Tashkent', '+998 71 200 10 10', 'сум');
        $this->full($a, $this->textsUz(), ['nvz-park', 'nvz-city', 'nvz-tower'], 'navruz', '+998 90 100 00');
        $this->media3dNavruz($a);

        $m = $this->company('МосГрад Девелопмент', 'corporate', 'active', $src['moscow'], 'ru', 'Europe/Moscow', '+7 495 120 45 67', 'руб');
        $this->full($m, $this->textsRu(), ['msk-sever', 'msk-reka', 'msk-sever'], 'mosgrad', '+7 916 100 00');
        $this->media3dMoscow($m);

        $b = $this->company('Pamir Stroy', 'business', 'trial', $src['pamir'], 'tg', 'Asia/Dushanbe', '+992 37 222 33 44', 'смн');
        $this->pamir($b);

        $this->report();
    }

    /* ======================== общее ======================== */

    private function cleanup(): void
    {
        foreach (Workspace::all() as $ws) {
            if (! $ws->setting('demo_seed')) {
                continue;
            }
            Storage::disk('local')->deleteDirectory('media/'.$ws->id);
            $ws->users()->delete();
            $ws->delete();
            $this->command?->warn("Удалена старая демо-компания: {$ws->name}");
        }
    }

    private function shiftDates(mixed $v): mixed
    {
        if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return Carbon::parse($v)->addDays($this->shift)->toDateString();
        }
        if (! is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $nk = is_string($k) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $k) ? Carbon::parse($k)->addDays($this->shift)->toDateString() : $k;
            $out[$nk] = $this->shiftDates($x);
        }

        return $out;
    }

    /** Кириллица → узбекская латиница (имена людей и т. п.) */
    public static function latin(string $s): string
    {
        $map = ['щ' => 'sh', 'ш' => 'sh', 'ч' => 'ch', 'ж' => 'j', 'ё' => 'yo', 'ю' => 'yu', 'я' => 'ya', 'ц' => 'ts', 'х' => 'x', 'ў' => 'o‘', 'қ' => 'q', 'ғ' => 'g‘', 'ҳ' => 'h',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'ъ' => '’', 'ы' => 'i', 'ь' => '', 'э' => 'e'];
        $out = '';
        foreach (mb_str_split($s) as $i => $ch) {
            $lo = mb_strtolower($ch);
            if (! isset($map[$lo])) {
                $out .= $ch;
                continue;
            }
            $t = $map[$lo];
            if ($lo === 'е' && ($i === 0 || preg_match('/[\s«(]/u', mb_substr($s, $i - 1, 1)))) {
                $t = 'ye';
            }
            $out .= $ch !== $lo ? mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1) : $t;
        }

        return $out;
    }

    /** Перевод текста из генератора на язык компании: словарь, затем шаблоны */
    private function tr(string $s, string $l): string
    {
        if ($l === 'ru' || $s === '' || ! preg_match('/\p{Cyrillic}/u', $s)) {
            return $s;
        }
        if (Lang::has($s, $l, false)) {
            return __($s, [], $l);
        }
        $pat = [
            'uz' => ['/^Оплата кв\. №(.+)$/u' => '$1-xonadon uchun to‘lov', '/^Задаток за кв\. №(.+)$/u' => '$1-xonadon uchun zakalat', '/^Топливо: (.+), (\d+) л$/u' => 'Yoqilg‘i: $1, $2 l'],
            'tg' => ['/^Оплата кв\. №(.+)$/u' => 'Пардохт барои ҳуҷраи №$1', '/^Задаток за кв\. №(.+)$/u' => 'Закалат барои ҳуҷраи №$1', '/^Топливо: (.+), (\d+) л$/u' => 'Сӯзишворӣ: $1, $2 л'],
        ][$l] ?? [];
        foreach ($pat as $re => $to) {
            if (preg_match($re, $s)) {
                return $l === 'uz' ? self::latin(preg_replace($re, $to, $s)) : preg_replace($re, $to, $s);
            }
        }
        if (preg_match('/^Зарплата за (.+)$/u', $s, $m)) {
            return ($l === 'uz' ? 'Ish haqi: ' : 'Музди меҳнат: ').__($m[1], [], $l);
        }

        return $l === 'uz' ? self::latin($s) : $s;
    }

    /** Данные генератора на языке компании: имена, покупатели, получатели, описания операций */
    private function localize(array $p, string $l): array
    {
        if ($l === 'ru') {
            return $p;
        }
        $name = fn ($s) => $l === 'uz' ? self::latin((string) $s) : (string) $s;
        $p += ['staff' => [], 'apts' => [], 'tx' => [], 'fleet' => [], 'stages' => []];
        foreach ($p['staff'] as &$x) {
            $x['name'] = $name($x['name']);
            // должность не переводим: это ключ (список должностей, ИТР, ставки); на экране программа переводит её сама
        }
        foreach ($p['apts'] as &$o) {
            $o['buyer'] = $name($o['buyer'] ?? '');
        }
        foreach ($p['tx'] as &$t) {
            $t['who'] = $this->tr((string) ($t['who'] ?? ''), $l);
            $t['desc'] = $this->tr((string) ($t['desc'] ?? ''), $l);
        }
        foreach (['fleet' => ['driver'], 'stages' => ['resp']] as $k => $fields) {
            foreach ($p[$k] as &$row) {
                foreach ($fields as $fl) {
                    if (! empty($row[$fl])) {
                        $row[$fl] = $name($row[$fl]);
                    }
                }
            }
        }

        return $p;
    }

    private function prepare(array $p, string $l): array
    {
        $p = $this->localize($this->shiftDates($p), $l);
        foreach ($p['tx'] as &$t) {
            if (($t['date'] ?? '') > $this->today) {
                $t['date'] = $this->today;
            }
        }
        foreach ($p['apts'] as &$o) {
            $o['payments'] ??= [];
            foreach ($o['payments'] as &$x) {
                if (($x['date'] ?? '') > $this->today) {
                    $x['date'] = $this->today;
                }
            }
        }

        return $p;
    }

    private function company(string $name, string $plan, string $status, array $projects, string $locale, string $tz, string $phone, string $currency): Workspace
    {
        $pl = Plan::where('slug', $plan)->first();
        $projects = array_map(fn ($p) => $this->prepare($p, $locale), $projects);
        $data = ['settings' => ['lang' => $locale, 'currency' => $currency, 'company' => $name, 'fuelPrice' => $currency === 'руб' ? 65 : 12500, 'normHours' => 8, 'overtimeK' => 1.5],
            'projects' => $projects, 'currentId' => $projects[0]['id']];
        $ws = Workspace::create([
            'name' => $name, 'version' => 1, 'plan_id' => $pl?->id, 'status' => $status, 'phone' => $phone, 'locale' => $locale, 'timezone' => $tz,
            'trial_ends_at' => $status === 'trial' ? now()->addDays(14)->endOfDay() : null, 'paid_until' => $status === 'active' ? now()->addYear()->endOfDay() : null,
            'report_enabled' => true, 'report_time' => '20:00', 'report_ai' => true, 'approval_limit' => $currency === 'руб' ? 400000 : 50000000,
            'public_enabled' => true, 'public_key' => Str::lower(Str::random(12)), 'settings' => ['demo_seed' => true, 'chain' => ['accountant', 'admin']],
        ]);
        $ws->forceFill(['data' => json_encode($data, JSON_UNESCAPED_UNICODE)])->save();
        $this->creds[$name] = ['ws' => $ws, 'users' => []];

        return $ws->fresh();
    }

    private function user(Workspace $ws, string $name, string $role, ?string $email, ?string $phone, ?array $objects = null, string $note = ''): User
    {
        $u = $ws->users()->create(['name' => $name, 'role' => $role, 'password' => self::PASSWORD, 'phone' => $phone, 'locale' => $ws->locale, 'is_active' => true,
            'email' => $email ?: 'u'.Str::lower(Str::random(10)).'@ws'.$ws->id.'.oftob.local', 'object_ids' => $objects]);
        $this->creds[$ws->name]['users'][] = [$name, config('oftob.roles.'.$role), $email ?: $phone, $note];

        return $u;
    }

    /** Файл в медиатеку объекта (+ превью, если есть .jpg рядом) */
    private function media(Workspace $ws, string $pid, string $kind, string $file, string $title, ?int $rooms = null, bool $main = false, array $meta = [], ?string $posterFile = null): void
    {
        $src = __DIR__.'/demo/'.$file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $base = 'media/'.$ws->id.'/'.$pid.'/'.Str::lower(Str::random(20));
        Storage::disk('local')->put($base.'.'.$ext, file_get_contents($src));
        $poster = null;
        $jpg = __DIR__.'/demo/'.($posterFile ?? str_replace('.glb', '.jpg', $file));
        if ($ext === 'glb' && is_file($jpg)) {
            Storage::disk('local')->put($poster = $base.'-poster.jpg', file_get_contents($jpg));
        }
        if (! empty($meta['finished_file'])) {
            Storage::disk('local')->put($base.'-finished.glb', file_get_contents(__DIR__.'/demo/'.$meta['finished_file']));
            $meta['finished'] = $base.'-finished.glb';
            unset($meta['finished_file']);
        }
        ObjectMedia::create(['workspace_id' => $ws->id, 'project_id' => $pid, 'kind' => $kind, 'rooms' => $rooms, 'title' => $title, 'path' => $base.'.'.$ext, 'ext' => $ext,
            'size' => filesize($src), 'poster' => $poster, 'is_public' => true, 'is_main' => $main, 'meta' => $meta ?: null, 'sort' => 0]);
    }

    private function d(int $daysAgo): string
    {
        return now()->subDays($daysAgo)->toDateString();
    }

    /* ======================== тексты демо-данных ======================== */

    private function textsUz(): array
    {
        return [
            'users' => [
                ['Sherzod Karimov', 'admin', 'direktor@navruz.demo', '01', null, 'rahbar, hammasini ko‘radi'],
                ['Malika Yusupova', 'accountant', 'buxgalter@navruz.demo', '02', null, 'pul, tasdiqlash, ombor'],
                ['Dilnoza Rahimova', 'cashier', 'kassa@navruz.demo', '03', null, 'kassalar va to‘lovlar'],
                ['Lola Karimova', 'manager', 'sotuv@navruz.demo', '04', null, 'xonadon savdosi, mijozlar uchun 3D'],
                ['Alisher Toshmatov', 'foreman', null, '11', 0, 'faqat 1-obyekt prorabi (telefon bilan kiradi)'],
                ['Baxtiyor Yusupov', 'foreman', null, '12', 1, 'faqat 2-obyekt prorabi'],
                ['Rustam Nazarov', 'foreman', null, '13', 2, 'faqat 3-obyekt prorabi'],
                ['Farhod Ismoilov', 'viewer', 'investor@navruz.demo', null, null, 'investor: hammasini ko‘radi, o‘zgartirmaydi'],
            ],
            'deskObject' => 'Obyekt kassasi', 'bank' => '«Kapitalbank» krediti', 'cashBuyers' => 'Xaridorlardan haftalik naqd pul', 'usdAdvance' => 'Tijorat xonasi uchun avans',
            'imprest' => 'Obyektga hisobdor pul', 'exchange' => 'Valyuta ayirboshlash', 'fasteners' => 'Mahkamlagichlar va sarf materiallari', 'electrodes' => 'Payvand elektrodlari (2-obyekt uchun)',
            'payroll' => [['Alisher Toshmatov', 9500000], ['1-brigada betonchilar', 42000000], ['2-brigada armaturachilar', 38000000]], 'payrollNote' => 'O‘tgan oy uchun ish haqi', 'rent' => 'Ofis ijarasi',
            'cps' => [['«BetonStroy» MChJ', 'supplier', 'Beton', 'ok'], ['«Metall-Invest» MChJ', 'supplier', 'Armatura', 'ok'], ['«Gazoblok Savdo» XK', 'supplier', 'Devor materiallari', 'ok'],
                ['«SpecTexnika» MChJ', 'contractor', 'Texnika', 'ok'], ['«ElektroMontaj» MChJ', 'contractor', 'Subpudrat', 'check'],
                ['«Sardor Derazalar» YaTT', 'supplier', 'Derazalar', 'suspicious', 'Ikki marta yetkazib berish muddatini buzdi, 100% oldindan to‘lov talab qiladi'],
                ['«Arzon Material» MChJ', 'supplier', 'Devor materiallari', 'blocked', 'D500 markasidan past gazoblok yetkazdi, da’vo yopilmagan'], ['«Lift Service» MChJ', 'contractor', 'Subpudrat', 'ok']],
            'contacts' => ['Aziz', 'Bobur', 'Sardor', 'Nodir'],
            'contracts' => [['NP-2026/14', 0, 0, 'B25 beton yetkazib berish, 1 800 m³', 2340000000, 'base', 25], ['NC-2026/03', 1, 1, 'A500S armatura, 220 t', 165000, 'USD', 12], ['NP-2026/21', 7, 0, '4 ta lift montaji', 980000000, 'base', 60]],
            'contractPay' => 'NP-2026/14 shartnoma bo‘yicha to‘lov, 1-qism',
            'pr' => ['9-qavat yopmasi uchun B25 beton', 'A500S armatura, 2-partiya', '5 smenaga avtokran ijarasi', 'Kotlovan to‘sig‘i', 'Vaxta shaharchasi uchun derazalar'],
            'prComment' => ['Narx shartnoma bo‘yicha, limit doirasida', 'Kelishildi', 'Yetkazib beruvchi shubhali — boshqasini toping'],
            'whs' => ['1-obyekt ombori', '2-obyekt ombori'],
            'items' => [['M400 sement, 50 kg qop', 'мешок', 'Сыпучие'], ['A500S armatura Ø12', 'т', 'Металл'], ['A500S armatura Ø16', 'т', 'Металл'], ['D500 gazoblok 600×300×200', 'м³', 'Стеновые'],
                ['Yuvilgan qum', 'м³', 'Сыпучие'], ['Shag‘al 5–20', 'м³', 'Сыпучие'], ['VVGng kabel 3×2,5', 'м', 'Электрика'], ['PPR quvur 25', 'м', 'Сантехника'], ['Gazoblok uchun yelim', 'мешок', 'Сыпучие'],
                ['Himoya kaskasi', 'шт', 'Охрана труда'], ['Ish qo‘lqoplari', 'пара', 'Охрана труда'], ['MR-3 elektrodlari Ø3', 'пачка', 'Металл']],
            'issueNote' => 'Brigadaga qavatga', 'sr' => ['10-qavat uchun', 'Poydevor plitasi uchun beton'],
            'tender' => ['3-obyekt uchun B30 beton yetkazib berish, 2 400 m³', "Mikserlar bilan, 3 smena.\nTo‘lov: 30% avans, qolgani fakt bo‘yicha."], 'bidNote' => 'Yetkazib berish narxga kiritilgan',
            'tasks' => ['10-qavat yopmasini quyish', '2-partiya armaturani qabul qilish', 'Oylik KS-2 dalolatnomasini tayyorlash', '3 xonalilar narxini yangilash', 'To‘siq loyihasini kelishish',
                'Yangi ishchilarga TX bo‘yicha yo‘riqnoma', '«BetonStroy» bilan solishtirma', 'Sayt uchun fasad fotosi'], 'taskDone' => 'Tayyor, qabul qilishingizni so‘rayman.',
            'services' => ['Hyundai R220 ekskavatori gidravlikasini ta’mirlash', 'Kafolat: 14-xonadonda suv oqishi', 'Ombordagi tarozini qiyoslash'], 'serviceNote' => 'Xaridor rakovina ostida suv oqayotganini xabar qildi.',
            'logs' => ['1-seksiya 10-qavat plitasining 40% quyildi. B25 beton, 62 m³.', '10-qavat plitasini armaturalash tugadi, texnik nazorat qabul qildi.', '2-seksiya 5-qavat gazoblok terish — 70%.', 'Kotlovan: 1 200 m³ tuproq olib chiqildi.'],
            'leads' => [['Jasur', '+998 93 555 12 34', 'instagram', 2, '1-obyektda 2 xonali narxi qancha?'], ['Gulnora', '+998 97 321 45 67', 'site', 3, '3 xonali, bo‘lib to‘lash qiziqtiradi'],
                ['Timur', '+998 90 777 88 99', 'facebook', 1, '600 mln gacha 1 xonali bormi?'], ['Irina', '+998 99 123 00 11', 'whatsapp', 2, 'Xonadonni 3D da ko‘rsa bo‘ladimi?']],
        ];
    }

    private function textsRu(): array
    {
        return [
            'users' => [
                ['Андрей Волков', 'admin', 'direktor@mosgrad.demo', '01', null, 'генеральный директор, видит всё'],
                ['Елена Соколова', 'accountant', 'buh@mosgrad.demo', '02', null, 'финансы, согласование, склад'],
                ['Ольга Морозова', 'cashier', 'kassa@mosgrad.demo', '03', null, 'кассы и оплаты'],
                ['Мария Лебедева', 'manager', 'prodazhi@mosgrad.demo', '04', null, 'продажи квартир, 3D-показы'],
                ['Сергей Козлов', 'foreman', null, '11', 0, 'прораб только «Oftob Tower» (вход по телефону)'],
                ['Дмитрий Новиков', 'foreman', null, '12', 1, 'прораб только «Речной квартал»'],
                ['Игорь Павлов', 'foreman', null, '13', 0, 'мастер участка «Oftob Tower» (стилобат)'],
                ['Павел Орлов', 'viewer', 'investor@mosgrad.demo', null, null, 'инвестор: всё видит, ничего не меняет'],
            ],
            'deskObject' => 'Касса объекта', 'bank' => 'Транш проектного финансирования (Сбербанк, эскроу)', 'cashBuyers' => 'Наличные от покупателей за неделю', 'usdAdvance' => 'Аванс за коммерческое помещение',
            'imprest' => 'Подотчёт на объект', 'exchange' => 'Обмен валюты', 'fasteners' => 'Крепёж и расходники', 'electrodes' => 'Сварочные электроды (для «Речного квартала»)',
            'payroll' => [['Сергей Козлов', 145000], ['Бригада монолитчиков №1', 1850000], ['Бригада арматурщиков №2', 1320000]], 'payrollNote' => 'Зарплата за прошлый месяц', 'rent' => 'Аренда офиса на Тверской',
            'cps' => [['ООО «Бетон-Москва»', 'supplier', 'Бетон', 'ok'], ['ООО «Северсталь-Дистрибуция»', 'supplier', 'Арматура', 'ok'], ['ООО «Кирпичный двор»', 'supplier', 'Стеновые', 'ok'],
                ['ООО «СпецТехАренда»', 'contractor', 'Техника', 'ok'], ['ООО «МонтажЭлектро»', 'contractor', 'Субподряд', 'check'],
                ['ИП Сидоренко (окна)', 'supplier', 'Окна', 'suspicious', 'Дважды сорвал сроки поставки, требует 100% предоплату'],
                ['ООО «Дешёвые материалы»', 'supplier', 'Стеновые', 'blocked', 'Поставил кирпич не той марки, претензия не закрыта'], ['ООО «ОТИС Лифт»', 'contractor', 'Субподряд', 'ok']],
            'contacts' => ['Алексей', 'Виктор', 'Наталья', 'Роман'],
            'contracts' => [['СП-2026/14', 0, 0, 'Поставка бетона B30, 9 400 м³', 68500000, 'base', 25], ['СП-2026/03', 1, 0, 'Арматура А500С, 1 150 т', 980000, 'USD', 12], ['СП-2026/21', 7, 0, 'Поставка и монтаж 12 лифтов', 54000000, 'base', 60]],
            'contractPay' => 'Оплата по договору СП-2026/14, 1 часть',
            'pr' => ['Бетон B60 на перекрытие 47 этажа Oftob Tower', 'Арматура А500С, 2 партия', 'Аренда автокрана на 5 смен', 'Ограждение котлована «Речного квартала»', 'Окна для бытового городка'],
            'prComment' => ['Цена по договору, сумма в лимите', 'Согласовано', 'Поставщик подозрительный — найдите другого'],
            'whs' => ['Склад «Северный парк»', 'Склад «Речной квартал»'],
            'items' => [['Цемент М500, мешок 50 кг', 'мешок', 'Сыпучие'], ['Арматура А500С Ø12', 'т', 'Металл'], ['Арматура А500С Ø16', 'т', 'Металл'], ['Кирпич облицовочный М150', 'шт', 'Стеновые'],
                ['Песок мытый', 'м³', 'Сыпучие'], ['Щебень гранитный 5–20', 'м³', 'Сыпучие'], ['Кабель ВВГнг 3×2,5', 'м', 'Электрика'], ['Труба ППР 25', 'м', 'Сантехника'], ['Клей для газобетона', 'мешок', 'Сыпучие'],
                ['Каска защитная', 'шт', 'Охрана труда'], ['Перчатки рабочие', 'пара', 'Охрана труда'], ['Электроды МР-3 Ø3', 'пачка', 'Металл']],
            'issueNote' => 'Бригаде на этаж', 'sr' => ['На 47 этаж', 'Бетон для фундаментной плиты'],
            'tender' => ['Поставка бетона B30 для «Речного квартала», 3 200 м³', "Поставка миксерами, 3 смены.\nОплата: 30% аванс, остальное по факту."], 'bidNote' => 'Доставка включена',
            'tasks' => ['Залить перекрытие 47 этажа', 'Принять арматуру по 2 партии', 'Подготовить акт КС-2 за месяц', 'Обновить прайс по 3-комнатным', 'Согласовать проект ограждения',
                'Провести инструктаж по ОТ новым рабочим', 'Сверка с «Бетон-Москва»', 'Рендеры фасада для сайта'], 'taskDone' => 'Готово, прошу принять.',
            'services' => ['Ремонт гидравлики экскаватора Hitachi ZX200', 'Гарантия: протечка в кв. 14 корпуса 1', 'Поверка весов на складе'], 'serviceNote' => 'Покупатель сообщил о протечке под раковиной.',
            'logs' => ['Залили 40% плиты 46 этажа. Бетон B60, 180 м³.', 'Армирование плиты 47 этажа завершено, технадзор принял.', '«Речной квартал»: кладка кирпича 5 этаж, секция 2 — 70%.', 'Нарастили второй башенный кран Liebherr 357 HC-L до 230 м.'],
            'leads' => [['Артём', '+7 916 555 12 34', 'instagram', 2, 'Цена двушки в Oftob Tower?'], ['Наталья', '+7 925 321 45 67', 'site', 3, 'Интересует трёшка, ипотека'],
                ['Константин', '+7 903 777 88 99', 'facebook', 1, 'Есть студии до 12 млн?'], ['Ирина', '+7 999 123 00 11', 'whatsapp', 2, 'Можно посмотреть квартиру в 3D?']],
        ];
    }

    /* ======================== полная компания ======================== */

    private function full(Workspace $ws, array $T, array $pids, string $slug, string $phone): void
    {
        $rub = Str::contains(\App\Services\CompanyStats::currency($ws), 'руб');
        $k = $rub ? 0.03 : 1;   // суммы в рублях меньше примерно в 30 раз (цены и платежи)
        $money = fn ($v) => round($v * $k, -3) ?: round($v * $k);
        $U = [];
        $fc = 0;
        foreach ($T['users'] as [$name, $role, $email, $ph, $obj, $note]) {
            $U[$role === 'foreman' ? 'foreman'.($fc++) : $role] = $this->user($ws, $name, $role, $email, $ph ? $phone.' '.$ph : null, $obj !== null ? [$pids[$obj]] : null, $note);
        }
        [$boss, $acc, $cash, $sales] = [$U['admin'], $U['accountant'], $U['cashier'], $U['manager']];
        $f1 = $U['foreman0'];
        $f2 = $U['foreman1'];
        $f3 = $U['foreman2'] ?? $U['foreman0'];
        [$p1, $p2, $p3] = $pids;
        auth()->setUser($boss);

        // деньги
        Cash::ensureDefaults($ws);
        $desks = CashDesk::of($ws->id)->get()->keyBy('name');
        $main = $desks['Основная касса'];
        $usd = $desks['Долларовая касса'];
        $bank = $desks['Расчётный счёт'];
        $deskObj = CashDesk::create(['workspace_id' => $ws->id, 'name' => $T['deskObject'].' · '.(collect($ws->projects())->firstWhere('id', $p1)['name'] ?? ''), 'kind' => 'cash',
            'currency' => \App\Services\Erp\Money::base($ws), 'project_id' => $p1, 'keeper_id' => $f1->id]);
        $cat = fn ($n) => FinCategory::of($ws->id)->where('name', $n)->value('id');
        FinCategory::of($ws->id)->where('name', 'Материалы')->update(['month_limit' => $money(2500000000)]);
        FinCategory::of($ws->id)->where('name', 'Офис и прочее')->update(['month_limit' => $money(60000000)]);
        Cash::add($ws, $boss, ['cash_desk_id' => $bank->id, 'type' => 'in', 'amount' => $money(8000000000), 'date' => $this->d(40), 'category_id' => $cat('Займы и инвестиции'), 'note' => $T['bank']]);
        Cash::add($ws, $boss, ['cash_desk_id' => $main->id, 'type' => 'in', 'amount' => $money(650000000), 'date' => $this->d(20), 'category_id' => $cat('Продажа квартир'), 'note' => $T['cashBuyers']]);
        Cash::add($ws, $boss, ['cash_desk_id' => $usd->id, 'type' => 'in', 'amount' => 85000, 'date' => $this->d(18), 'category_id' => $cat('Аванс заказчика'), 'note' => $T['usdAdvance']]);
        Cash::transfer($ws, $acc, $bank->id, $deskObj->id, $money(120000000), null, $T['imprest'], $this->d(10));
        Cash::transfer($ws, $acc, $usd->id, $main->id, 10000, null, $T['exchange'], $this->d(8));
        Cash::add($ws, $cash, ['cash_desk_id' => $deskObj->id, 'type' => 'out', 'amount' => $money(18500000), 'date' => $this->d(6), 'category_id' => $cat('Материалы'), 'project_id' => $p1, 'note' => $T['fasteners']]);
        Cash::add($ws, $cash, ['cash_desk_id' => $deskObj->id, 'type' => 'out', 'amount' => $money(9400000), 'date' => $this->d(3), 'category_id' => $cat('Материалы'), 'project_id' => $p2, 'note' => $T['electrodes']]);
        foreach ($T['payroll'] as [$who, $amt]) {
            Cash::add($ws, $cash, ['cash_desk_id' => $main->id, 'type' => 'out', 'amount' => $amt, 'date' => $this->d(5), 'employee' => $who, 'category_id' => $cat('Зарплата'), 'project_id' => $p1, 'note' => $T['payrollNote']]);
        }
        Cash::add($ws, $cash, ['cash_desk_id' => $main->id, 'type' => 'out', 'amount' => $money(14000000), 'date' => $this->today, 'category_id' => $cat('Офис и прочее'), 'note' => $T['rent']]);

        // контрагенты и договоры
        $cps = [];
        foreach ($T['cps'] as $i => $r) {
            $cps[$i] = Counterparty::create(['workspace_id' => $ws->id, 'name' => $r[0], 'kind' => $r[1], 'folder' => $r[2], 'status' => $r[3], 'status_reason' => $r[4] ?? null,
                'inn' => (string) (($rub ? 7700000000 : 300000000) + random_int(100000, 999999)), 'phone' => $phone.' '.random_int(20, 99), 'contact' => $T['contacts'][$i % 4], 'created_by' => $acc->id]);
        }
        $contracts = [];
        foreach ($T['contracts'] as [$num, $ci, $pi, $subj, $amt, $cur, $due]) {
            $contracts[] = Contract::create(['workspace_id' => $ws->id, 'counterparty_id' => $cps[$ci]->id, 'project_id' => $pids[$pi], 'number' => $num, 'date' => $this->d(90 - $due),
                'subject' => $subj, 'direction' => 'out', 'amount' => $amt, 'currency' => $cur === 'USD' ? 'USD' : \App\Services\Erp\Money::base($ws),
                'due_date' => now()->addDays($due)->toDateString(), 'status' => 'active', 'created_by' => $acc->id]);
        }
        Cash::add($ws, $acc, ['cash_desk_id' => $bank->id, 'type' => 'out', 'amount' => round($contracts[0]->amount * 0.5), 'date' => $this->d(30), 'category_id' => $cat('Материалы'), 'project_id' => $p1,
            'counterparty_id' => $contracts[0]->counterparty_id, 'contract_id' => $contracts[0]->id, 'note' => $T['contractPay']]);

        // заявки на оплату в разных состояниях
        $base = \App\Services\Erp\Money::base($ws);
        $r1 = Payments::create($ws, $f1, ['amount' => $money(640000000), 'currency' => $base, 'purpose' => $T['pr'][0], 'project_id' => $p1, 'counterparty_id' => $cps[0]->id, 'contract_id' => $contracts[0]->id, 'category_id' => $cat('Материалы'), 'due_date' => now()->addDays(3)->toDateString()]);
        $r2 = Payments::create($ws, $f2, ['amount' => 42000, 'currency' => 'USD', 'purpose' => $T['pr'][1], 'project_id' => $p2, 'counterparty_id' => $cps[1]->id, 'contract_id' => $contracts[1]->id, 'category_id' => $cat('Материалы'), 'due_date' => now()->addDays(7)->toDateString()]);
        Payments::decide($r2, $acc, true, $T['prComment'][0]);
        $r3 = Payments::create($ws, $f1, ['amount' => $money(35000000), 'currency' => $base, 'purpose' => $T['pr'][2], 'project_id' => $p1, 'counterparty_id' => $cps[3]->id, 'category_id' => $cat('Техника и топливо')]);
        Payments::decide($r3, $acc, true);
        Payments::decide($r3->fresh(), $boss, true, $T['prComment'][1]);
        $r4 = Payments::create($ws, $f3, ['amount' => $money(28000000), 'currency' => $base, 'purpose' => $T['pr'][3], 'project_id' => $p3, 'category_id' => $cat('Материалы')]);
        Payments::decide($r4, $acc, true);
        Payments::decide($r4->fresh(), $boss, true);
        Payments::pay($ws, $cash, $r4->fresh(), $main->id, $this->d(1));
        $r5 = Payments::create($ws, $f2, ['amount' => $money(12000000), 'currency' => $base, 'purpose' => $T['pr'][4], 'project_id' => $p2, 'counterparty_id' => $cps[5]->id, 'category_id' => $cat('Материалы')]);
        Payments::decide($r5, $acc, false, $T['prComment'][2]);
        unset($r1);

        // склад
        Stock::ensureDefaults($ws);
        $central = Warehouse::of($ws->id)->first();
        $w1 = Warehouse::create(['workspace_id' => $ws->id, 'name' => $T['whs'][0], 'project_id' => $p1, 'keeper_id' => $f1->id]);
        $w2 = Warehouse::create(['workspace_id' => $ws->id, 'name' => $T['whs'][1], 'project_id' => $p2, 'keeper_id' => $f2->id]);
        $prices = [68000, 9200000, 9100000, $rub ? 1 : 720000, 95000, 160000, 9800, 12500, 52000, 45000, 6000, 85000];
        $rubPrices = [520, 78000, 76000, 38, 1400, 2400, 95, 110, 450, 650, 90, 750];
        $qtys = [1200, 42, 30, $rub ? 46000 : 260, 400, 350, 2400, 900, 80, 60, 400, 25];
        $mins = [300, 5, 5, $rub ? 5000 : 40, 50, 50, 500, 200, 100, 20, 100, 30];
        $items = [];
        foreach ($T['items'] as $i => [$n, $u, $g]) {
            $price = $rub ? $rubPrices[$i] : $prices[$i];
            $items[$i] = Item::create(['workspace_id' => $ws->id, 'name' => $n, 'unit' => $u, 'group' => $g, 'min_qty' => $mins[$i], 'price' => $price, 'sku' => strtoupper(substr($slug, 0, 2)).'-'.(1000 + $i)]);
            Stock::receive($ws, $acc, ['item_id' => $items[$i]->id, 'warehouse_id' => $central->id, 'qty' => $qtys[$i], 'price' => $price, 'date' => $this->d(15 + $i * 2), 'counterparty_id' => $cps[0]->id]);
        }
        foreach ([[0, 500, $w1], [1, 20, $w1], [3, $rub ? 20000 : 120, $w2], [6, 900, $w2], [9, 30, $w1]] as [$i, $q, $w]) {
            Stock::transfer($ws, $acc, ['item_id' => $items[$i]->id, 'warehouse_id' => $central->id, 'to_warehouse_id' => $w->id, 'qty' => $q, 'date' => $this->d(10)]);
        }
        foreach ([[0, 380, $w1, $p1, $f1], [1, 14, $w1, $p1, $f1], [3, $rub ? 15000 : 95, $w2, $p2, $f2], [6, 650, $w2, $p2, $f2]] as [$i, $q, $w, $pid, $who]) {
            Stock::issue($ws, $who, ['item_id' => $items[$i]->id, 'warehouse_id' => $w->id, 'qty' => $q, 'project_id' => $pid, 'date' => $this->d(4), 'note' => $T['issueNote']]);
        }
        Stock::issue($ws, $acc, ['item_id' => $items[11]->id, 'warehouse_id' => $central->id, 'qty' => 18, 'project_id' => $p3, 'date' => $this->d(2)]);   // меньше минимума

        // снабжение
        $sr1 = SupplyRequest::create(['workspace_id' => $ws->id, 'number' => 1, 'project_id' => $p1, 'user_id' => $f1->id, 'need_by' => now()->addDays(5)->toDateString(), 'status' => 'new', 'note' => $T['sr'][0]]);
        SupplyLine::create(['supply_request_id' => $sr1->id, 'item_id' => $items[0]->id, 'qty' => 600, 'price' => $items[0]->price]);
        SupplyLine::create(['supply_request_id' => $sr1->id, 'item_id' => $items[8]->id, 'qty' => 120, 'price' => $items[8]->price]);
        $sr2 = SupplyRequest::create(['workspace_id' => $ws->id, 'number' => 2, 'project_id' => $p2, 'user_id' => $f2->id, 'need_by' => now()->addDays(2)->toDateString(), 'status' => 'ordered', 'counterparty_id' => $cps[2]->id]);
        $l2 = SupplyLine::create(['supply_request_id' => $sr2->id, 'item_id' => $items[3]->id, 'qty' => $rub ? 30000 : 200, 'price' => $items[3]->price]);
        Stock::receiveSupply($ws, $acc, $sr2, [$l2->id => $rub ? 12000 : 80], $w2->id);
        $sr3 = SupplyRequest::create(['workspace_id' => $ws->id, 'number' => 3, 'project_id' => $p3, 'user_id' => $f3->id, 'need_by' => now()->addDays(14)->toDateString(), 'status' => 'approved', 'note' => $T['sr'][1]]);
        SupplyLine::create(['supply_request_id' => $sr3->id, 'item_id' => $items[5]->id, 'qty' => 300, 'price' => $items[5]->price]);

        // тендер
        $t = Tender::create(['workspace_id' => $ws->id, 'title' => $T['tender'][0], 'description' => $T['tender'][1], 'project_id' => $p2 === $p3 ? $p2 : $p3, 'supply_request_id' => $sr3->id,
            'deadline' => now()->addDays(4)->toDateString(), 'status' => 'review', 'user_id' => $acc->id]);
        $bids = [];
        foreach ([[0, $rub ? 6400 : 1180000, 3], [2, $rub ? 6900 : 1235000, 5], [3, $rub ? 6250 : 1150000, 7]] as [$ci, $pr, $days]) {
            $bids[] = TenderBid::create(['tender_id' => $t->id, 'counterparty_id' => $cps[$ci]->id, 'amount' => $pr * 2400, 'days' => $days, 'note' => $T['bidNote']]);
        }
        $t->update(['winner_bid_id' => $bids[0]->id]);

        // задачи
        foreach ([[0, $f1, $p1, 3, 'high', 'doing', 60], [1, $acc, $p2, 1, 'normal', 'todo', 0], [2, $f1, $p1, -2, 'high', 'review', 100], [3, $sales, $p1, 5, 'normal', 'todo', 0],
            [4, $f3, $p3, -1, 'high', 'doing', 30], [5, $f2, $p2, 0, 'normal', 'review', 100], [6, $acc, $p1, 7, 'low', 'todo', 0], [7, $sales, $p2, -5, 'normal', 'done', 100]] as [$ti, $who, $pid, $due, $prio, $st, $prog]) {
            $task = Task::create(['workspace_id' => $ws->id, 'title' => $T['tasks'][$ti], 'project_id' => $pid, 'assignee_id' => $who->id, 'author_id' => $boss->id, 'due_date' => now()->addDays($due)->toDateString(),
                'priority' => $prio, 'status' => $st, 'progress' => $prog, 'done_at' => $st === 'done' ? now()->subDays(4) : null, 'approved_by' => $st === 'done' ? $boss->id : null]);
            if ($st === 'review') {
                Comment::create(['workspace_id' => $ws->id, 'subject' => 'task', 'subject_id' => $task->id, 'user_id' => $who->id, 'text' => $T['taskDone']]);
            }
        }

        // сервисные заявки
        ServiceOrder::create(['workspace_id' => $ws->id, 'number' => 1, 'title' => $T['services'][0], 'project_id' => $p3, 'counterparty_id' => $cps[3]->id, 'assignee_id' => $f3->id, 'amount' => $money(7500000), 'due_date' => now()->addDays(2)->toDateString(), 'status' => 'work', 'user_id' => $f3->id]);
        ServiceOrder::create(['workspace_id' => $ws->id, 'number' => 2, 'title' => $T['services'][1], 'project_id' => $p1, 'assignee_id' => $f1->id, 'due_date' => now()->addDays(1)->toDateString(), 'status' => 'new', 'user_id' => $sales->id, 'description' => $T['serviceNote']]);
        ServiceOrder::create(['workspace_id' => $ws->id, 'number' => 3, 'title' => $T['services'][2], 'counterparty_id' => $cps[7]->id, 'amount' => $money(1200000), 'status' => 'done', 'user_id' => $acc->id]);

        // журнал работ с геометками
        $geo = $rub ? [[55.8312, 37.4975], [55.8313, 37.4974], [55.6830, 37.6560], [55.8310, 37.4980]] : [[41.3412, 69.2867], [41.3413, 69.2866], [41.3201, 69.3352], [41.2954, 69.2721]];
        foreach ([[$p1, $f1, 0], [$p1, $f1, 1], [$p2, $f2, 0], [$p3, $f3, 2]] as $i => [$pid, $who, $ago]) {
            SiteLog::create(['workspace_id' => $ws->id, 'project_id' => $pid, 'user_id' => $who->id, 'date' => $this->d($ago), 'text' => $T['logs'][$i], 'source' => 'telegram',
                'lat' => $geo[$i][0], 'lng' => $geo[$i][1], 'accuracy' => 12, 'taken_at' => now()->subDays($ago)]);
        }

        // заявки клиентов
        foreach ($T['leads'] as [$n, $ph, $ch, $r, $txt]) {
            LeadInbox::create(['workspace_id' => $ws->id, 'ch' => $ch, 'kind' => 'form', 'key' => preg_replace('/\D/', '', $ph), 'name' => $n, 'phone' => $ph, 'src' => ucfirst($ch), 'rooms' => (string) $r, 'text' => $txt]);
        }

        // кабинет покупателя
        $p = collect($ws->fresh()->projects())->firstWhere('id', $p1);
        foreach (Apartments::list($p) as $apt) {
            if ($apt['status'] === 'sold' && $apt['plan'] && str_contains($apt['plan'], 'Рассрочка')) {
                $bl = \App\Services\Deals::link($ws->fresh(), $p1, $apt['id']);   // ссылка привязана к текущей сделке
                $this->creds[$ws->name]['buyer'] = $bl->url().'  ('.$apt['buyer'].', '.$apt['num'].')';
                break;
            }
        }
        $this->creds[$ws->name]['public'] = url('/s/'.$ws->public_key);
    }

    /* ======================== 3D ======================== */

    private function media3dNavruz(Workspace $ws): void
    {
        // Toshkentning haqiqiy kvartallari (OpenStreetMap): hozirgi / tayyor holat, kunduz / tun — make-tashkent.cjs
        $sites = json_decode(file_get_contents(__DIR__.'/demo/tsh-sites.json'), true);
        $names = [
            'nvz-tower' => ['tower', '«Navruz Tower» — Mirobod, atrofdagi mahalla bilan', '«Navruz Tower» yaqindan: karkas, kran'],
            'nvz-park' => ['park', '«Navruz Park» — Amir Temur ko‘chasi bo‘yida', '«Navruz Park» yaqindan: 2 seksiya, kran'],
            'nvz-city' => ['city', '«Navruz City» — Buyuk Ipak Yo‘li, mahalla ichida', '«Navruz City» yaqindan: 3 seksiya, 2 ta kran'],
        ];
        foreach ($names as $pid => [$key, $title, $close]) {
            $s = $sites[$key];
            $theta = round(rad2deg($s['rot'])) + 35;   // fasad (bino old tomoni) kameraga qaragan
            // locate — где дом в сцене: квартира подсвечивается прямо на модели (продажи, сайт, кабинет покупателя)
            $base = ['exposure' => 0.75, 'sky' => true, 'daynight' => true, 'finished_file' => "tsh-{$key}-finished.glb", 'locate' => \App\Services\Locate::demo($pid)];
            $this->media($ws, $pid, 'exterior', "tsh-{$key}-current.glb", $title, null, true,
                $base + ['camera' => "{$theta}deg 63deg 340m", 'target' => "{$s['x']}m ".round($s['top'] / 3)."m {$s['z']}m"]);
            $this->media($ws, $pid, 'exterior', "tsh-{$key}-current.glb", $close." — {$s['done']}/{$s['floors']} qavat", null, false,
                $base + ['camera' => "{$theta}deg 74deg 150m", 'target' => "{$s['x']}m ".round($s['top'] / 2)."m {$s['z']}m"], "tsh-{$key}-close.jpg");
        }
        foreach (['nvz-park', 'nvz-city', 'nvz-tower'] as $pid) {
            $this->media($ws, $pid, 'interior', 'interior-1.glb', '1 xonali xonadon', 1);
            $this->media($ws, $pid, 'interior', 'interior-2.glb', '2 xonali xonadon', 2);
            $this->media($ws, $pid, 'interior', 'interior-3.glb', '3 xonali xonadon', 3);
        }
    }

    private function media3dMoscow(Workspace $ws): void
    {
        // Москва-Сити по данным OpenStreetMap: два состояния (сейчас / готово), день и ночь
        $city = ['camera' => '-30deg 72deg 1100m', 'target' => '0m 120m 0m', 'exposure' => 0.75, 'sky' => true, 'daynight' => true];
        $this->media($ws, 'msk-sever', 'exterior', 'msk-city-current.glb', 'Oftob Tower в Москва-Сити — квартал целиком', null, true, $city + ['finished_file' => 'msk-city-finished.glb']);
        $this->media($ws, 'msk-sever', 'exterior', 'msk-city-current.glb', 'Oftob Tower крупно: стройка, краны, 46 этажей из 82', null, false,
            ['camera' => '-55deg 78deg 520m', 'target' => '250m 150m 150m', 'exposure' => 0.75, 'sky' => true, 'daynight' => true, 'finished_file' => 'msk-city-finished.glb'], 'msk-city-current.jpg');
        $this->media($ws, 'msk-sever', 'interior', 'msk-flat-1.glb', 'Студия 32 м² с видом на Москва-Сити', 1, false, ['exposure' => 0.8]);
        $this->media($ws, 'msk-sever', 'interior', 'msk-flat-2.glb', 'Евро-двушка 58 м²', 2, false, ['exposure' => 0.8]);
        $this->media($ws, 'msk-sever', 'interior', 'msk-flat-3.glb', 'Трёхкомнатная 96 м²', 3, false, ['exposure' => 0.8]);
        foreach ([['msk-pano-living.jpg', 'Панорама 360°: кухня-гостиная', 2], ['msk-pano-bedroom.jpg', 'Панорама 360°: спальня', 3]] as [$f, $t, $r]) {
            if (is_file(__DIR__.'/demo/'.$f) && filesize(__DIR__.'/demo/'.$f) > 50000) {
                $this->media($ws, 'msk-sever', 'pano', $f, $t, $r);
            }
        }
        $this->media($ws, 'msk-reka', 'exterior', 'msk-complex.glb', '«Речной квартал» — квартал с двором', null, true, ['camera' => '-35deg 78deg 290m', 'exposure' => 0.72, 'sky' => true, 'target' => '0m 22m -12m']);
        $this->media($ws, 'msk-reka', 'interior', 'msk-flat-2.glb', 'Евро-двушка 58 м²', 2, false, ['exposure' => 0.8]);
    }

    /* ======================== Pamir: пробный период ======================== */

    private function pamir(Workspace $ws): void
    {
        $boss = $this->user($ws, 'Фирдавс Назаров', 'admin', 'direktor@pamir.demo', '+992 90 100 00 01', null, 'роҳбар (давраи озмоишӣ)');
        $this->user($ws, 'Мадина Саидова', 'accountant', 'buxgalter@pamir.demo', null, null, 'муҳосиб');
        $this->user($ws, 'Суҳроб Каримов', 'foreman', null, '+992 90 100 00 11', ['pmr-sohil'], 'прораби «Соҳил» (бо телефон ворид мешавад)');
        auth()->setUser($boss);
        Cash::ensureDefaults($ws);
        $this->media($ws, 'pmr-sohil', 'exterior', 'building-a.glb', '«Соҳил» — фасад', null, true, ['camera' => '-32deg 70deg 62%', 'exposure' => 0.85]);
        $this->media($ws, 'pmr-sohil', 'interior', 'interior-2.glb', 'Хонаи 2-ҳуҷрагӣ', 2);
        Counterparty::create(['workspace_id' => $ws->id, 'name' => 'ҶДММ «Бетони Душанбе»', 'kind' => 'supplier', 'folder' => 'Бетон', 'inn' => '020011223', 'status' => 'ok']);
        $this->creds[$ws->name]['public'] = url('/s/'.$ws->public_key);
    }

    /* ======================== отчёт ======================== */

    private function report(): void
    {
        $lines = ['ДЕМО-ДОСТУПЫ Oftob_Sohtumon — создано '.now()->format('d.m.Y H:i'), 'Вход: '.url('/login').'   Пароль у всех: '.self::PASSWORD, ''];
        foreach ($this->creds as $company => $c) {
            $ws = $c['ws'];
            $lines[] = '■ '.$company.' — '.($ws->status === 'trial' ? 'пробный период до '.$ws->trial_ends_at?->format('d.m.Y') : 'оплачено до '.$ws->paid_until?->format('d.m.Y')).' · язык: '.$ws->locale;
            foreach ($c['users'] as [$name, $role, $login, $note]) {
                $lines[] = sprintf('   %-24s %-22s %-20s %s', $login, $role, $name, $note);
            }
            if (! empty($c['buyer'])) {
                $lines[] = '   Кабинет покупателя: '.$c['buyer'];
            }
            $lines[] = '   Шахматка для сайта: '.$c['public'];
            $lines[] = '';
        }
        $text = implode("\n", $lines);
        Storage::disk('local')->put('demo-logins.txt', $text);
        $this->command?->line($text);
    }
}
