<?php

namespace App\Http\Controllers;

use App\Models\LoginEvent;
use App\Services\LoginGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/** Безопасность аккаунта: активные сеансы, история входов, пароль, оповещения, привязка Google */
class SecurityController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $cur = $request->session()->getId();
        $sessions = DB::table('sessions')->where('user_id', $u->id)->orderByDesc('last_activity')->get()->map(function ($s) use ($cur) {
            [$b, $os, $kind] = LoginGuard::device($s->user_agent);

            return (object) ['id' => $s->id, 'browser' => $b, 'os' => $os, 'kind' => $kind, 'ip' => $s->ip_address, 'at' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity), 'current' => $s->id === $cur];
        });

        return view('security', [
            'u' => $u, 'sessions' => $sessions,
            'events' => LoginEvent::where('user_id', $u->id)->latest('id')->limit(30)->get()->each(fn ($e) => [$e->browser, $e->os] = LoginGuard::device($e->agent)),
            'google' => (bool) \App\Models\PlatformSetting::get('google_client_id'),
        ]);
    }

    public function revoke(Request $request, string $id)
    {
        abort_if($id === $request->session()->getId(), 422);
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();

        return back()->with('ok', __('Сеанс завершён'));
    }

    public function revokeOthers(Request $request)
    {
        $n = DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        // «запомнить меня» на других устройствах тоже перестаёт работать
        $request->user()->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();

        return back()->with('ok', __('Завершено сеансов: :n', ['n' => $n]));
    }

    public function password(Request $request)
    {
        $u = $request->user();
        $request->validate([
            'current' => [$request->session()->has('via_social') ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        if (! $request->session()->has('via_social') && ! Hash::check((string) $request->input('current'), $u->password)) {
            return back()->withErrors(['current' => __('Текущий пароль неверный')]);
        }
        $u->forceFill(['password' => $request->input('password'), 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        DB::table('sessions')->where('user_id', $u->id)->where('id', '!=', $request->session()->getId())->delete();

        return back()->with('ok', __('Пароль изменён. На других устройствах нужно войти заново.'));
    }

    public function alerts(Request $request)
    {
        $request->user()->forceFill(['login_alerts' => $request->boolean('login_alerts')])->save();

        return back()->with('ok', __('Сохранено'));
    }

    public function unlinkGoogle(Request $request)
    {
        $request->user()->forceFill(['google_id' => null])->save();

        return back()->with('ok', __('Google отвязан'));
    }
}
