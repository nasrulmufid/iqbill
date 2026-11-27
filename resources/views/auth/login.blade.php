<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — IQ Bill</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 flex items-center justify-center p-4 antialiased">
    <div class="w-full max-w-md space-y-6">
        <div class="text-center">
            <img src="{{ asset('images/iqbill-logo-192.png') }}"
                 alt="IQ Bill Logo"
                 width="64"
                 height="64"
                 class="mx-auto h-16 w-16 rounded-full object-contain ring-2 ring-white/20 shadow-lg shadow-indigo-950/60">
            <h1 class="mt-4 text-2xl font-bold text-white tracking-tight">IQ Bill</h1>
            <p class="mt-1 text-xs font-medium text-indigo-300">Billing RT/RW Net &amp; Management PPPOE Mikrotik REST API</p>
            <p class="mt-1.5 text-sm text-slate-400">Sign in to Customer Portal or Operations Backoffice</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200/80 p-6 sm:p-8">
            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email or Portal Username</label>
                    <input id="email" type="text" name="email" value="{{ old('email') }}" required autofocus
                           placeholder="name@domain.com or 261117001"
                           class="mt-1.5 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('email')
                        <p class="mt-1.5 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                    <input id="password" type="password" name="password" required
                           placeholder="••••••••"
                           class="mt-1.5 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Remember me
                    </label>
                </div>

                <button type="submit"
                        class="w-full min-h-12 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                    Sign In
                </button>
            </form>

            @if(app()->environment(['local', 'testing']))
                <div class="mt-6 rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-xs text-slate-600">
                    <div class="font-semibold text-slate-800">Local Demo Account:</div>
                    <div class="mt-1 font-mono">admin@iqbill.local / password</div>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
