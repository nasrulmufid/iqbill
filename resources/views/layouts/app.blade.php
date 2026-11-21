@php
    $setting = \App\Models\CompanySetting::query()->select(['company_name', 'sumopod_environment'])->first();
    $companyName = $setting?->company_name ?: config('app.name', 'IQ Bill');
    $sumopodEnv = strtoupper($setting?->sumopod_environment ?? 'sandbox');
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Dashboard' }} — {{ $companyName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased"
      x-data="{ sidebarOpen: false, copiedToast: false }"
      @keydown.escape.window="sidebarOpen = false"
      @notify-copy.window="copiedToast = true; setTimeout(() => copiedToast = false, 2000)">

    {{-- Mobile Backdrop --}}
    <div x-show="sidebarOpen"
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm lg:hidden"
         style="display: none;"></div>

    {{-- Mobile Off-Canvas Sidebar --}}
    <aside x-show="sidebarOpen"
           x-transition:enter="transition ease-in-out duration-200 transform"
           x-transition:enter-start="-translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in-out duration-200 transform"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="-translate-x-full"
           class="fixed inset-y-0 left-0 z-50 w-72 bg-slate-900 text-slate-100 flex flex-col shadow-2xl lg:hidden"
           style="display: none;">
        @include('layouts.partials.sidebar-nav', ['companyName' => $companyName, 'isMobile' => true])
    </aside>

    {{-- Desktop Fixed Sidebar --}}
    <aside class="hidden lg:fixed lg:inset-y-0 lg:z-40 lg:flex lg:w-64 lg:flex-col bg-slate-900 text-slate-100 border-r border-slate-800">
        @include('layouts.partials.sidebar-nav', ['companyName' => $companyName, 'isMobile' => false])
    </aside>

    <script>
        (function () {
            const KEY = 'billing_sidebar_scroll_top';

            const isNavVisible = function (nav) {
                if (!nav) return false;
                const aside = nav.closest('aside');
                if (aside && window.getComputedStyle(aside).display === 'none') return false;
                return window.getComputedStyle(nav).display !== 'none' && nav.clientHeight > 0;
            };

            window.saveSidebarScroll = function (nav) {
                if (!isNavVisible(nav)) return;
                try {
                    sessionStorage.setItem(KEY, String(nav.scrollTop));
                } catch (e) {}
            };

            window.lockSidebarActiveMenu = function (nav) {
                if (!isNavVisible(nav)) return;

                try {
                    const raw = sessionStorage.getItem(KEY);
                    if (raw !== null && raw !== '') {
                        const savedTop = parseInt(raw, 10);
                        if (Number.isFinite(savedTop) && savedTop >= 0) {
                            nav.scrollTop = savedTop;
                        }
                    }
                } catch (e) {}

                const activeEl = nav.querySelector('.is-sidebar-active');
                if (activeEl) {
                    const navRect = nav.getBoundingClientRect();
                    const activeRect = activeEl.getBoundingClientRect();
                    const pad = 12;
                    const isVisible = activeRect.top >= navRect.top + pad && activeRect.bottom <= navRect.bottom - pad;

                    if (!isVisible) {
                        const offsetInNav = activeRect.top - navRect.top + nav.scrollTop;
                        nav.scrollTop = Math.max(0, offsetInNav - (nav.clientHeight / 2) + (activeEl.offsetHeight / 2));
                    }
                }

                window.saveSidebarScroll(nav);
            };

            const applyDesktopLock = function () {
                const desktopNav = document.querySelector('[data-sidebar-nav="desktop"]');
                if (desktopNav) {
                    window.lockSidebarActiveMenu(desktopNav);
                }
            };

            applyDesktopLock();
            window.addEventListener('load', applyDesktopLock);
        })();
    </script>

    {{-- Main Content Shell --}}
    <div class="lg:pl-64 flex flex-col min-h-screen">
        <header class="sticky top-0 z-30 h-16 bg-white/90 backdrop-blur border-b border-slate-200 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button"
                        @click="sidebarOpen = true"
                        class="lg:hidden inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 transition"
                        aria-label="Open navigation">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <h1 class="truncate text-base sm:text-lg font-semibold text-slate-900">
                    {{ $title ?? 'Dashboard' }}
                </h1>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                @auth
                    @if(auth()->user()->role->isStaff())
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $sumopodEnv === 'LIVE' ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $sumopodEnv === 'LIVE' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            {{ $sumopodEnv }}
                        </span>
                    @endif
                @endauth
                <span class="hidden sm:inline-flex items-center text-xs font-medium text-slate-500">
                    {{ now('Asia/Jakarta')->translatedFormat('d M Y') }} WIB
                </span>
            </div>
        </header>

        <main class="flex-1 p-4 pb-24 sm:p-6 sm:pb-24 lg:p-8 lg:pb-24 max-w-7xl w-full mx-auto space-y-6">
            @if(session('success'))
                <div x-data="{ open: true }" x-show="open"
                     class="flex items-start justify-between gap-3 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
                    <div class="flex items-center gap-2.5">
                        <svg class="h-5 w-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ session('success') }}</span>
                    </div>
                    <button type="button" @click="open = false" class="text-emerald-600 hover:text-emerald-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif

            @if(session('error'))
                <div x-data="{ open: true }" x-show="open"
                     class="flex items-start justify-between gap-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                    <div class="flex items-center gap-2.5">
                        <svg class="h-5 w-5 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ session('error') }}</span>
                    </div>
                    <button type="button" @click="open = false" class="text-rose-600 hover:text-rose-800">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif

            @if($errors->any())
                <div x-data="{ open: true }" x-show="open"
                     class="flex items-start justify-between gap-3 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" @click="open = false" class="text-rose-600 hover:text-rose-800 shrink-0">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            @endif

            @isset($slot)
                {{ $slot }}
            @else
                @yield('content')
            @endisset
        </main>
    </div>

    {{-- Global Copy Toast --}}
    <div x-show="copiedToast"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-24 right-5 sm:bottom-24 sm:right-7 z-50 flex items-center gap-2.5 rounded-xl bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-xl border border-slate-700"
         style="display: none;">
        <svg class="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
        <span>Payment link copied to clipboard</span>
    </div>

    @auth
        @if(auth()->user()->role->isStaff())
            @include('layouts.partials.customer-quick-search-fab')
        @endif
    @endauth

    @livewireScripts
</body>
</html>
