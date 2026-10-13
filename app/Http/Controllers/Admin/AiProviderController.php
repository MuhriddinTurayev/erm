<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Services\Ai\AiException;
use App\Services\Ai\AiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Подключения к ИИ: Gemini, ChatGPT, DeepSeek, Claude, OpenAI-совместимые */
class AiProviderController extends Controller
{
    public function index()
    {
        $month = now()->startOfMonth();

        return view('admin.ai', [
            'providers' => AiProvider::orderByDesc('is_default')->orderBy('sort')->orderBy('id')->get(),
            'drivers' => AiProvider::DRIVERS,
            'usage' => AiUsage::where('created_at', '>=', $month)->selectRaw('ai_provider_id, count(*) as n, sum(ok) as okn, sum(input_tokens) as tin, sum(output_tokens) as tout, avg(ms) as ms')
                ->groupBy('ai_provider_id')->get()->keyBy('ai_provider_id'),
            'byCompany' => AiUsage::with('workspace')->where('created_at', '>=', $month)->whereNotNull('workspace_id')->selectRaw('workspace_id, count(*) as n, sum(input_tokens + output_tokens) as tok')
                ->groupBy('workspace_id')->orderByDesc('n')->limit(15)->get(),
            'aiErrors' => AiUsage::with('workspace')->where('ok', false)->latest()->limit(10)->get(),
        ]);
    }

    private function validated(Request $request, ?AiProvider $p = null): array
    {
        $d = $request->validate([
            'driver' => ['required', Rule::in(array_keys(AiProvider::DRIVERS))],
            'name' => ['required', 'string', 'max:80'],
            'api_key' => [$p ? 'nullable' : 'required', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120'],
            'base_url' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('driver') === 'compatible')],
            'max_tokens' => ['required', 'integer', 'min:256', 'max:64000'],
            'sort' => ['nullable', 'integer'],
        ]);
        if ($p && empty($d['api_key'])) {
            unset($d['api_key']);   // пустое поле — ключ не меняется
        }
        if ($d['driver'] !== 'compatible') {
            $d['base_url'] = null;   // у Gemini, ChatGPT, DeepSeek, Claude адрес API свой и постоянный
        }
        $d['sort'] = $d['sort'] ?? 0;
        $d['is_active'] = $request->boolean('is_active');
        $d['is_default'] = $request->boolean('is_default');

        return $d;
    }

    public function store(Request $request)
    {
        $p = AiProvider::create($this->validated($request));
        $this->singleDefault($p);

        return back()->with('ok', __('Подключение добавлено. Нажмите «Проверить».'));
    }

    public function update(Request $request, AiProvider $provider)
    {
        $provider->update($this->validated($request, $provider));
        $this->singleDefault($provider);

        return back()->with('ok', __('Сохранено'));
    }

    private function singleDefault(AiProvider $p): void
    {
        if ($p->is_default) {
            AiProvider::whereKeyNot($p->id)->update(['is_default' => false]);
        }
    }

    public function destroy(AiProvider $provider)
    {
        $provider->delete();

        return back()->with('ok', __('Подключение удалено'));
    }

    /** Короткий проверочный запрос */
    public function test(Request $request, AiProvider $provider, AiService $ai)
    {
        try {
            // ответ — на языке интерфейса администратора
            $lang = ['uz' => "Uzbek (Latin script, O'zbek tili)", 'tg' => 'Tajik (Cyrillic, забони тоҷикӣ)'][app()->getLocale()] ?? 'Russian';
            $r = $ai->ask(null, $request->user(), 'test', "Reply with exactly one short sentence in {$lang}.",
                [['role' => 'user', 'content' => 'Say that the connection works and name your model.']], $provider->id, 300);
        } catch (AiException $e) {
            return back()->withErrors(['ai' => $provider->name.': '.$e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['ai' => $provider->name.': '.__('Ошибка сервиса ИИ').' — '.mb_substr($e->getMessage(), 0, 200)]);
        }
        if (! $provider->is_active) {
            return back()->with('ok', $provider->name.' ✓ '.$r['text'].' — '.__('подключение выключено, включите его'));
        }

        return back()->with('ok', $provider->name.' ✓ '.$r['text']);
    }
}
