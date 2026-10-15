<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Язык Telegram-бота: выбирается в самом боте (/start, /til) — узбекский, русский или таджикский.
 * Отдельно от языка сайта: сменить язык в боте не значит сменить его в панели.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('tg_locale', 5)->nullable()->after('tg_cart');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('tg_locale'));
    }
};
