<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Post;
use App\Services\SiteText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Сайт: любой текст на трёх языках, правовые страницы, SEO и аналитика, блог */
class SiteContentController extends Controller
{
    public function index(Request $request)
    {
        $keys = SiteText::keys();
        $locales = array_keys(config('oftob.locales'));
        $saved = SiteText::all();
        // значение по умолчанию на каждом языке — обычный перевод
        $def = [];
        $prev = App::getLocale();
        foreach ($locales as $l) {
            App::setLocale($l);
            foreach ($keys as $list) {
                foreach ($list as $k) {
                    $def[$l][$k] = __($k);
                }
            }
        }
        App::setLocale($prev);
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            foreach ($keys as $sec => $list) {
                $keys[$sec] = array_values(array_filter($list, fn ($k) => collect($locales)->contains(fn ($l) => mb_stripos($saved[$l][$k] ?? $def[$l][$k], $q) !== false)));
            }
            $keys = array_filter($keys);
        }

        return view('admin.site', [
            'keys' => $keys, 'locales' => $locales, 'saved' => $saved, 'def' => $def, 'q' => $q,
            'changed' => array_sum(array_map('count', $saved)),
            'pages' => json_decode((string) PlatformSetting::get('pages'), true) ?: [],
            'seo' => ['ga_id' => PlatformSetting::get('ga_id'), 'ym_id' => PlatformSetting::get('ym_id'), 'seo_image' => PlatformSetting::get('seo_image')],
        ]);
    }

    /** Сохранить тексты раздела: пустое поле или равное переводу — вернуть стандартный текст */
    public function save(Request $request)
    {
        $in = (array) $request->input('t', []);
        $data = SiteText::all();
        $prev = App::getLocale();
        foreach ($in as $l => $rows) {
            if (! array_key_exists($l, config('oftob.locales')) || ! is_array($rows)) {
                continue;
            }
            App::setLocale($l);
            foreach ($rows as $hk => $v) {
                $k = base64_decode($hk);
                $v = trim((string) $v);
                if ($v === '' || $v === __($k)) {
                    unset($data[$l][$k]);
                } else {
                    $data[$l][$k] = mb_substr($v, 0, 5000);
                }
            }
        }
        App::setLocale($prev);
        SiteText::save($data);

        return back()->with('ok', __('Тексты сайта сохранены'));
    }

    public function reset(Request $request)
    {
        $k = base64_decode((string) $request->input('k'));
        $data = SiteText::all();
        foreach ($data as $l => $rows) {
            unset($data[$l][$k]);
        }
        SiteText::save($data);

        return back()->with('ok', __('Возвращён стандартный текст'));
    }

    public function pages(Request $request)
    {
        $d = $request->validate(['pages' => ['array'], 'pages.*.*' => ['nullable', 'string', 'max:60000']]);
        PlatformSetting::put('pages', json_encode($d['pages'] ?? [], JSON_UNESCAPED_UNICODE));

        return back()->with('ok', __('Сохранено'));
    }

    public function seo(Request $request)
    {
        $d = $request->validate(['ga_id' => ['nullable', 'string', 'max:30', 'regex:/^[A-Z0-9\-]*$/'], 'ym_id' => ['nullable', 'digits_between:4,12'],
            'image' => ['nullable', 'image', 'max:4096']]);
        PlatformSetting::put('ga_id', $d['ga_id'] ?? '');
        PlatformSetting::put('ym_id', $d['ym_id'] ?? '');
        if ($request->hasFile('image')) {
            PlatformSetting::put('seo_image', $this->upload($request->file('image'), 'og'));
        }

        return back()->with('ok', __('Сохранено'));
    }

    private function upload($file, string $dir): string
    {
        $name = Str::lower(Str::random(16)).'.'.$file->extension();
        $file->move(public_path('uploads/'.$dir), $name);

        return '/uploads/'.$dir.'/'.$name;
    }

    /* ---------------- Блог ---------------- */

    public function posts()
    {
        return view('admin.posts', ['posts' => Post::latest('id')->paginate(30), 'edit' => null]);
    }

    public function postEdit(Post $post)
    {
        return view('admin.posts', ['posts' => Post::latest('id')->paginate(30), 'edit' => $post]);
    }

    public function postSave(Request $request, ?Post $post = null)
    {
        $d = $request->validate([
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9\-]*$/', Rule::unique('posts', 'slug')->ignore($post?->id)],
            'title' => ['required', 'array'], 'title.ru' => ['required', 'string', 'max:200'], 'title.*' => ['nullable', 'string', 'max:200'],
            'excerpt' => ['array'], 'excerpt.*' => ['nullable', 'string', 'max:500'], 'body' => ['array'], 'body.*' => ['nullable', 'string', 'max:100000'],
            'cover' => ['nullable', 'image', 'max:4096'], 'published_at' => ['nullable', 'date'],
        ]);
        $d['slug'] = ($d['slug'] ?? '') ?: Str::slug(Str::transliterate($d['title']['ru'])) ?: Str::lower(Str::random(8));
        if (! $post && Post::where('slug', $d['slug'])->exists()) {
            $d['slug'] .= '-'.Str::lower(Str::random(4));
        }
        $data = ['slug' => $d['slug'], 'title' => $d['title'], 'excerpt' => $d['excerpt'] ?? [], 'body' => $d['body'] ?? [],
            'is_published' => $request->boolean('is_published'), 'published_at' => $d['published_at'] ?? ($post?->published_at ?? now())];
        if ($request->hasFile('cover')) {
            $data['cover'] = $this->upload($request->file('cover'), 'blog');
        }
        $post ? $post->update($data) : $post = Post::create($data);

        return redirect('/admin/posts/'.$post->id)->with('ok', __('Статья сохранена'));
    }

    public function postDelete(Post $post)
    {
        $post->delete();

        return redirect('/admin/posts')->with('ok', __('Статья удалена'));
    }
}
