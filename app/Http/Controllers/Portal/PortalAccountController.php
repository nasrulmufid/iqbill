<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PortalAccountController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $customer = $user?->customer?->load(['subscriptions.package', 'subscriptions.mikrotikRouter']);

        return view('portal.account', [
            'user' => $user,
            'customer' => $customer,
            'company' => CompanySetting::current(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'email.unique' => 'This email address is already in use by another account.',
        ]);

        $email = !empty($data['email']) ? trim((string) $data['email']) : null;
        $user->update(['email' => $email]);

        if ($user->customer !== null) {
            $user->customer->update(['email' => $email]);
        }

        return back()->with('success', 'Contact email updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password.confirmed' => 'New password confirmation does not match.',
            'password.min' => 'New password must be at least 6 characters.',
        ]);

        if (!Hash::check($data['current_password'], (string) $user->password)) {
            return back()->withErrors([
                'current_password' => 'Current password does not match.',
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        return back()->with('success', 'Portal password updated successfully.');
    }
}
