<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Один Telegram-бот на двух сайтах (сервер и копия на компьютере): сообщения получает основной сайт,
 * а те, что относятся к другому сайту (его код привязки, его сотрудник), кладёт сюда — тот забирает их сам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_relay_queue', function (Blueprint $t) {
            $t->id();
            $t->string('chat_id', 32)->index();
            $t->longText('payload');   // обновление Telegram как есть (JSON)
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_relay_queue');
    }
};
