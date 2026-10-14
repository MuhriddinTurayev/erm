<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Файлы объекта привязываются к планировке (типу квартиры из перечня помещений):
 * её чертёж, 3D-интерьер и панорамы 360° комнат. Пусто — как раньше, по числу комнат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('object_media', function (Blueprint $t) {
            $t->string('layout', 40)->nullable()->after('rooms');
            $t->index(['workspace_id', 'project_id', 'layout']);
        });
    }

    public function down(): void
    {
        Schema::table('object_media', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'project_id', 'layout']);
            $t->dropColumn('layout');
        });
    }
};
