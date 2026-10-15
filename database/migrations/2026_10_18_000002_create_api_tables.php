<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Открытый API для других программ: ключи доступа с правами по разделам,
 * вебхуки (сообщаем внешней программе о событиях) и журнал их отправки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // кто выпустил ключ
            $t->string('name', 80);
            $t->char('token_hash', 64)->unique();                                  // sha256 ключа; сам ключ показывается один раз
            $t->string('hint', 16);                                                // «oft_…AbCd» — узнать ключ в списке
            $t->json('scopes');
            $t->timestamp('last_used_at')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'revoked_at']);
        });

        Schema::create('webhooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('url', 500);
            $t->text('secret');                                                    // зашифрован; им подписываем каждую отправку
            $t->json('events');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('fails')->default(0);                              // неудачных отправок подряд
            $t->unsignedSmallInteger('last_status')->nullable();
            $t->timestamp('last_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $t->uuid('uuid');
            $t->string('event', 40);
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->unsignedSmallInteger('status')->nullable();                        // HTTP-ответ получателя
            $t->boolean('ok')->default(false);
            $t->string('error', 300)->nullable();
            $t->unsignedInteger('ms')->nullable();
            $t->timestamps();
            $t->index(['webhook_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_tokens');
    }
};
