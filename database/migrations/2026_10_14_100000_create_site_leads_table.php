<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Заявки с сайта платформы: запись на бесплатный разбор и демонстрацию */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_leads', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('phone', 32);
            $t->string('company', 120)->nullable();
            $t->string('kind', 40)->nullable();        // что строят
            $t->string('objects', 20)->nullable();     // сколько объектов
            $t->string('role', 40)->nullable();        // должность
            $t->string('budget', 40)->nullable();      // бюджет из калькулятора (если считали)
            $t->string('locale', 5)->nullable();
            $t->string('source', 120)->nullable();     // utm / откуда пришёл
            $t->string('ip', 45)->nullable();
            $t->string('status', 12)->default('new'); // new | called | done | spam
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_leads');
    }
};
