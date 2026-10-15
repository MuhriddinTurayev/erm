<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ключи API выдаёт администратор платформы: кому (партнёр, разработчик, другая программа), для какой компании
 * и с какой ролью — ключ работает как сотрудник с этой ролью, только на выбранных объектах.
 * Вебхук принадлежит ключу: отозвали ключ — перестали уходить и события.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $t) {
            $t->string('role', 20)->nullable()->after('name');            // роль в компании: admin, manager, accountant…
            $t->json('object_ids')->nullable()->after('role');            // только эти объекты (пусто — все объекты роли)
            $t->boolean('read_only')->default(false)->after('object_ids');
            $t->string('contact', 160)->nullable()->after('read_only');   // кому выдан: телефон, email, компания-партнёр
            $t->string('purpose', 300)->nullable()->after('contact');     // зачем
        });
        Schema::table('webhooks', function (Blueprint $t) {
            $t->foreignId('api_token_id')->nullable()->after('workspace_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('api_token_id');
        });
        Schema::table('api_tokens', function (Blueprint $t) {
            $t->dropColumn(['role', 'object_ids', 'read_only', 'contact', 'purpose']);
        });
    }
};
