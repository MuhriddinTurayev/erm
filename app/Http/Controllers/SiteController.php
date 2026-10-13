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
        return view('site.home', [
            'plans' => Plan::active()->get(),
            'ai' => AiProvider::where('is_active', true)->pluck('driver')->unique()->values(),
        ]);
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
