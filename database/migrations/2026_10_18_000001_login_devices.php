<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * История входов — по устройствам: одно устройство = одна строка (последний вход, сколько раз входили).
 * Повторы, которые уже накопились, сводятся в одну строку на устройство.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $t) {
            $t->timestamp('last_at')->nullable()->after('new_device');      // последний вход с этого устройства
            $t->unsignedInteger('logins')->default(1)->after('last_at');   // сколько раз входили
        });

        $groups = DB::table('login_events')->selectRaw('user_id, device, count(*) n, min(id) first_id, max(id) last_id, max(created_at) last_at')
            ->groupBy('user_id', 'device')->get();
        foreach ($groups as $g) {
            $last = DB::table('login_events')->where('id', $g->last_id)->first();
            DB::table('login_events')->where('id', $g->first_id)->update([
                'last_at' => $g->last_at, 'logins' => (int) $g->n, 'ip' => $last->ip, 'agent' => $last->agent, 'method' => $last->method,
            ]);
            if ($g->n > 1) {
                DB::table('login_events')->where('user_id', $g->user_id)->where('device', $g->device)->where('id', '!=', $g->first_id)->delete();
            }
        }
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $t) {
            $t->dropColumn(['last_at', 'logins']);
        });
    }
};
