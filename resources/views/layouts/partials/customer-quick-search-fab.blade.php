@php
    $canViewCustomerDetail = auth()->user()?->hasRole(\App\Enums\UserRole::SuperAdmin, \App\Enums\UserRole::Noc) ?? false;
    $allCustomersHref = $canViewCustomerDetail ? route('admin.customers.index') : route('admin.invoices.index');
    $allCustomersLabel = $canViewCustomerDetail ? 'View All Customers' : 'Open Invoices List';
@endphp

<div x-data="customerQuickSearch('{{ route('admin.customers.quick-search') }}')"
     @keydown.window="handleGlobalShortcut($event)"
     class="relative">

    {{-- Floating Action Button (FAB) --}}
    <button type="button"
            @click="openModal()"
            aria-label="Search Customer (ID or Name)"
            title="Search Customer (Ctrl+K)"
            style="bottom: max(1.25rem, env(safe-area-inset-bottom)); right: max(1.25rem, env(safe-area-inset-right));"
            class="group fixed z-30 inline-flex h-14 w-14 items-center justify-center rounded-full bg-indigo-600 text-white shadow-xl shadow-indigo-600/30 ring-4 ring-white/90 hover:bg-indigo-700 hover:scale-105 active:scale-95 transition focus:outline-none focus:ring-indigo-300">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <span class="hidden sm:block pointer-events-none absolute right-16 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg transition group-hover:opacity-100">
            Search Customer <span class="ml-1 rounded bg-slate-700 px-1.5 py-0.5 font-mono text-[10px] text-slate-200">Ctrl+K</span>
        </span>
    </button>

    {{-- Global Customer Search Modal (Mobile & Desktop Friendly) --}}
    <div x-show="open"
         x-transition.opacity
         @click.self="closeModal()"
         role="dialog"
         aria-modal="true"
         aria-label="Quick Customer Search"
         class="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/65 backdrop-blur-sm p-2.5 pt-3 sm:p-4 sm:pt-16"
         style="display: none;">

        <div class="w-full max-w-xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[85dvh] sm:max-h-[78vh]">
            {{-- Search Input Header --}}
            <form @submit.prevent="openHighlighted()" class="shrink-0 flex items-center gap-2 border-b border-slate-200 px-3 sm:px-4 py-2.5 bg-white">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center text-indigo-600">
                    <svg x-show="!loading" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <svg x-show="loading" class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24" style="display: none;">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </div>

                <input x-ref="searchInput"
                       type="search"
                       x-model="query"
                       @input.debounce.220ms="fetchResults()"
                       @keydown.arrow-down.prevent="moveSelection(1)"
                       @keydown.arrow-up.prevent="moveSelection(-1)"
                       @keydown.escape.prevent="closeModal()"
                       placeholder="Type Customer ID (IQ-...), name, WhatsApp, or PPPoE..."
                       autocomplete="off"
                       autocorrect="off"
                       spellcheck="false"
                       enterkeyhint="search"
                       class="min-w-0 flex-1 border-0 bg-transparent px-1 py-2 text-base sm:text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0">

                <button type="button"
                        x-show="query.length > 0"
                        @click="clearQuery()"
                        aria-label="Clear search query"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        style="display: none;">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <button type="button"
                        @click="closeModal()"
                        aria-label="Close search"
                        class="inline-flex h-10 px-3 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-xs font-semibold text-slate-600 hover:bg-slate-200 sm:bg-transparent sm:px-2 sm:hover:bg-slate-100">
                    <span class="sm:hidden">Close</span>
                    <span class="hidden sm:inline font-mono text-[11px] rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 text-slate-500">ESC</span>
                </button>
            </form>

            {{-- Status / Context Bar --}}
            <div class="shrink-0 flex items-center justify-between bg-slate-50 px-4 py-2 border-b border-slate-100 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <span x-text="query.trim() === '' ? 'Recent Customers' : 'Autocomplete Results (' + results.length + ')'"></span>
                <span class="hidden sm:inline normal-case font-medium text-slate-400">&uarr;&darr; navigate &middot; Enter open</span>
            </div>

            {{-- Autocomplete Results List --}}
            <div class="flex-1 overflow-y-auto overscroll-contain divide-y divide-slate-100">
                <template x-if="errorMsg">
                    <div class="p-6 text-center space-y-3">
                        <div class="text-sm font-medium text-rose-700" x-text="errorMsg"></div>
                        <button type="button" @click="fetchResults()"
                                class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                            Retry
                        </button>
                    </div>
                </template>

                <template x-if="!loading && !errorMsg && results.length === 0">
                    <div class="px-6 py-10 text-center space-y-2">
                        <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-800">No customers found</p>
                        <p class="text-xs text-slate-500">
                            No matching results for "<span class="font-semibold text-slate-700" x-text="query"></span>". Try another Customer ID, name, or WhatsApp number.
                        </p>
                    </div>
                </template>

                <template x-for="(item, idx) in results" :key="item.id">
                    <div @click="goTo(item.url)"
                         @mouseenter="activeIndex = idx"
                         :class="idx === activeIndex ? 'bg-indigo-50/80' : 'hover:bg-slate-50'"
                         class="cursor-pointer px-4 py-3.5 sm:py-3 transition flex items-start sm:items-center justify-between gap-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 font-mono text-[11px] font-bold text-indigo-700 ring-1 ring-indigo-200"
                                      x-text="item.customer_code"></span>
                                <span class="truncate text-sm font-semibold text-slate-900" x-text="item.full_name"></span>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                                      :class="statusBadgeClass(item.status)"
                                      x-text="statusLabel(item.status)"></span>
                            </div>

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500">
                                <span class="font-mono" x-text="item.phone_whatsapp"></span>
                                <template x-if="item.package_name">
                                    <span>&bull; <span class="font-medium text-slate-700" x-text="item.package_name"></span></span>
                                </template>
                                <template x-if="item.pppoe_username">
                                    <span class="font-mono text-[11px]">PPPoE: <span x-text="item.pppoe_username"></span></span>
                                </template>
                                <template x-if="item.static_ip">
                                    <span class="font-mono text-[11px] text-indigo-600" x-text="item.static_ip"></span>
                                </template>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0 pt-0.5 sm:pt-0">
                            <template x-if="item.invoice_url">
                                <a :href="item.invoice_url"
                                   @click.stop
                                   class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100">
                                    Invoice
                                </a>
                            </template>
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </span>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div class="shrink-0 bg-slate-50 px-4 py-2.5 border-t border-slate-100 flex items-center justify-between gap-3 text-xs">
                <a href="{{ $allCustomersHref }}" class="font-semibold text-indigo-600 hover:text-indigo-700 hover:underline">
                    {{ $allCustomersLabel }} &rarr;
                </a>
                <button type="button" @click="closeModal()" class="sm:hidden rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.customerQuickSearch = function (endpointUrl) {
        return {
            open: false,
            query: '',
            loading: false,
            errorMsg: '',
            results: [],
            activeIndex: 0,
            abortController: null,

            openModal() {
                this.open = true;
                this.activeIndex = 0;
                document.body.classList.add('overflow-hidden');
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                    this.$refs.searchInput?.select();
                });
                this.fetchResults();
            },

            closeModal() {
                this.open = false;
                document.body.classList.remove('overflow-hidden');
                if (this.abortController) {
                    this.abortController.abort();
                    this.abortController = null;
                }
            },

            clearQuery() {
                this.query = '';
                this.activeIndex = 0;
                this.$nextTick(() => this.$refs.searchInput?.focus());
                this.fetchResults();
            },

            handleGlobalShortcut(event) {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    this.open ? this.closeModal() : this.openModal();
                    return;
                }

                if (event.key === 'Escape' && this.open) {
                    this.closeModal();
                }
            },

            async fetchResults() {
                if (this.abortController) {
                    this.abortController.abort();
                }
                this.abortController = new AbortController();
                this.loading = true;
                this.errorMsg = '';

                try {
                    const url = endpointUrl + '?q=' + encodeURIComponent(this.query.trim());
                    const response = await fetch(url, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: this.abortController.signal,
                    });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const data = await response.json();
                    this.results = Array.isArray(data.results) ? data.results : [];
                    this.activeIndex = 0;
                } catch (err) {
                    if (err && err.name === 'AbortError') {
                        return;
                    }
                    this.errorMsg = 'Failed to load customer search results.';
                    this.results = [];
                } finally {
                    this.loading = false;
                }
            },

            moveSelection(delta) {
                if (this.results.length === 0) return;
                const len = this.results.length;
                this.activeIndex = (this.activeIndex + delta + len) % len;
            },

            openHighlighted() {
                if (this.results.length === 0) return;
                const item = this.results[this.activeIndex] || this.results[0];
                if (item && item.url) {
                    this.goTo(item.url);
                }
            },

            goTo(url) {
                if (!url) return;
                this.closeModal();
                window.location.href = url;
            },

            statusBadgeClass(status) {
                switch (status) {
                    case 'active':
                        return 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200';
                    case 'isolated':
                    case 'suspended':
                    case 'terminated':
                        return 'bg-rose-50 text-rose-700 ring-1 ring-rose-200';
                    case 'pending_installation':
                        return 'bg-sky-50 text-sky-700 ring-1 ring-sky-200';
                    default:
                        return 'bg-slate-100 text-slate-600 ring-1 ring-slate-200';
                }
            },

            statusLabel(status) {
                if (status === 'no_subscription') return 'NO SUBSCRIPTION';
                return String(status || '').replace(/_/g, ' ');
            },
        };
    };
</script>
