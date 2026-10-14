<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram-бот «Материалы»: корзина прораба (переживает любые шаги диалога),
 * у заявки на снабжение — пометка «срочно» и фото (к заявке и накладная при приёмке).
 * Новый статус заявки transit («в пути») — строка, схема не меняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('tg_cart')->nullable()->after('tg_state');
        });
        Schema::table('supply_requests', function (Blueprint $t) {
            $t->boolean('urgent')->default(false)->after('need_by');
            $t->json('photos')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('tg_cart'));
        Schema::table('supply_requests', fn (Blueprint $t) => $t->dropColumn(['urgent', 'photos']));
    }
};
