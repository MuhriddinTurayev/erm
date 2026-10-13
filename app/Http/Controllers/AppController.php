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
