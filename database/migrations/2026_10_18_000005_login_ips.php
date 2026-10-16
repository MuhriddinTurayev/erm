<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** История входов: у каждого устройства — с каких IP входили (последние 10), чтобы вход со знакомого устройства, но чужого IP был виден */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_events', function (Blueprint $t) {
            $t->json('ips')->nullable()->after('ip');   // [[ip, время последнего входа], …]
        });
    }

    public function down(): void
    {
        Schema::table('login_events', function (Blueprint $t) {
            $t->dropColumn('ips');
        });
    }
};
