<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initial Security Setup — {{ $companyName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 flex items-center justify-center p-4 antialiased">
    <div class="w-full max-w-lg space-y-6">
        <div class="text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 text-white shadow-lg shadow-amber-500/30">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <div class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-amber-300 ring-1 ring-amber-400/30">
                First-Deployment Security Requirement
            </div>
            <h1 class="mt-3 text-2xl font-bold text-white tracking-tight">Set Super Admin Password</h1>
            <p class="mt-1.5 text-sm text-slate-300">
                For security on initial deployment, you must replace the default Super Admin password before accessing {{ $companyName }}. This step only appears once.
            </p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200/80 p-6 sm:p-8 space-y-5">
            <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 border border-slate-200 px-4 py-3 text-xs">
                <div>
                    <div class="font-semibold text-slate-500 uppercase tracking-wider text-[10px]">Super Admin Account</div>
                    <div class="mt-0.5 font-mono font-semibold text-slate-900">{{ $user->email }}</div>
                </div>
                <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-semibold text-indigo-700 ring-1 ring-indigo-200">
                    SUPER ADMIN
                </span>
            </div>

            @if($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700 space-y-1">
                    @foreach($errors->all() as $error)
                        <p>&bull; {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.password.force.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-700">Current Deployment Password</label>
                    <input id="current_password"
                           type="password"
                           name="current_password"
                           autocomplete="current-password"
                           required
                           autofocus
                           placeholder="Enter initial deployment password"
                           class="mt-1.5 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('current_password') border-rose-500 @enderror">
                    @error('current_password')
                        <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-slate-700">New Super Admin Password</label>
                    <input id="new_password"
                           type="password"
                           name="password"
                           autocomplete="new-password"
                           minlength="12"
                           required
                           placeholder="Minimum 12 characters with letters & numbers"
                           class="mt-1.5 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('password') border-rose-500 @enderror">
                    @error('password')
                        <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Confirm New Password</label>
                    <input id="password_confirmation"
                           type="password"
                           name="password_confirmation"
                           autocomplete="new-password"
                           minlength="12"
                           required
                           placeholder="Re-enter your new password"
                           class="mt-1.5 h-11 w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>

                {{-- Password Policy Checklist --}}
                <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-3.5 text-xs space-y-1.5">
                    <div class="font-semibold text-slate-700">Password Requirements:</div>
                    <ul class="space-y-1 text-slate-600">
                        <li id="req-len" class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                            <span>At least 12 characters</span>
                        </li>
                        <li id="req-letter" class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                            <span>Contains alphabetic characters (A–Z / a–z)</span>
                        </li>
                        <li id="req-number" class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                            <span>Contains at least one number (0–9)</span>
                        </li>
                        <li id="req-match" class="flex items-center gap-2">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                            <span>Confirmation matches new password</span>
                        </li>
                    </ul>
                </div>

                <button type="submit"
                        class="w-full min-h-12 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition">
                    Save New Password &amp; Continue
                </button>
            </form>

            <div class="border-t border-slate-100 pt-4 flex items-center justify-between text-xs text-slate-500">
                <span>Signed in as {{ $user->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="font-semibold text-slate-600 hover:text-rose-600 transition">
                        Sign Out
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const pw = document.getElementById('new_password');
            const confirmPw = document.getElementById('password_confirmation');

            const setRuleState = (id, passed) => {
                const el = document.getElementById(id);
                if (!el) return;
                const dot = el.querySelector('span:first-child');
                el.classList.toggle('text-emerald-700', passed);
                el.classList.toggle('font-medium', passed);
                el.classList.toggle('text-slate-600', !passed);
                if (dot) {
                    dot.classList.toggle('bg-emerald-500', passed);
                    dot.classList.toggle('bg-slate-300', !passed);
                }
            };

            const evaluate = () => {
                const val = pw ? pw.value : '';
                const conf = confirmPw ? confirmPw.value : '';
                setRuleState('req-len', val.length >= 12);
                setRuleState('req-letter', /[a-zA-Z]/.test(val));
                setRuleState('req-number', /[0-9]/.test(val));
                setRuleState('req-match', val.length > 0 && val === conf);
            };

            pw && pw.addEventListener('input', evaluate);
            confirmPw && confirmPw.addEventListener('input', evaluate);
        })();
    </script>
</body>
</html>
