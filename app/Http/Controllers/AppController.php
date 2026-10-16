<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\PlatformSetting;
use App\Services\StateAccess;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /** Сама программа: интерфейс на JavaScript, данные — с сервера */
    public function index(Request $request)
    {
        $user = $request->user();
        $ws = $user->workspace()->with('plan')->first();
        $plan = $ws->plan;

        return view('app', [
            'ws' => $ws,
            'boot' => [
                'ws' => $ws->id,
                'company' => $ws->name,
                'brand' => PlatformSetting::brand(),
                'version' => (int) $ws->version,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'locale' => app()->getLocale()],
                'roles' => collect(config('oftob.roles'))->map(fn ($r) => __($r)),
                'canEdit' => $user->canEdit(),
                'isAdmin' => $user->isAdmin(),
                'seesMoney' => $user->seesMoney(),
                'pages' => $user->allowedPages(),
                'restricted' => $user->allowedObjects() !== null,
                // загруженные 3D-модели и панорамы по объектам — в «Объект в 3D» и «Дизайн квартиры» показываем кнопки к ним
                'media' => self::mediaCounts($ws->id),
                'canMedia' => $user->canMedia(),
                'stock' => $user->canModule('warehouse'),   // «Материалы» (расчёт) ведут на настоящий склад
                'lists' => \App\Services\Lists::all($ws),   // справочники компании: способы и условия оплаты, источники заявок, единицы
                // деньги в кассах и на счетах (с начальными остатками и переводами) — «Деньги на счету» как в ERP; только тому, кто видит все деньги
                'cashDesks' => $user->seesMoney() && $user->allowedObjects() === null ? \App\Services\Erp\Cash::totalBase($ws) : null,
                'firstObject' => $ws->setting('first_object'),
                'plan' => [
                    'name' => $plan?->title(),
                    'objects' => $ws->maxObjects(),
                    'users' => $ws->maxUsers(),
                    'ai' => $ws->aiAllowed(),
                    'aiLimit' => $ws->aiLimit(),
                    'aiUsed' => $ws->aiUsedThisMonth(),
                    'state' => $ws->state(),
                    'daysLeft' => $ws->daysLeft(),
                ],
                'ai' => AiProvider::active()->get()->map->publicInfo()->values(),
                'aiDefault' => $ws->ai_provider_id,
            ],
            // данные передаём отдельной строкой: это уже готовый JSON
            'state' => StateAccess::forUser($ws->data, $user),
        ]);
    }

    /** [id объекта => [exterior => n, interior => n, pano => n]] */
    private static function mediaCounts(int $wsId): array
    {
        $out = [];
        foreach (\App\Models\ObjectMedia::of($wsId)->whereIn('kind', ['exterior', 'interior', 'pano'])->selectRaw('project_id, kind, count(*) as n')->groupBy('project_id', 'kind')->get() as $r) {
            $out[(string) $r->project_id][$r->kind] = (int) $r->n;
        }
        // для чьих квартир есть загруженный интерьер: [планировка, комнат] — программа покажет его вместо нарисованного
        foreach (\App\Models\ObjectMedia::of($wsId)->whereIn('kind', ['interior', 'pano'])->select('project_id', 'layout', 'rooms')->distinct()->get() as $r) {
            $out[(string) $r->project_id]['in'][] = [$r->layout, $r->rooms];
        }
        // сами файлы интерьеров (3D, 360°, рендеры, планы) — «Фото», «Планировка» и «3D-интерьер» квартиры показывают загруженное, а не примеры программы
        foreach (\App\Models\ObjectMedia::of($wsId)->whereIn('kind', ['interior', 'pano', 'photo', 'plan'])->orderBy('kind')->orderBy('sort')->orderBy('id')
            ->get(['id', 'project_id', 'kind', 'layout', 'rooms', 'title', 'ext', 'meta']) as $m) {
            $out[(string) $m->project_id]['files'][] = array_filter(['id' => $m->id, 'k' => $m->kind, 'l' => $m->layout, 'r' => $m->rooms, 'n' => $m->title,
                'room' => $m->meta['room'] ?? null, 'ext' => $m->ext, 'u' => $m->unitIds() ?: null], fn ($v) => $v !== null);
        }

        return $out;
    }

    /** Смена языка из программы */
    public function locale(Request $request)
    {
        $lang = (string) $request->input('lang');
        abort_unless(array_key_exists($lang, config('oftob.locales')), 422);
        $request->user()->forceFill(['locale' => $lang])->save();
        $request->session()->put('locale', $lang);

        return response()->json(['ok' => true]);
    }
}
