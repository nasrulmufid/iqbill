@php
    $user = auth()->user();
    $companyName = $companyName ?? config('app.name', 'IQ Bill');
    $initials = collect(explode(' ', trim($user?->name ?? 'User')))
        ->filter()
        ->take(2)
        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    $navLink = fn (bool $active): string => $active
        ? 'is-sidebar-active bg-indigo-600 text-white shadow-sm'
        : 'text-slate-300 hover:bg-slate-800 hover:text-white';
@endphp

<div class="h-16 px-6 flex items-center justify-between gap-3 border-b border-slate-800/80 shrink-0">
    <a href="{{ $user?->role->isStaff() ? route('admin.dashboard') : route('portal.dashboard') }}" class="flex items-center gap-3 min-w-0">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-md shadow-indigo-950/50">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
        </span>
        <div class="min-w-0">
            <div class="truncate text-sm font-bold text-white tracking-tight">{{ $companyName }}</div>
            <div class="text-[11px] font-medium text-slate-400">{{ $user?->role->isStaff() ? 'Billing & MikroTik API' : 'Customer Portal' }}</div>
        </div>
    </a>

    @if(!empty($isMobile))
        <button type="button" @click="sidebarOpen = false"
                class="lg:hidden rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white transition"
                aria-label="Close navigation">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    @endif
</div>

<nav data-sidebar-nav="{{ !empty($isMobile) ? 'mobile' : 'desktop' }}"
     @if(!empty($isMobile))
         x-effect="if (sidebarOpen) { $nextTick(() => window.lockSidebarActiveMenu && window.lockSidebarActiveMenu($el)) }"
     @endif
     @scroll.passive="window.saveSidebarScroll && window.saveSidebarScroll($el)"
     @click="if ($event.target.closest('a')) { window.saveSidebarScroll && window.saveSidebarScroll($el); }"
     style="scroll-behavior: auto;"
     class="flex-1 overflow-y-auto px-3 py-4 space-y-5">
    @if($user && $user->role->isStaff())
        <div>
            <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Main</div>
            <div class="space-y-1">
                <a href="{{ route('admin.dashboard') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.dashboard')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('admin.monitoring.index') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.monitoring.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    <span>System Health</span>
                </a>
            </div>
        </div>

        <div>
            <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Billing</div>
            <div class="space-y-1">
                <a href="{{ route('admin.invoices.index') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.invoices.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span>Invoices</span>
                </a>
                @if($user->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Finance))
                    <a href="{{ route('admin.finance.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.finance.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Finance</span>
                    </a>
                @endif
            </div>
        </div>

        @if($user->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc))
            <div>
                <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Operations</div>
                <div class="space-y-1">
                    <a href="{{ route('admin.customers.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.customers.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>Customers</span>
                    </a>
                    <a href="{{ route('admin.subscriptions.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.subscriptions.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.14 0" />
                        </svg>
                        <span>Subscriptions</span>
                    </a>
                    <a href="{{ route('admin.packages.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.packages.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                        </svg>
                        <span>Packages</span>
                    </a>
                    <a href="{{ route('admin.tickets.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.tickets.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                        </svg>
                        <span>Tickets</span>
                    </a>
                </div>
            </div>

            <div>
                <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Network</div>
                <div class="space-y-1">
                    <a href="{{ route('admin.routers.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.routers.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2" />
                        </svg>
                        <span>Routers</span>
                    </a>
                    <a href="{{ route('admin.mikrotik.pppoe') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.mikrotik.pppoe')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>PPPoE</span>
                    </a>
                    <a href="{{ route('admin.mikrotik.usage') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.mikrotik.usage')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                        </svg>
                        <span>Data Usage</span>
                    </a>
                    <a href="{{ route('admin.mikrotik.backups') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.mikrotik.backups')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        <span>Backups</span>
                    </a>
                </div>
            </div>
        @else
            <div>
                <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Support</div>
                <div class="space-y-1">
                    <a href="{{ route('admin.tickets.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.tickets.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                        </svg>
                        <span>Tickets</span>
                    </a>
                </div>
            </div>
        @endif

        <div>
            <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">System</div>
            <div class="space-y-1">
                <a href="{{ route('admin.webhooks.index') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.webhooks.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span>Webhooks</span>
                </a>
                <a href="{{ route('admin.whatsapp.broadcast') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.whatsapp.broadcast*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <span>WA Broadcast</span>
                </a>
                @if($user->hasRole(\App\Enums\UserRole::SuperAdmin))
                    <a href="{{ route('admin.whatsapp.device') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.whatsapp.device')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                        <span>Go-WhatsApp</span>
                    </a>
                    <a href="{{ route('admin.whatsapp.templates') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.whatsapp.templates*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                        <span>WA Templates</span>
                    </a>
                    <a href="{{ route('admin.settings.payment.edit') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.settings.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>Settings</span>
                    </a>
                    <a href="{{ route('admin.users.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.users.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <span>Users</span>
                    </a>
                    <a href="{{ route('admin.system-backups.index') }}" @click="sidebarOpen = false"
                       class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('admin.system-backups.*')) }}">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span>Backup &amp; Restore</span>
                    </a>
                @endif
            </div>
        </div>
    @elseif($user)
        <div>
            <div class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Client Portal</div>
            <div class="space-y-1">
                <a href="{{ route('portal.dashboard') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('portal.dashboard')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('portal.invoices.index') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('portal.invoices.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <span>Invoices</span>
                </a>
                <a href="{{ route('portal.tickets.index') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('portal.tickets.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                    </svg>
                    <span>Support Tickets</span>
                </a>
                <a href="{{ route('portal.account.edit') }}" @click="sidebarOpen = false"
                   class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition {{ $navLink(request()->routeIs('portal.account.*')) }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>My Account</span>
                </a>
            </div>
        </div>
    @endif
</nav>

@if($user)
    <div class="p-4 border-t border-slate-800 shrink-0 space-y-3">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-300 text-xs font-bold border border-indigo-500/30">
                {{ $initials }}
            </div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold text-white">{{ $user->name }}</div>
                <div class="truncate text-xs text-slate-400">{{ $user->email }}</div>
            </div>
        </div>
        <div class="flex items-center justify-between gap-2">
            <span class="inline-flex items-center rounded-md bg-slate-800 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-300 border border-slate-700">
                {{ strtoupper($user->role->value) }}
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-rose-400 hover:bg-rose-500/10 hover:text-rose-300 transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Sign Out
                </button>
            </form>
        </div>
    </div>
@endif
