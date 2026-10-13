<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Тарифы платформы
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->json('name');                                  // {"ru": "...", "uz": "...", "tg": "..."}
            $table->json('description')->nullable();
            $table->decimal('price_month', 14, 2)->default(0);
            $table->decimal('price_year', 14, 2)->default(0);
            $table->string('currency', 10)->default('сум');
            $table->unsignedInteger('max_objects')->default(1);    // 0 — без ограничений
            $table->unsignedInteger('max_users')->default(5);      // 0 — без ограничений
            $table->boolean('ai_enabled')->default(true);
            $table->unsignedInteger('ai_requests')->default(100);  // в месяц, 0 — без ограничений
            $table->boolean('daily_report')->default(true);
            $table->json('features')->nullable();                  // дополнительные пункты для витрины тарифов
            $table->unsignedInteger('trial_days')->default(14);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('name')->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active')->after('plan_id');   // trial | active | suspended
            $table->timestamp('trial_ends_at')->nullable()->after('status');
            $table->timestamp('paid_until')->nullable()->after('trial_ends_at');
            $table->string('phone', 40)->nullable()->after('paid_until');
            $table->string('locale', 5)->default('ru')->after('phone');
            $table->string('timezone', 40)->default('Asia/Tashkent')->after('locale');
            $table->boolean('report_enabled')->default(true)->after('timezone');
            $table->string('report_time', 5)->default('20:00')->after('report_enabled');
            $table->string('report_chats')->nullable()->after('report_time');   // Telegram chat_id через запятую
            $table->string('report_email')->nullable()->after('report_chats');
            $table->string('report_link_code', 20)->nullable()->after('report_email');
            $table->boolean('report_ai')->default(true)->after('report_link_code');
            $table->foreignId('ai_provider_id')->nullable()->after('report_ai');
            $table->text('note')->nullable()->after('ai_provider_id');           // заметка супер-админа
            $table->string('plan_request', 120)->nullable()->after('note');      // заявка компании на смену тарифа
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->change();             // у супер-админа компании нет
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('locale', 5)->nullable()->after('role');
            $table->json('object_ids')->nullable()->after('locale');             // null — все объекты компании
            $table->boolean('is_active')->default(true)->after('object_ids');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        // Подключения к ИИ (добавляет супер-админ): Gemini, DeepSeek, ChatGPT, Claude…
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 20);                 // openai | deepseek | gemini | anthropic | compatible
            $table->string('name', 80);
            $table->text('api_key')->nullable();          // хранится зашифрованным
            $table->string('model', 120);
            $table->string('base_url')->nullable();
            $table->unsignedInteger('max_tokens')->default(4000);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('driver', 20);
            $table->string('model', 120);
            $table->string('kind', 20)->default('chat'); // chat | report | test
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('ms')->default(0);
            $table->boolean('ok')->default(true);
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        // Ежедневные отчёты руководителю
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('stats');
            $table->text('ai_summary')->nullable();
            $table->boolean('sent_telegram')->default(false);
            $table->boolean('sent_email')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'date']);
        });

        // Оплаты подписки (вносит супер-админ)
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10)->default('сум');
            $table->unsignedInteger('months')->default(1);
            $table->string('method', 20)->default('transfer');   // cash | card | transfer | click | payme
            $table->string('note')->nullable();
            $table->date('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Настройки платформы (ключ — значение)
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_providers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'locale', 'object_ids', 'is_active', 'last_login_at']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['status', 'trial_ends_at', 'paid_until', 'phone', 'locale', 'timezone', 'report_enabled', 'report_time',
                'report_chats', 'report_email', 'report_link_code', 'report_ai', 'ai_provider_id', 'note', 'plan_request']);
        });
        Schema::dropIfExists('plans');
    }
};
