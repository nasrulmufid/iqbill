<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $role = $request->string('role')->toString();
        $search = trim($request->string('search')->toString());

        $users = User::query()
            ->when($role !== '', fn ($q) => $q->where('role', $role))
            ->when($search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'role' => $role,
            'search' => $search,
            'roles' => UserRole::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:25', 'unique:users,phone'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'string', 'min:8'],
        ]);

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return back()->with('success', 'New user created successfully.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:25', Rule::unique('users', 'phone')->ignore($user->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        if ($request->user()?->id === $user->id && $data['role'] !== $user->role->value) {
            return back()->with('error', 'You cannot change your own account role.');
        }

        $passwordChanged = !empty($data['password']);
        $roleChanged = $data['role'] !== $user->role->value;

        $updates = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
        ];

        if ($passwordChanged) {
            $updates['password'] = Hash::make($data['password']);
        }

        $user->update($updates);

        if ($passwordChanged || $roleChanged) {
            $exceptSessionId = $request->user()?->id === $user->id ? $request->session()->getId() : null;
            $user->revokeAllSessions($exceptSessionId);

            if ($passwordChanged && $request->user()?->id === $user->id) {
                $request->session()->regenerate();
                $request->session()->put('password_hash_' . auth()->getDefaultDriver(), $user->getAuthPassword());
            }
        }

        return back()->with('success', 'User details updated successfully.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->id === $user->id) {
            return back()->with('error', 'You cannot deactivate your currently signed-in account.');
        }

        $user->forceFill(['is_active' => !$user->is_active])->save();

        if (!$user->is_active) {
            $user->revokeAllSessions();
        }

        return back()->with('success', $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->id === $user->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->revokeAllSessions();
        $user->delete();

        return back()->with('success', 'User deleted successfully.');
    }
}
