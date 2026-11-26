<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ForcePasswordChangeController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null || !$user->requiresInitialPasswordChange()) {
            return redirect()->route('admin.dashboard');
        }

        return view('auth.force-password-change', [
            'user' => $user,
            'companyName' => CompanySetting::current()->company_name ?: config('app.name', 'IQ Bill'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null || !$user->requiresInitialPasswordChange()) {
            return redirect()->route('admin.dashboard');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->numbers(),
            ],
        ], [
            'current_password.required' => 'Please enter your current deployment password.',
            'password.required' => 'Please enter a new password.',
            'password.confirmed' => 'New password confirmation does not match.',
            'password.min' => 'New password must be at least 12 characters long.',
        ]);

        if (!Hash::check($validated['current_password'], (string) $user->password)) {
            return back()->withErrors([
                'current_password' => 'Current password is incorrect.',
            ]);
        }

        if (Hash::check($validated['password'], (string) $user->password)) {
            return back()->withErrors([
                'password' => 'New password must be different from the initial deployment password.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'super_admin_initial_password_changed',
            'auditable_type' => $user::class,
            'auditable_id' => $user->id,
            'old_values' => ['must_change_password' => true],
            'new_values' => ['must_change_password' => false],
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $request->session()->regenerate();
        $request->session()->put('password_hash_' . auth()->getDefaultDriver(), $user->getAuthPassword());
        $user->revokeAllSessions($request->session()->getId());

        return redirect()
            ->route('admin.dashboard')
            ->with('success', 'Super Admin password updated successfully. Initial deployment security setup is complete.');
    }
}
