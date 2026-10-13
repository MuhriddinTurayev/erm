<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (User::count() === 0) {
            return redirect('/setup');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:120'],   // email или телефон
            'password' => ['required', 'string'],
        ]);

        $user = \App\Services\Accounts::find($data['email'], $data['password']);
        if (! $user) {
            return back()->withErrors(['email' => __('Неверный логин или пароль')])->onlyInput('email');
        }
        Auth::login($user, $request->boolean('remember'));
        if ($user->is_active === false) {
            Auth::logout();

            return back()->withErrors(['email' => __('Ваш доступ отключён. Обратитесь к руководителю.')])->onlyInput('email');
        }
        $request->session()->regenerate();
        if ($user->locale) {
            $request->session()->put('locale', $user->locale);
        }
        \App\Services\LoginGuard::record($user, $request);

        return redirect()->intended(self::home($user));
    }

    public static function home(User $user): string
    {
        return $user->isSuperAdmin() ? '/admin' : '/crm';
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /* ---------- Регистрация компании ---------- */

    public function showRegister(Request $request)
    {
        abort_unless(PlatformSetting::get('registration_open') === '1', 404);
        $plans = Plan::active()->get();

        return view('auth.register', [
            'plans' => $plans,
            'selected' => $request->query('plan', PlatformSetting::get('default_plan')),
        ]);
    }

    public function register(Request $request)
    {
        abort_unless(PlatformSetting::get('registration_open') === '1', 404);
        // регистрация через Google: email подтверждён Google, пароль не нужен
        $gp = $request->session()->get('google_profile');
        $viaGoogle = $gp && mb_strtolower((string) $request->input('email')) === $gp['email'];
        $data = $request->validate([
            'company' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => [$viaGoogle ? 'nullable' : 'required', 'confirmed', Password::min(8)],
            'plan' => ['nullable', 'string'],
            'first_object' => ['nullable', 'string', 'max:120'],
        ]);
        if ($viaGoogle) {
            $data['password'] = \Illuminate\Support\Str::password(24);
            $request->session()->forget('google_profile');
        }
        $plan = Plan::active()->where('slug', $data['plan'] ?? '')->first() ?? Plan::active()->where('slug', PlatformSetting::get('default_plan'))->first() ?? Plan::active()->first();
        $locale = app()->getLocale();

        $user = DB::transaction(function () use ($data, $plan, $locale, $viaGoogle, $gp) {
            $ws = Workspace::create([
                'name' => $data['company'],
                'version' => 0,
                'plan_id' => $plan?->id,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays($plan?->trial_days ?: 14)->endOfDay(),
                'phone' => $data['phone'],
                'locale' => $locale,
                'report_email' => $data['email'],
                'settings' => ['first_object' => trim((string) ($data['first_object'] ?? '')) ?: null],
            ]);

            return User::create([
                'workspace_id' => $ws->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => 'admin',
                'locale' => $locale,
                'last_login_at' => now(),
                'google_id' => $viaGoogle ? $gp['sub'] : null,
            ]);
        });

        Auth::login($user, true);
        $request->session()->regenerate();
        if ($viaGoogle) {
            $request->session()->put('via_social', 'google');
        }
        \App\Services\LoginGuard::record($user, $request, $viaGoogle ? 'google' : 'password');

        return redirect('/crm')->with('welcome', 1);
    }

    /* ---------- Первый запуск: создаём администратора платформы ---------- */

    public function showSetup()
    {
        abort_if(User::count() > 0, 404);

        return view('auth.setup');
    }

    public function setup(Request $request)
    {
        abort_if(User::count() > 0, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'workspace_id' => null,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'superadmin',
            'locale' => app()->getLocale(),
        ]);

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect('/admin');
    }

    /* ---------- Супер-админ вошёл в компанию — вернуться обратно ---------- */

    public function stopImpersonate(Request $request)
    {
        $id = $request->session()->pull('impersonator');
        $admin = $id ? User::find($id) : null;
        abort_unless($admin && $admin->isSuperAdmin(), 403);
        Auth::login($admin);
        $request->session()->regenerate();

        return redirect('/admin/companies');
    }
}
