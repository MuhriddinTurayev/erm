<?php

namespace App\Http\Controllers\Company;

use App\Models\ObjectMedia;
use App\Services\Inventory;
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
            // auto=1 — сразу после загрузки модели: ИИ сам расставляет квартиры, человек проверяет
            'auto' => $canEdit && $request->boolean('auto') && $model,
            'src' => $model ? url('/company/media/'.$model->id.'/file'.(! empty($model->meta['finished']) ? '?v=finished' : '')) : null,
            'saveUrl' => $model ? url('/company/media/'.$model->id.'/locate') : null,
            'aiUrl' => $model && $canEdit ? url('/company/media/'.$model->id.'/locate/ai') : null,
            'back' => $canEdit ? '/company/objects/'.$pid.'?tab=media' : '/company/sales?o='.$pid,
            // «Внутри квартиры»: интерьеры (3D, панорамы, рендеры, планы); в настройке их можно загрузить прямо к выбранной квартире
            'inner' => Locate::media($ws->id, $pid), 'viewUrl' => url('/company/objects/'.$pid.'/3d?embed=1&u={u}'), 'fileUrl' => url('/company/media/{id}/file'),
            'canUnits' => $request->user()->canEdit() && $request->user()->managesObjects(), 'hasInv' => Inventory::has($p),
            'upUrl' => url('/company/objects/'.$pid.'/media'), 'unitUrl' => url('/company/objects/'.$pid.'/units/{id}'),
            'cvt' => ObjectController::convertTexts(), 'mcUrl' => asset('assets/model-convert.js').'?v='.@filemtime(public_path('assets/model-convert.js')),
        ]);
    }

    /** Сохранить рамку корпуса. Автопривязка (auto) не затирает рамку, которую человек уже поставил сам */
    public function save(Request $request, ObjectMedia $media)
    {
        abort_unless($request->user()->canMedia(), 403);
        $this->mine($media);
        $this->checkProject($media->project_id);
        abort_unless($media->kind === 'exterior', 422);
        $d = $request->validate(['block' => ['nullable', 'string', 'max:40'], 'c' => ['required', 'array', 'size:3'], 'c.*' => ['numeric'],
            's' => ['required', 'array', 'size:3'], 's.*' => ['numeric'], 'r' => ['required', 'numeric'], 'floors' => ['required', 'integer', 'min:1', 'max:200'], 'gf' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'rows' => ['required', 'integer', 'in:1,2'], 'back' => ['required', 'in:rev,fwd'], 'flipX' => ['boolean'], 'flipZ' => ['boolean'],
            'ex' => ['nullable', 'array', 'max:60'], 'ex.*' => ['numeric', 'gt:0', 'lt:1'],
            'win' => ['nullable', 'array'], 'win.f' => ['nullable', 'array', 'max:200'], 'win.b' => ['nullable', 'array', 'max:200'],
            'slots' => ['nullable', 'array', 'max:3000'], 'ux' => ['nullable', 'array', 'max:6000'],
            'auto' => ['nullable', 'array'], 'auto.by' => ['nullable', 'in:ai,geo'], 'auto.mf' => ['nullable', 'integer', 'min:0', 'max:300'],
            'auto.doors' => ['nullable', 'integer', 'min:0', 'max:100'], 'auto.warn' => ['nullable', 'array', 'max:8'], 'auto.warn.*' => ['string', 'in:'.implode(',', Locate::WARN)]]);
        $bid = (string) ($d['block'] ?? '');
        $meta = (array) ($media->meta ?? []);
        $old = $meta['locate']['blocks'][$bid] ?? null;
        if (! empty($d['auto']) && $old && empty($old['auto'])) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }
        $meta['locate']['blocks'][$bid] = Locate::clean($d);
        $media->meta = $meta;
        $media->save();

        return response()->json(['ok' => true]);
    }

    /** ИИ смотрит на фасады модели: этажи, входы в подъезды, лицевая сторона (картинку готовит assets/locate-auto.js) */
    public function ai(Request $request, ObjectMedia $media)
    {
        abort_unless($request->user()->canMedia(), 403);
        $this->mine($media);
        $this->checkProject($media->project_id);
        abort_unless($media->kind === 'exterior', 422);
        $d = $request->validate(['image' => ['required', 'string', 'max:4000000'], 'entr' => ['required', 'integer', 'min:1', 'max:100']]);
        $jpeg = base64_decode((string) preg_replace('#^data:image/jpeg;base64,#', '', $d['image']), true);
        abort_unless($jpeg && str_starts_with($jpeg, "\xFF\xD8"), 422);

        return response()->json(Locate::askAi($this->ws(), $request->user(), $jpeg, (int) $d['entr']));
    }
}
