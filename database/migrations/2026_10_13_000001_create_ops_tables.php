<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Расходы, пришедшие из Telegram или ждущие согласования руководителя
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('project_id', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 12)->default('web');          // web | telegram
            $table->date('date');
            $table->decimal('amount', 16, 2);
            $table->string('cat', 60);
            $table->string('desc', 300)->nullable();
            $table->string('who', 120)->nullable();
            $table->string('receipt')->nullable();                 // путь к фото чека
            $table->string('status', 12)->default('pending');      // pending | approved | rejected
            $table->string('tx_id', 40)->nullable();               // операция в бухгалтерии после проведения
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 300)->nullable();
            $table->json('tg')->nullable();                        // сообщения в Telegram, чтобы обновить кнопки
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        // Журнал работ: фото и записи прораба с объекта
        Schema::create('site_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('project_id', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->text('text')->nullable();
            $table->json('photos')->nullable();
            $table->string('source', 12)->default('telegram');
            $table->timestamps();
            $table->index(['workspace_id', 'date']);
        });

        // Личный кабинет покупателя квартиры
        Schema::create('buyer_links', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('project_id', 40);
            $table->string('apt_id', 40);
            $table->string('tg_chat_id', 40)->nullable();          // покупатель подписался на напоминания
            $table->date('last_reminded')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'project_id', 'apt_id']);
        });

        // История изменений: кто, когда и что изменил
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project', 120)->nullable();
            $table->string('action', 30);                          // tx.add, tx.del, tx.edit, staff.add, apt.status, project.add…
            $table->string('summary', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable()->after('last_login_at');
            $table->string('tg_link_code', 20)->nullable()->after('telegram_chat_id');
            $table->string('tg_project', 40)->nullable()->after('tg_link_code');   // объект по умолчанию для бота
            $table->json('tg_state')->nullable()->after('tg_project');             // ожидаемое действие бота
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->decimal('approval_limit', 16, 2)->default(0)->after('plan_request');  // 0 — согласование выключено
            $table->string('public_key', 24)->nullable()->unique()->after('approval_limit'); // публичная шахматка
            $table->boolean('public_enabled')->default(false)->after('public_key');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn(['approval_limit', 'public_key', 'public_enabled']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['telegram_chat_id', 'tg_link_code', 'tg_project', 'tg_state']));
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('buyer_links');
        Schema::dropIfExists('site_logs');
        Schema::dropIfExists('expenses');
    }
};
