<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Тарифы платформы */
class PlanController extends Controller
{
    public function index()
    {
        return view('admin.plans', ['plans' => Plan::withCount('workspaces')->orderBy('sort')->get()]);
    }

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $loc = array_keys(config('oftob.locales'));
        $rules = [
            'slug' => ['required', 'alpha_dash', 'max:40', Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'price_month' => ['required', 'numeric', 'min:0'],
            'price_year' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'max_objects' => ['required', 'integer', 'min:0'],
            'max_users' => ['required', 'integer', 'min:0'],
            'ai_requests' => ['required', 'integer', 'min:0'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'sort' => ['nullable', 'integer'],
        ];
        foreach ($loc as $l) {
            $rules["name.$l"] = [$l === 'ru' ? 'required' : 'nullable', 'string', 'max:60'];
            $rules["description.$l"] = ['nullable', 'string', 'max:300'];
            $rules["features.$l"] = ['nullable', 'string', 'max:2000'];
        }
        $d = $request->validate($rules);
        $d['features'] = collect($loc)->mapWithKeys(fn ($l) => [$l => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($d['features'][$l] ?? '')))))])->all();
        $d['price_year'] = $d['price_year'] ?? 0;
        $d['sort'] = $d['sort'] ?? 0;
        foreach (['ai_enabled', 'daily_report', 'is_active', 'is_featured'] as $b) {
            $d[$b] = $request->boolean($b);
        }

        return $d;
    }

    public function store(Request $request)
    {
        Plan::create($this->validated($request));

        return back()->with('ok', __('Тариф добавлен'));
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request, $plan));

        return back()->with('ok', __('Сохранено'));
    }

    public function destroy(Plan $plan)
    {
        if ($plan->workspaces()->exists()) {
            return back()->withErrors(['plan' => __('На этом тарифе есть компании — сначала переведите их или отключите тариф')]);
        }
        $plan->delete();

        return back()->with('ok', __('Тариф удалён'));
    }
}
