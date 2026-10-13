<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ключи подключений Meta (Facebook, Instagram, WhatsApp) и сайта
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('hook_id', 40)->unique();     // часть адреса вебхука: /webhook/meta/{hook_id}
            $table->string('verify_token', 60);
            $table->string('api_key', 40)->unique();     // ключ для заявок с сайта: /api/lead?key=
            $table->text('app_secret')->nullable();      // хранится зашифрованным
            $table->text('page_token')->nullable();      // хранится зашифрованным
            $table->string('public_url')->nullable();
            $table->timestamps();
        });

        // Входящие заявки и сообщения
        Schema::create('lead_inbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('ch', 20);                    // facebook, messenger, instagram, whatsapp, site
            $table->string('kind', 10)->default('msg');  // form | msg
            $table->string('key', 120);                  // один клиент = один ключ (повторные сообщения идут в историю)
            $table->string('name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('user', 80)->nullable();      // имя в Instagram
            $table->string('src', 40)->nullable();
            $table->string('rooms', 2)->nullable();
            $table->unsignedInteger('budget')->nullable();
            $table->text('text')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'id']);
        });

        Schema::create('integration_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('msg', 300);
            $table->timestamps();
        });

        // Личные ссылки сотрудников для отметки «Пришёл / Ушёл»
        Schema::create('worker_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('project_id', 40);
            $table->string('staff_id', 40);
            $table->string('name', 80)->nullable();
            $table->string('role', 60)->nullable();
            $table->timestamps();
        });

        // Где находится каждый объект (центр и радиус)
        Schema::create('work_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('project_id', 40);
            $table->string('name')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('radius')->default(150);
            $table->timestamps();
            $table->unique(['workspace_id', 'project_id']);
        });

        // Отметки с телефонов: пришёл, ушёл, сигнал каждые 5 минут
        Schema::create('checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->index();
            $table->string('project_id', 40);
            $table->string('staff_id', 40);
            $table->string('type', 5);                   // in | out | ping
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedInteger('acc')->default(0);
            $table->unsignedInteger('dist')->nullable();
            $table->boolean('on_site')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkins');
        Schema::dropIfExists('work_sites');
        Schema::dropIfExists('worker_tokens');
        Schema::dropIfExists('integration_errors');
        Schema::dropIfExists('lead_inbox');
        Schema::dropIfExists('integrations');
    }
};
