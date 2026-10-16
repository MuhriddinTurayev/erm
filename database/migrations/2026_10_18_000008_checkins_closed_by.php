<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Смену может закрыть руководитель (сотрудник забыл нажать «Ушёл», сел телефон): кто закрыл и почему */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->string('closed_by', 80)->nullable();
            $table->string('note', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checkins', function (Blueprint $table) {
            $table->dropColumn(['closed_by', 'note']);
        });
    }
};
