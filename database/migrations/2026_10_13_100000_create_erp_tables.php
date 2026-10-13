<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP-модули: кассы и валюта, статьи и лимиты, заявки на оплату с цепочкой согласования,
 * контрагенты, договоры, склад, снабжение, тендеры, задачи, сервисные заявки,
 * безопасность входа, платформенные администраторы, блог сайта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->decimal('usd_rate', 14, 4)->default(0)->after('public_enabled');   // курс USD к валюте компании
            $t->json('settings')->nullable()->after('usd_rate');                      // цепочка согласования, права ролей
        });

        Schema::table('users', function (Blueprint $t) {
            $t->json('admin_perms')->nullable()->after('tg_state');      // права администратора платформы
            $t->string('google_id', 64)->nullable()->after('admin_perms');
            $t->boolean('login_alerts')->default(true)->after('google_id');
        });

        Schema::table('site_logs', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable()->after('photos');
            $t->decimal('lng', 10, 7)->nullable()->after('lat');
            $t->unsignedInteger('accuracy')->nullable()->after('lng');
            $t->timestamp('taken_at')->nullable()->after('accuracy');
        });

        // Статьи доходов и расходов с месячным лимитом
        Schema::create('fin_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 4)->default('out');                   // in | out
            $t->decimal('month_limit', 16, 2)->default(0);           // 0 — без лимита
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        // Кассы и счета (наличные, банк, карта) в сумах/сомони или долларах
        Schema::create('cash_desks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('kind', 10)->default('cash');                 // cash | bank | card
            $t->string('currency', 3)->default('UZS');               // UZS | TJS | USD
            $t->string('project_id', 40)->nullable();                // касса объекта
            $t->foreignId('keeper_id')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('opening', 16, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('counterparties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12)->default('supplier');             // supplier | contractor | client | other
            $t->string('folder', 60)->nullable();
            $t->string('name', 160);
            $t->string('inn', 20)->nullable();                       // ИНН / СТИР
            $t->string('phone', 40)->nullable();
            $t->string('contact', 120)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('bank', 255)->nullable();
            $t->string('status', 12)->default('check');              // check | ok | suspicious | blocked
            $t->string('status_reason', 300)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'inn']);
        });

        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $t->string('project_id', 40)->nullable();
            $t->string('number', 40);
            $t->date('date');
            $t->string('subject', 300);
            $t->string('direction', 4)->default('out');              // out — мы платим, in — нам платят
            $t->decimal('amount', 16, 2)->default(0);
            $t->string('currency', 3)->default('UZS');
            $t->date('due_date')->nullable();
            $t->string('status', 12)->default('active');             // draft | active | done | cancelled
            $t->string('didox_id', 64)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Заявки на оплату: заявка → цепочка согласования → оплата из кассы
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('project_id', 40)->nullable();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->decimal('amount', 16, 2);
            $t->string('currency', 3)->default('UZS');
            $t->string('purpose', 300);
            $t->date('due_date')->nullable();
            $t->string('status', 12)->default('pending');            // pending | approved | rejected | paid | cancelled
            $t->unsignedTinyInteger('step')->default(0);             // текущий шаг цепочки
            $t->json('chain')->nullable();                           // роли согласующих на момент создания
            $t->foreignId('cash_op_id')->nullable();
            $t->json('tg')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'status']);
        });

        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('step');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('decision', 10);                              // approve | reject
            $t->string('comment', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Движение денег по кассам
        Schema::create('cash_ops', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cash_desk_id')->constrained()->cascadeOnDelete();
            $t->string('type', 4);                                   // in | out
            $t->date('date');
            $t->decimal('amount', 16, 2);                            // в валюте кассы
            $t->decimal('rate', 14, 4)->default(1);                  // курс к валюте компании
            $t->decimal('amount_base', 18, 2);                       // в валюте компании
            $t->foreignId('category_id')->nullable()->constrained('fin_categories')->nullOnDelete();
            $t->string('project_id', 40)->nullable();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('transfer_key', 16)->nullable();              // пара операций перевода между кассами
            $t->string('employee', 120)->nullable();                 // выплата зарплаты
            $t->string('note', 300)->nullable();
            $t->string('tx_id', 40)->nullable();                     // операция в бухгалтерии объекта
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'date']);
            $t->index('transfer_key');
        });

        // Склад
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('project_id', 40)->nullable();
            $t->foreignId('keeper_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name', 160);
            $t->string('unit', 16)->default('шт');
            $t->string('sku', 40)->nullable();
            $t->string('group', 60)->nullable();
            $t->decimal('min_qty', 14, 3)->default(0);               // предупреждать при остатке ниже
            $t->decimal('price', 16, 2)->default(0);                 // последняя цена закупки
            $t->timestamps();
        });

        // Снабжение: заявка с объекта → согласование → заказ поставщику → приём (частично)
        Schema::create('supply_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->string('project_id', 40)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $t->date('need_by')->nullable();
            $t->string('status', 12)->default('new');                // new | approved | ordered | partial | received | rejected
            $t->string('note', 300)->nullable();
            $t->timestamps();
        });

        Schema::create('supply_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supply_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('price', 16, 2)->default(0);
            $t->decimal('received', 14, 3)->default(0);
        });

        Schema::create('stock_moves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                                  // in | out | transfer | adjust
            $t->decimal('qty', 14, 3);                               // + приход, − расход
            $t->decimal('price', 16, 2)->default(0);
            $t->string('batch', 40)->nullable();                     // партия
            $t->string('project_id', 40)->nullable();                // куда выдано
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supply_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('transfer_key', 16)->nullable();
            $t->string('note', 300)->nullable();
            $t->date('date');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'item_id', 'warehouse_id']);
        });

        Schema::create('tenders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('project_id', 40)->nullable();
            $t->foreignId('supply_request_id')->nullable()->constrained()->nullOnDelete();
            $t->date('deadline')->nullable();
            $t->string('status', 12)->default('open');               // open | review | awarded | cancelled
            $t->unsignedBigInteger('winner_bid_id')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('tender_bids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $t->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 16, 2);
            $t->unsignedSmallInteger('days')->nullable();            // срок поставки / работ
            $t->string('note', 300)->nullable();
            $t->timestamps();
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('project_id', 40)->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_date')->nullable();
            $t->string('priority', 8)->default('normal');            // low | normal | high
            $t->string('status', 10)->default('todo');               // todo | doing | review | done
            $t->unsignedTinyInteger('progress')->default(0);
            $t->timestamp('done_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'status']);
        });

        Schema::create('service_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('project_id', 40)->nullable();
            $t->foreignId('counterparty_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('amount', 16, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->string('status', 10)->default('new');                // new | work | done | cancelled
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        // Комментарии и файлы к любой сущности (задача, заявка, договор, фотоотчёт…)
        Schema::create('comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 30);
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('text');
            $t->timestamps();
            $t->index(['subject', 'subject_id']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 30);
            $t->unsignedBigInteger('subject_id');
            $t->string('path');
            $t->string('name', 200);
            $t->unsignedInteger('size')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['subject', 'subject_id']);
        });

        // Вход: история и известные устройства
        Schema::create('login_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('method', 12)->default('password');           // password | google | telegram
            $t->string('ip', 45)->nullable();
            $t->string('agent', 255)->nullable();
            $t->string('device', 64);                                // отпечаток устройства
            $t->boolean('new_device')->default(false);
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'device']);
        });

        // Блог сайта
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 120)->unique();
            $t->json('title');
            $t->json('excerpt')->nullable();
            $t->json('body');
            $t->string('cover')->nullable();
            $t->boolean('is_published')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['posts', 'login_events', 'attachments', 'comments', 'service_orders', 'tasks', 'tender_bids', 'tenders', 'stock_moves', 'supply_lines',
            'supply_requests', 'items', 'warehouses', 'cash_ops', 'approvals', 'payment_requests', 'contracts', 'counterparties', 'cash_desks', 'fin_categories'] as $tb) {
            Schema::dropIfExists($tb);
        }
        Schema::table('site_logs', fn (Blueprint $t) => $t->dropColumn(['lat', 'lng', 'accuracy', 'taken_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['admin_perms', 'google_id', 'login_alerts']));
        Schema::table('workspaces', fn (Blueprint $t) => $t->dropColumn(['usd_rate', 'settings']));
    }
};
