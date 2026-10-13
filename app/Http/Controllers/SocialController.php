<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\LoginGuard;
use App\Services\Telegram;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Вход через Google (OAuth 2.0) и через Telegram (Login Widget) */
class SocialController extends Controller
{
    private function googleRedirectUri(): string
    {
        return url('/auth/google/callback');
    }

    public function google(Request $request)
    {
        $id = PlatformSetting::get('google_client_id');
        abort_unless($id, 404);
        $state = Str::random(40);
        $request->session()->put('google_state', $state);
        $request->session()->put('google_link', $request->boolean('link') && auth()->check());

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $id, 'redirect_uri' => $this->googleRedirectUri(), 'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $state, 'prompt' => 'select_account', 'access_type' => 'online',
        ]));
    }

    public function googleCallback(Request $request)
    {
        $state = $request->session()->pull('google_state');
        $link = $request->session()->pull('google_link');
        if (! $state || ! hash_equals($state, (string) $request->query('state')) || ! $request->query('code')) {
            return redirect('/login')->withErrors(['email' => __('Вход через Google отменён')]);
        }
        try {
            $tok = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => $request->query('code'), 'client_id' => PlatformSetting::get('google_client_id'), 'client_secret' => PlatformSetting::get('google_client_secret'),
                'redirect_uri' => $this->googleRedirectUri(), 'grant_type' => 'authorization_code',
            ])->throw()->json();
            $info = Http::withToken($tok['access_token'])->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
        } catch (\Throwable $e) {
            report($e);

            return redirect('/login')->withErrors(['email' => __('Google не ответил. Попробуйте ещё раз.')]);
        }
        if (empty($info['sub']) || empty($info['email']) || empty($info['email_verified'])) {
            return redirect('/login')->withErrors(['email' => __('В аккаунте Google нет подтверждённого email')]);
        }
        // привязка из раздела «Безопасность»
        if ($link && auth()->check()) {
            if (User::where('google_id', $info['sub'])->where('id', '!=', auth()->id())->exists()) {
                return redirect('/security')->withErrors(['google' => __('Этот Google-аккаунт уже привязан к другому пользователю')]);
            }
            auth()->user()->forceFill(['google_id' => $info['sub']])->save();

            return redirect('/security')->with('ok', __('Google привязан: :e', ['e' => $info['email']]));
        }
        $user = User::where('google_id', $info['sub'])->first() ?? User::where('email', mb_strtolower($info['email']))->first();
        if (! $user) {
            // аккаунта нет — регистрация компании с данными из Google
            $request->session()->put('google_profile', ['sub' => $info['sub'], 'email' => mb_strtolower($info['email']), 'name' => $info['name'] ?? '']);

            return redirect('/register')->with('ok', __('Аккаунт с :e не найден. Зарегистрируйте компанию — пароль не нужен, вход будет через Google.', ['e' => $info['email']]));
        }

        return $this->finish($request, $user, 'google', ['google_id' => $user->google_id ?: $info['sub']]);
    }

    /** Telegram Login Widget: данные подписаны токеном бота */
    public function telegram(Request $request)
    {
        $data = $request->only(['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash']);
        $hash = (string) ($data['hash'] ?? '');
        unset($data['hash']);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        ksort($data);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($data), $data));
        $calc = hash_hmac('sha256', $check, hash('sha256', Telegram::token(), true));
        if (! Telegram::enabled() || ! $hash || ! hash_equals($calc, $hash) || (int) ($data['auth_date'] ?? 0) < time() - 86400) {
            return redirect('/login')->withErrors(['email' => __('Не удалось войти через Telegram')]);
        }
        $user = User::where('telegram_chat_id', (string) $data['id'])->first();
        if (! $user) {
            return redirect('/login')->withErrors(['email' => __('Этот Telegram не привязан ни к одному аккаунту. Войдите по паролю и привяжите Telegram в разделе «Мой Telegram».')]);
        }

        return $this->finish($request, $user, 'telegram');
    }

    private function finish(Request $request, User $user, string $method, array $fill = [])
    {
        if ($user->is_active === false) {
            return redirect('/login')->withErrors(['email' => __('Ваш доступ отключён. Обратитесь к руководителю.')]);
        }
        if ($fill) {
            $user->forceFill($fill)->save();
        }
        Auth::login($user, true);
        $request->session()->regenerate();
        if ($user->locale) {
            $request->session()->put('locale', $user->locale);
        }
        $request->session()->put('via_social', $method);
        LoginGuard::record($user, $request, $method);

        return redirect()->intended(AuthController::home($user));
    }
}
