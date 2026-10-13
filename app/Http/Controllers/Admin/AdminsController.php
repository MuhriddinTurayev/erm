<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Администраторы платформы (назначает владелец) и назначение руководителей компаний */
class AdminsController extends Controller
{
    public function index()
    {
        return view('admin.admins', [
            'admins' => User::whereIn('role', ['superadmin', 'padmin'])->orderByRaw("role = 'superadmin' desc")->orderBy('name')->get(),
            'perms' => config('oftob.admin_perms'),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', Password::min(10)->letters()->numbers()], 'perms' => ['nullable', 'array'], 'perms.*' => [Rule::in(array_keys(config('oftob.admin_perms')))],
            'owner' => ['nullable', 'boolean'],
        ]);
        User::create(['workspace_id' => null, 'name' => $d['name'], 'email' => mb_strtolower($d['email']), 'password' => $d['password'],
            'role' => $request->boolean('owner') ? 'superadmin' : 'padmin', 'admin_perms' => array_values($d['perms'] ?? []), 'locale' => app()->getLocale()]);

        return back()->with('ok', __('Администратор добавлен: :e', ['e' => $d['email']]));
    }

    public function update(Request $request, User $user)
    {
        abort_unless(in_array($user->role, ['superadmin', 'padmin'], true), 404);
        $d = $request->validate(['perms' => ['nullable', 'array'], 'perms.*' => [Rule::in(array_keys(config('oftob.admin_perms')))],
            'password' => ['nullable', Password::min(10)->letters()->numbers()]]);
        $self = $user->id === $request->user()->id;
        $owner = $request->boolean('owner');
        if ($self && ! $owner) {
            return back()->withErrors(['perms' => __('Нельзя снять права владельца с самого себя')]);
        }
        $user->fill(['role' => $owner ? 'superadmin' : 'padmin', 'admin_perms' => array_values($d['perms'] ?? []), 'is_active' => $self || $request->boolean('is_active')]);
        if (! empty($d['password'])) {
            $user->password = $d['password'];
        }
        $user->save();
        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return back()->with('ok', __('Сохранено: :e', ['e' => $user->email]));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless(in_array($user->role, ['superadmin', 'padmin'], true) && $user->id !== $request->user()->id, 422);
        if ($user->role === 'superadmin' && User::where('role', 'superadmin')->count() < 2) {
            return back()->withErrors(['perms' => __('Должен остаться хотя бы один владелец платформы')]);
        }
        $user->delete();

        return back()->with('ok', __('Администратор удалён'));
    }

    /** Назначить роль сотруднику компании (например, сделать руководителем) */
    public function companyRole(Request $request, Workspace $workspace, User $user)
    {
        abort_unless((int) $user->workspace_id === (int) $workspace->id, 404);
        $role = $request->validate(['role' => ['required', Rule::in(array_keys(config('oftob.roles')))]])['role'];
        if ($user->role === 'admin' && $role !== 'admin' && $workspace->users()->where('role', 'admin')->count() < 2) {
            return back()->withErrors(['role' => __('В компании должен остаться хотя бы один руководитель')]);
        }
        $user->update(['role' => $role, 'object_ids' => $role === 'admin' ? null : $user->object_ids]);
        AuditLog::create(['workspace_id' => $workspace->id, 'user_id' => null, 'action' => 'user.edit',
            'summary' => 'Администратор платформы ('.$request->user()->name.'): '.$user->name.' — '.config('oftob.roles.'.$role)]);

        return back()->with('ok', __('Роль изменена: :n', ['n' => $user->name]));
    }
}
