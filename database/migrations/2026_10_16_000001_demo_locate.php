<?php

use App\Models\ObjectMedia;
use App\Services\Locate;
use Illuminate\Database\Migrations\Migration;

/** Уже созданным демо-объектам «Навруз» — привязка дома к 3D-модели квартала (квартира подсвечивается сразу) */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['nvz-park', 'nvz-tower', 'nvz-city'] as $pid) {
            foreach (ObjectMedia::where('project_id', $pid)->where('kind', 'exterior')->get() as $m) {
                $meta = (array) ($m->meta ?? []);
                // только демо-сцены квартала (с вариантом «готовый дом» и днём/ночью) и только если привязку ещё не настраивали
                if (empty($meta['daynight']) || empty($meta['finished']) || ! empty($meta['locate'])) {
                    continue;
                }
                $meta['locate'] = Locate::demo($pid);
                $m->meta = $meta;
                $m->save();
            }
        }
    }

    public function down(): void {}
};
