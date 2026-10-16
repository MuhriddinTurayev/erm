<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметки прихода через Telegram: сотрудник (по своей ссылке) привязывает Telegram и шлёт местоположение,
 * в том числе «трансляцию геопозиции» — Telegram передаёт её и при выключенном экране.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_tokens', function (Blueprint $t) {
            $t->string('tg_chat_id', 32)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('worker_tokens', function (Blueprint $t) {
            $t->dropIndex(['tg_chat_id']);
            $t->dropColumn('tg_chat_id');
        });
    }
};
