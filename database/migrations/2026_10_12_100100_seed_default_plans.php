<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $plans = [
            [
                'slug' => 'start', 'sort' => 1, 'price_month' => 990000, 'price_year' => 9900000, 'max_objects' => 1, 'max_users' => 5, 'ai_requests' => 100, 'is_featured' => false,
                'name' => ['ru' => 'Старт', 'uz' => 'Start', 'tg' => 'Оғоз'],
                'description' => ['ru' => 'Для небольшой компании с одним объектом', 'uz' => "Bitta obyektli kichik kompaniya uchun", 'tg' => 'Барои ширкати хурд бо як объект'],
                'features' => [
                    'ru' => ['Касса и расходы', 'Ежедневный отчёт в Telegram', 'Продажи квартир и лиды', 'Сотрудники, табель, GPS'],
                    'uz' => ["Kassa va xarajatlar", "Telegram'ga kunlik hisobot", "Kvartira sotuvi va lidlar", "Xodimlar, tabel, GPS"],
                    'tg' => ['Хазина ва хароҷот', 'Ҳисоботи ҳаррӯза ба Telegram', 'Фурӯши хонаҳо ва лидҳо', 'Кормандон, табел, GPS'],
                ],
            ],
            [
                'slug' => 'business', 'sort' => 2, 'price_month' => 2490000, 'price_year' => 24900000, 'max_objects' => 5, 'max_users' => 25, 'ai_requests' => 1000, 'is_featured' => true,
                'name' => ['ru' => 'Бизнес', 'uz' => 'Biznes', 'tg' => 'Бизнес'],
                'description' => ['ru' => 'Несколько объектов, полный контроль денег', 'uz' => "Bir nechta obyekt, pul ustidan to'liq nazorat", 'tg' => 'Якчанд объект, назорати пурраи пул'],
                'features' => [
                    'ru' => ['Всё из тарифа «Старт»', 'Сводка по всем объектам', 'Роли и доступ по объектам', 'ИИ-аналитик в отчёте', 'Бухгалтерия, смета, техника'],
                    'uz' => ["«Start» tarifidagi hamma narsa", "Barcha obyektlar bo'yicha hisobot", "Rollar va obyektlar bo'yicha ruxsat", "Hisobotda AI-tahlilchi", "Buxgalteriya, smeta, texnika"],
                    'tg' => ['Ҳамаи имконоти «Оғоз»', 'Хулоса аз рӯи ҳамаи объектҳо', 'Нақшҳо ва дастрасӣ аз рӯи объект', 'Таҳлилгари AI дар ҳисобот', 'Муҳосибот, смета, техника'],
                ],
            ],
            [
                'slug' => 'corporate', 'sort' => 3, 'price_month' => 5990000, 'price_year' => 59900000, 'max_objects' => 0, 'max_users' => 0, 'ai_requests' => 0, 'is_featured' => false,
                'name' => ['ru' => 'Корпорация', 'uz' => 'Korporativ', 'tg' => 'Корпоративӣ'],
                'description' => ['ru' => 'Без ограничений для крупных застройщиков', 'uz' => 'Yirik quruvchilar uchun cheklovsiz', 'tg' => 'Бе маҳдудият барои созандагони калон'],
                'features' => [
                    'ru' => ['Всё из тарифа «Бизнес»', 'Объекты и пользователи без ограничений', 'ИИ без лимита', 'Персональный менеджер и обучение'],
                    'uz' => ["«Biznes» tarifidagi hamma narsa", "Cheksiz obyektlar va foydalanuvchilar", "Cheksiz AI", "Shaxsiy menejer va o'qitish"],
                    'tg' => ['Ҳамаи имконоти «Бизнес»', 'Объектҳо ва корбарон бе маҳдудият', 'AI бе маҳдудият', 'Менеҷери шахсӣ ва омӯзиш'],
                ],
            ],
        ];
        foreach ($plans as $p) {
            DB::table('plans')->insert([
                'slug' => $p['slug'], 'name' => json_encode($p['name'], JSON_UNESCAPED_UNICODE), 'description' => json_encode($p['description'], JSON_UNESCAPED_UNICODE),
                'features' => json_encode($p['features'], JSON_UNESCAPED_UNICODE), 'price_month' => $p['price_month'], 'price_year' => $p['price_year'], 'currency' => 'сум',
                'max_objects' => $p['max_objects'], 'max_users' => $p['max_users'], 'ai_enabled' => true, 'ai_requests' => $p['ai_requests'], 'daily_report' => true,
                'trial_days' => 14, 'is_active' => true, 'is_featured' => $p['is_featured'], 'sort' => $p['sort'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Компании, которые уже работали до тарифов, — на максимальном тарифе без срока
        $top = DB::table('plans')->where('slug', 'corporate')->value('id');
        DB::table('workspaces')->whereNull('plan_id')->update(['plan_id' => $top, 'status' => 'active', 'paid_until' => null]);
    }

    public function down(): void
    {
        DB::table('workspaces')->update(['plan_id' => null]);
        DB::table('plans')->whereIn('slug', ['start', 'business', 'corporate'])->delete();
    }
};
