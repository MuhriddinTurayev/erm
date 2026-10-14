<?php

namespace App\Http\Controllers\Company;

use App\Models\ObjectMedia;
use App\Services\Locate;
use App\Services\StateWriter;
use Illuminate\Http\Request;

/** Квартира на 3D-модели дома: показ (продажи) и привязка корпусов к модели (администратор объектов, руководитель) */
class LocateController extends ErpController
{
    public function show(Request $request, string $pid)
    {
        $ws = $this->ws();
        $this->checkProject($pid);
        $p = StateWriter::project($ws, $pid) ?? abort(404);
        $model = Locate::model($ws->id, $pid, (int) $request->query('m') ?: null);
        $canEdit = $request->user()->canMedia();

        return view('company.objects.locate', [
            'p' => $p, 'pid' => $pid, 'model' => $model, 'data' => Locate::data($p),
            'unit' => (string) $request->query('u', ''), 'embed' => $request->boolean('embed'),
            'calibrate' => $canEdit && $request->boolean('calibrate') && $model, 'canEdit' => $canEdit && $model,
            'src' => $model ? url('/company/media/'.$model->id.'/file'.(! empty($model->meta['finished']) ? '?v=finished' : '')) : null,
            'saveUrl' => $model ? url('/company/media/'.$model->id.'/locate') : null,
            'back' => $canEdit ? '/company/objects/'.$pid.'?tab=media' : '/company/sales?o='.$pid,
        ]);
    }

    /** Сохранить рамку корпуса */
    public function save(Request $request, ObjectMedia $media)
    {
        abort_unless($request->user()->canMedia(), 403);
        $this->mine($media);
        $this->checkProject($media->project_id);
        abort_unless($media->kind === 'exterior', 422);
        $d = $request->validate(['block' => ['nullable', 'string', 'max:40'], 'c' => ['required', 'array', 'size:3'], 'c.*' => ['numeric'],
            's' => ['required', 'array', 'size:3'], 's.*' => ['numeric'], 'r' => ['required', 'numeric'], 'floors' => ['required', 'integer', 'min:1', 'max:200'], 'gf' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'rows' => ['required', 'integer', 'in:1,2'], 'back' => ['required', 'in:rev,fwd'], 'flipX' => ['boolean'], 'flipZ' => ['boolean']]);
        $meta = (array) ($media->meta ?? []);
        $meta['locate']['blocks'][(string) ($d['block'] ?? '')] = Locate::clean($d);
        $media->meta = $meta;
        $media->save();

        return response()->json(['ok' => true]);
    }
}
