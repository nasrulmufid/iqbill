<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->requiresInitialPasswordChange()) {
            if ($request->routeIs('admin.password.force.edit', 'admin.password.force.update', 'logout')) {
                return $next($request);
            }

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(403, 'Initial Super Admin password change is required before continuing.');
            }

            return redirect()->route('admin.password.force.edit');
        }

        return $next($request);
    }
}
