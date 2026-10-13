<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Сотрудники компании с доступом к программе. Управляет руководитель. */
class UserController extends Controller
{
    public function index()
    {
        $ws = $this->ws();

        return view('company.users', [
            'ws' => $ws,
            'users' => $ws->users()->orderByRaw("role = 'admin' desc")->orderBy('name')->get(),
            'roles' => config('oftob.roles'),
            'objects' => array_map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? '—'), 'demo' => Workspace::isDemo($p)], $ws->projects()),
        ]);
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(array_keys(config('oftob.roles')))],
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
            'objects' => ['nullable', 'array'],
            'objects.*' => ['string', 'max:40'],
        ];
    }

    /** Руководитель видит все объекты; пустой выбор — тоже все */
    private static function objectIds(array $data): ?array
    {
        return $data['role'] === 'admin' ? null : (($data['objects'] ?? null) ?: null);
    }

    public function store(Request $request)
    {
        $ws = $this->ws();
        $rules = $this->rules();
        // логин — email или телефон; пароль можно не придумывать — программа создаст
        $rules['email'] = ['nullable', 'required_without:phone', 'email', 'max:120', Rule::unique('users', 'email')];
        $rules['phone'] = ['nullable', 'required_without:email', 'string', 'max:40'];
        $rules['password'] = ['nullable', Password::min(8)];
        $data = $request->validate($rules);
        try {
            [$u, $pass, $login] = \App\Services\Accounts::create($ws, $request->user(), $data + ['objects' => self::objectIds($data)]);
        } catch (\DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        }

        return back()->with('creds', ['name' => $u->name, 'login' => $login, 'password' => $pass, 'role' => $u->roleName()]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless((int) $user->workspace_id === (int) $this->ws()->id, 404);
        $data = $request->validate($this->rules($user));
        $self = (int) $user->id === (int) $request->user()->id;
        if ($self && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => __('Нельзя снять с себя роль руководителя')]);
        }
        $user->fill([
            'name' => $data['name'], 'email' => ($data['email'] ?? '') ?: $user->email, 'phone' => $data['phone'] ?? null, 'role' => $data['role'],
            'object_ids' => self::objectIds($data), 'is_active' => $self ? true : $request->boolean('is_active'),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('ok', __('Сохранено: :e', ['e' => $user->email]));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless((int) $user->workspace_id === (int) $this->ws()->id, 404);
        if ((int) $user->id === (int) $request->user()->id) {
            return back()->withErrors(['email' => __('Нельзя удалить самого себя')]);
        }
        $user->delete();

        return back()->with('ok', __('Пользователь удалён'));
    }
}
