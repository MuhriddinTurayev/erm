<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Post;

/** Сайт платформы: главная, блог, правовые страницы, sitemap, robots, PWA */
class SiteController extends Controller
{
    public function home()
    {
        $plans = Plan::active()->get();

        return view('site.home', [
            'plans' => $plans,
            'ai' => AiProvider::where('is_active', true)->pluck('driver')->unique()->values(),
            // калькулятор потерь: цена самого доступного тарифа и курс доллара — чтобы сравнить потери с ценой программы
            // таджикскому посетителю — в сомони, остальным — в сумах
            'calc' => ['plan' => (float) ($plans->where('price_month', '>', 0)->min('price_month') ?? 0), 'uzs' => \App\Services\Erp\Money::cbRate('UZS'),
                'base' => app()->getLocale() === 'tg' ? 'tjs' : 'uzs', 'rate' => app()->getLocale() === 'tg' ? \App\Services\Erp\Money::cbRate('TJS') : \App\Services\Erp\Money::cbRate('UZS')],
        ]);
    }

    /** Запись на бесплатный разбор с сайта: заявка видна администратору платформы, приходит в Telegram */
    public function demo(\Illuminate\Http\Request $request)
    {
        // ловушка для ботов: это поле человек не видит и не заполняет
        if (trim((string) $request->input('website')) !== '') {
            return $this->demoDone($request);
        }
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'code' => ['required', \Illuminate\Validation\Rule::in(['998', '992', '7', '996', '993', '994'])],
            'phone' => ['required', 'string', 'max:24'],
            'company' => ['nullable', 'string', 'max:120'],
            'kind' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\SiteLead::KINDS))],
            'objects' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\SiteLead::OBJECTS))],
            'role' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\SiteLead::ROLES))],
            'budget' => ['nullable', 'string', 'max:40'],
        ], [], ['name' => st('Ваше имя'), 'phone' => st('Телефон')]);
        // номер без кода страны: длина строго по стране (Узбекистан, Таджикистан, Кыргызстан — 9 цифр, Россия и Казахстан — 10)
        $len = ['998' => 9, '992' => 9, '7' => 10, '996' => 9, '993' => 8, '994' => 9];
        $code = (string) $request->input('code');
        $digits = preg_replace('/\D/', '', (string) $request->input('phone'));
        if (isset($len[$code]) && strlen($digits) === strlen($code) + $len[$code] && str_starts_with($digits, $code)) {
            $digits = substr($digits, strlen($code));   // вписали номер вместе с кодом страны
        }
        $v->after(function ($v) use ($digits, $len, $code) {
            if (strlen($digits) !== ($len[$code] ?? 9)) {
                $v->errors()->add('phone', st('Проверьте номер телефона'));
            }
        });
        if ($v->fails()) {
            return $request->expectsJson() ? response()->json(['errors' => $v->errors()], 422) : redirect('/#demo')->withErrors($v)->withInput();
        }
        $d = $v->validated();
        // единый вид: +998 90 123 45 67
        $phone = '+'.$d['code'].' '.trim(preg_replace('/^(\d{2,3})(\d{3})(\d{2})(\d{2,3})$/', '$1 $2 $3 $4', $digits));
        // повторная отправка с того же номера в течение 10 минут — не дублируем заявку
        if (! \App\Models\SiteLead::where('phone', $phone)->where('created_at', '>=', now()->subMinutes(10))->exists()) {
            $lead = \App\Models\SiteLead::create(['name' => trim($d['name']), 'phone' => $phone, 'company' => $d['company'] ?? null, 'kind' => $d['kind'] ?? null,
                'objects' => $d['objects'] ?? null, 'role' => $d['role'] ?? null, 'budget' => $d['budget'] ?? null, 'locale' => app()->getLocale(),
                'source' => mb_substr((string) ($request->input('utm') ?: parse_url((string) $request->headers->get('referer'), PHP_URL_HOST)), 0, 120) ?: null, 'ip' => $request->ip()]);
            $this->notifyLead($lead);
        }

        return $this->demoDone($request);
    }

    private function demoDone(\Illuminate\Http\Request $request)
    {
        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect('/#demo')->with('demo_ok', true);
    }

    /** Новая заявка — администраторам платформы в Telegram (у кого он привязан) */
    private function notifyLead(\App\Models\SiteLead $l): void
    {
        if (! \App\Services\Telegram::enabled()) {
            return;
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $text = '🆕 <b>'.__('Заявка с сайта').'</b>'."\n".$e($l->name).' · '.$e($l->phone).($l->company ? "\n🏢 ".$e($l->company) : '')
            .($l->kind ? "\n🏗 ".$e(__(\App\Models\SiteLead::KINDS[$l->kind] ?? $l->kind)) : '').($l->objects ? ' · '.$e(__(\App\Models\SiteLead::OBJECTS[$l->objects] ?? $l->objects)) : '')
            .($l->role ? "\n👤 ".$e(__(\App\Models\SiteLead::ROLES[$l->role] ?? $l->role)) : '').($l->budget ? "\n🧮 ".$e($l->budget) : '');
        $admins = \App\Models\User::whereIn('role', ['superadmin', 'padmin'])->where('is_active', true)->whereNotNull('telegram_chat_id')->get()->filter(fn ($u) => $u->adminCan('companies'));
        foreach ($admins as $u) {
            try {
                \App\Services\Telegram::message($u->telegram_chat_id, $text, [[['text' => __('Открыть'), 'url' => url('/admin/leads')]]]);
            } catch (\Throwable) {
            }
        }
    }

    /** Политика конфиденциальности и условия: свой текст из админки или стандартный */
    public function page(string $slug)
    {
        abort_unless(in_array($slug, ['privacy', 'terms'], true), 404);
        $pages = json_decode((string) PlatformSetting::get('pages'), true) ?: [];
        $custom = $pages[$slug][app()->getLocale()] ?? ($pages[$slug]['ru'] ?? '');

        return view('site.page', ['slug' => $slug, 'custom' => $custom ? \Illuminate\Support\Str::markdown($custom, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null]);
    }

    public function blog()
    {
        return view('site.blog', ['posts' => Post::published()->latest('published_at')->paginate(12)]);
    }

    public function post(string $slug)
    {
        $post = Post::published()->where('slug', $slug)->firstOrFail();

        return view('site.post', ['post' => $post, 'more' => Post::published()->where('id', '!=', $post->id)->latest('published_at')->limit(3)->get()]);
    }

    public function sitemap()
    {
        $urls = [['/', now()->startOfWeek(), 'weekly', '1.0'], ['/blog', Post::published()->max('updated_at') ?? now(), 'weekly', '0.7'],
            ['/privacy', now()->startOfMonth(), 'yearly', '0.3'], ['/terms', now()->startOfMonth(), 'yearly', '0.3'], ['/register', now()->startOfMonth(), 'monthly', '0.6']];
        foreach (Post::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = ['/blog/'.$p->slug, $p->updated_at, 'monthly', '0.6'];
        }

        return response()->view('site.sitemap', ['urls' => $urls, 'locales' => array_keys(config('oftob.locales'))])->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots()
    {
        $txt = "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /crm\nDisallow: /company\nDisallow: /api\nDisallow: /me\nDisallow: /security\nDisallow: /b/\nDisallow: /files\nDisallow: /webhook\n\nSitemap: ".url('/sitemap.xml')."\n";

        return response($txt)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** PWA: программа ставится на телефон как приложение */
    public function manifest()
    {
        return response()->json([
            'name' => PlatformSetting::brand(), 'short_name' => PlatformSetting::brand(), 'start_url' => '/crm', 'scope' => '/', 'display' => 'standalone',
            'background_color' => '#0b1220', 'theme_color' => '#1d4ed8', 'lang' => app()->getLocale(),
            'description' => __('ERP и CRM для строительных компаний'),
            'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']],
            'shortcuts' => [['name' => __('Мониторинг'), 'url' => '/company/monitor'], ['name' => __('Заявки на оплату'), 'url' => '/company/payments'], ['name' => __('Задачи'), 'url' => '/company/tasks']],
        ])->header('Content-Type', 'application/manifest+json');
    }
}
