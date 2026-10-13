<?php

use App\Services\CompanyStats;

if (! function_exists('money_s')) {
    /** Коротко: «12,3 млн сум» */
    function money_s(float|int|null $n, string $cur = 'сум'): string
    {
        return CompanyStats::short((float) $n).' '.__($cur);
    }
}

if (! function_exists('money_f')) {
    /** Полностью: «12 345 678 сум» */
    function money_f(float|int|null $n, string $cur = 'сум'): string
    {
        return CompanyStats::money((float) $n).' '.__($cur);
    }
}

if (! function_exists('lang_url')) {
    /** Текущая страница на другом языке */
    function lang_url(string $lang): string
    {
        return request()->fullUrlWithQuery(['lang' => $lang]);
    }
}

if (! function_exists('initials')) {
    function initials(?string $name): string
    {
        $p = preg_split('/\s+/u', trim((string) $name)) ?: [];

        return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1).mb_substr($p[1] ?? '', 0, 1));
    }
}

if (! function_exists('ic')) {
    /** Иконка Lucide: {!! ic('wallet') !!} */
    function ic(string $name, string $class = '', int $size = 18): string
    {
        $paths = config('icons.'.$name) ?? config('icons.circle');

        return '<svg class="ic '.e($class).'" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    }
}

if (! function_exists('dmy')) {
    function dmy($date): string
    {
        return $date ? \Illuminate\Support\Carbon::parse($date)->format('d.m.Y') : '—';
    }
}

if (! function_exists('st')) {
    /** Текст сайта: свой текст администратора для текущего языка или обычный перевод */
    function st(string $key, array $replace = []): string
    {
        $v = \App\Services\SiteText::get($key);
        if ($v === null) {
            return __($key, $replace);
        }
        foreach ($replace as $k => $r) {
            $v = str_replace(':'.$k, (string) $r, $v);
        }

        return $v;
    }
}

if (! function_exists('weekday')) {
    /** День недели на языке интерфейса (у Carbon в узбекской латинице дней нет) */
    function weekday($date): string
    {
        $d = \Illuminate\Support\Carbon::parse($date);

        return __(['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'][$d->dayOfWeek]);
    }
}

if (! function_exists('sys_tr')) {
    /**
     * Перевод служебных записей, сохранённых по-русски (история изменений, примечания к операциям):
     * известные фразы заменяются на язык интерфейса, пользовательский текст остаётся как есть.
     */
    function sys_tr(?string $s): string
    {
        $s = (string) $s;
        if ($s === '' || app()->getLocale() === 'ru' || ! preg_match('/\p{Cyrillic}/u', $s)) {
            return $s;
        }
        static $map = null;
        if ($map === null) {
            $phrases = ['Заявка на оплату', 'Заявка на снабжение', 'Заявка', 'согласована, далее —', 'утверждена', 'отклонена', 'оплачена', 'Приход', 'Выдача', 'Перемещение', 'Инвентаризация',
                'Начальный остаток', 'Оплата за материал', 'заявка на снабжение', 'Перевод', 'курс', 'Новый пользователь', 'Новый контрагент', 'Договор', 'Тендер', 'победитель', 'Задача',
                'Сервис', 'статус', 'создана', 'изменена', 'объектов', 'Оплата кв.', 'Удалена оплата по квартире', 'Отклонён расход', 'расход', 'Кв.', 'Удалена', 'Продана', 'Бронь', 'Свободна',
                'Основная касса', 'Долларовая касса', 'Расчётный счёт', 'Центральный склад', 'Проверен', 'Подозрительный', 'Заблокирован', 'На проверке'];
            foreach (\App\Services\Erp\Cash::DEFAULT_CATS as $list) {
                array_push($phrases, ...$list);
            }
            foreach (config('oftob.roles') as $r) {
                $phrases[] = $r;
            }
            foreach (array_keys(\App\Models\ObjectMedia::KINDS) as $k) {
                $phrases[] = \App\Models\ObjectMedia::KINDS[$k][0];
            }
            usort($phrases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $map = [];
            foreach (array_unique($phrases) as $p) {
                $t = __($p);
                if ($t !== $p) {
                    $map[$p] = $t;
                }
            }
            // единицы измерения после числа
            foreach (['мешок', 'пачка', 'пара', 'шт', 'м³', 'м²', 'т', 'м', 'кг', 'л', 'сум', 'смн'] as $u) {
                $map["\x01".$u] = __($u);
            }
        }
        foreach ($map as $from => $to) {
            if ($from[0] === "\x01") {
                $u = substr($from, 1);
                $s = preg_replace('/(\d) '.preg_quote($u, '/').'(?=$|[\s·,.)→])/u', '$1 '.$to, $s);
            } else {
                $s = str_replace($from, $to, $s);
            }
        }

        return $s;
    }
}

if (! function_exists('apt_num')) {
    /** Номер помещения: «К1» (коммерция) на языке интерфейса */
    function apt_num($n): string
    {
        return str_replace('К', __('К'), (string) $n);
    }
}

if (! function_exists('file_size')) {
    /** Размер файла: 34 KB / 7,1 MB */
    function file_size($bytes): string
    {
        $b = (int) $bytes;

        return $b < 1048576 ? max(1, round($b / 1024)).' '.__('КБ') : number_format($b / 1048576, 1, ',', ' ').' '.__('МБ');
    }
}
