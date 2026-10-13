<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 3D-модели, панорамы 360°, планировки и рендеры — у каждого объекта свои */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('object_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('project_id', 40);
            $t->string('kind', 12);                          // exterior | interior | pano | plan | photo | video
            $t->unsignedTinyInteger('rooms')->nullable();    // для какой планировки (комнат); null — для всех
            $t->string('title', 160);
            $t->string('path');
            $t->string('ext', 8);
            $t->unsignedBigInteger('size')->default(0);
            $t->string('poster')->nullable();                // превью для 3D-модели
            $t->boolean('is_public')->default(true);         // показывать покупателям и на сайте
            $t->boolean('is_main')->default(false);          // главная модель объекта
            $t->unsignedSmallInteger('sort')->default(0);
            $t->json('meta')->nullable();                    // настройки вида: камера, освещение, высота
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['workspace_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('object_media');
    }
};
