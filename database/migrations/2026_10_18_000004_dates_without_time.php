<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * На SQLite даты записывались как «2026-10-15 00:00:00», а сравниваются с «2026-10-15»: «потрачено сегодня» было 0,
 * отбор «по дату» терял последний день. Теперь даты пишутся без времени (App\Casts\DateOnly) — приводим и старые записи.
 * На MySQL колонки DATE и так без времени — менять нечего.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'cash_ops' => ['date'], 'stock_moves' => ['date'], 'contracts' => ['date', 'due_date'], 'daily_reports' => ['date'], 'expenses' => ['date'],
        'payment_requests' => ['due_date'], 'service_orders' => ['due_date'], 'site_logs' => ['date'], 'subscription_payments' => ['paid_at'],
        'supply_requests' => ['need_by'], 'tasks' => ['due_date'], 'tenders' => ['deadline'], 'buyer_links' => ['last_reminded'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach (self::COLUMNS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $c) {
                if (Schema::hasColumn($table, $c)) {
                    DB::table($table)->where($c, 'like', '____-__-__ %')->update([$c => DB::raw("substr($c, 1, 10)")]);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }
};
