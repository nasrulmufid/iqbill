<div @if($hasActiveBroadcast) wire:poll.5s @endif class="space-y-6" x-data>
    <x-page-header title="Bulk WhatsApp Broadcast"
                   subtitle="Send announcements, maintenance alerts, promotions, or billing reminders to multiple customers at once via Go-WhatsApp.">
        <x-slot:actions>
            <a href="{{ route('admin.customers.index') }}"
               class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                Customers List
            </a>
            @if($isSuperAdmin)
                <a href="{{ route('admin.whatsapp.templates') }}"
                   class="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                    Message Templates
                </a>
                <a href="{{ route('admin.whatsapp.device') }}"
                   class="rounded-xl bg-slate-800 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-900 transition">
                    Go-WhatsApp Device
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if(!$gatewayConfigured)
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
                <div>
                    <div class="font-semibold">Go-WhatsApp Base URL is not configured</div>
                    <div class="text-xs text-amber-800 mt-0.5">WhatsApp delivery will fail until the Go-WhatsApp URL, credentials, and a linked device are configured.</div>
                </div>
            </div>
            @if($isSuperAdmin)
                <a href="{{ route('admin.whatsapp.device') }}"
                   class="shrink-0 rounded-xl bg-amber-600 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-700 transition">
                    Configure Go-WhatsApp
                </a>
            @endif
        </div>
    @endif

    @if($errorMessage)
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errorMessage }}</div>
    @endif

    @if($statusMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ $statusMessage }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {{-- Left Column: Segment Filter & Message Composer --}}
        <form wire:submit="sendBroadcast" class="lg:col-span-7 bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-5">
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-4">
                <div>
                    <h3 class="text-base font-bold text-slate-900">1. Target Audience &amp; Compose Message</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Use an automated segment or select specific customers from the list on the right.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 ring-1 ring-indigo-200">
                    {{ $recipientCount }} Selected Recipients
                </span>
            </div>

            {{-- Target Segment --}}
            <div class="space-y-3">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Target Customer Segment</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Target Category</label>
                        <select wire:model.live="segment" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            @foreach($segments as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Campaign Title (Optional)</label>
                        <input type="text" wire:model="title" placeholder="e.g. North POP Maintenance Notice"
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Filter MikroTik Router</label>
                        <select wire:model.live="routerId" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            <option value="">All Routers</option>
                            @foreach($routers as $r)
                                <option value="{{ $r->id }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600">Filter Service Package</label>
                        <select wire:model.live="packageId" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            <option value="">All Packages</option>
                            @foreach($packages as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} ({{ strtoupper($p->service_type) }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Load Template --}}
            <div class="border-t border-slate-100 pt-4 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-slate-600">Load from Template (Optional)</label>
                        <select wire:model.live="templateKey" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                            <option value="">-- Write Custom Message --</option>
                            @foreach($templates as $tpl)
                                <option value="{{ $tpl->key }}">{{ $tpl->label }} ({{ $tpl->key }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                        <label class="block text-xs font-medium text-slate-600">WhatsApp Message Body *</label>
                        <div class="flex items-center gap-1.5">
                            <button type="button"
                                    title="Bold (*text*)"
                                    @click="
                                        const el = $refs.messageInput;
                                        const s = el.selectionStart ?? el.value.length;
                                        const e = el.selectionEnd ?? el.value.length;
                                        const sel = el.value.slice(s, e) || 'BOLD';
                                        el.value = el.value.slice(0, s) + '*' + sel + '*' + el.value.slice(e);
                                        el.selectionStart = s + 1;
                                        el.selectionEnd = s + 1 + sel.length;
                                        el.focus();
                                        $wire.set('messageBody', el.value);
                                    "
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs font-bold text-slate-700 hover:bg-slate-100">B</button>
                            <button type="button"
                                    title="Italic (_text_)"
                                    @click="
                                        const el = $refs.messageInput;
                                        const s = el.selectionStart ?? el.value.length;
                                        const e = el.selectionEnd ?? el.value.length;
                                        const sel = el.value.slice(s, e) || 'Italic';
                                        el.value = el.value.slice(0, s) + '_' + sel + '_' + el.value.slice(e);
                                        el.selectionStart = s + 1;
                                        el.selectionEnd = s + 1 + sel.length;
                                        el.focus();
                                        $wire.set('messageBody', el.value);
                                    "
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs italic font-semibold text-slate-700 hover:bg-slate-100">I</button>
                            <button type="button"
                                    title="Strikethrough (~text~)"
                                    @click="
                                        const el = $refs.messageInput;
                                        const s = el.selectionStart ?? el.value.length;
                                        const e = el.selectionEnd ?? el.value.length;
                                        const sel = el.value.slice(s, e) || 'Strike';
                                        el.value = el.value.slice(0, s) + '~' + sel + '~' + el.value.slice(e);
                                        el.selectionStart = s + 1;
                                        el.selectionEnd = s + 1 + sel.length;
                                        el.focus();
                                        $wire.set('messageBody', el.value);
                                    "
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs line-through font-semibold text-slate-700 hover:bg-slate-100">S</button>
                            <button type="button"
                                    title="Monospace (`code`)"
                                    @click="
                                        const el = $refs.messageInput;
                                        const s = el.selectionStart ?? el.value.length;
                                        const e = el.selectionEnd ?? el.value.length;
                                        const sel = el.value.slice(s, e) || 'code';
                                        el.value = el.value.slice(0, s) + '`' + sel + '`' + el.value.slice(e);
                                        el.selectionStart = s + 1;
                                        el.selectionEnd = s + 1 + sel.length;
                                        el.focus();
                                        $wire.set('messageBody', el.value);
                                    "
                                    class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-slate-700 hover:bg-slate-100">&lt;/&gt;</button>
                            <span class="text-[11px] text-slate-400 ml-1">{{ mb_strlen($messageBody) }} chars</span>
                        </div>
                    </div>
                    <textarea x-ref="messageInput"
                              wire:model.live.debounce.300ms="messageBody"
                              rows="6"
                              required
                              placeholder="Halo *{customer_name}*, kami informasikan _maintenance jaringan_ pada pukul *23:00 WIB*..."
                              class="mt-1 w-full rounded-xl border-slate-300 text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    @error('messageBody') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>

                {{-- Variable Chips --}}
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 space-y-2">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Click to insert personalization variables:</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($placeholders as $var => $desc)
                            <button type="button"
                                    title="{{ $desc }}"
                                    @click="
                                        const el = $refs.messageInput;
                                        const start = el.selectionStart ?? el.value.length;
                                        const end = el.selectionEnd ?? el.value.length;
                                        const token = @js($var);
                                        el.value = el.value.slice(0, start) + token + el.value.slice(end);
                                        el.selectionStart = el.selectionEnd = start + token.length;
                                        el.focus();
                                        $wire.set('messageBody', el.value);
                                    "
                                    class="rounded-lg bg-white border border-indigo-200 px-2 py-1 text-[11px] font-mono font-semibold text-indigo-700 hover:bg-indigo-50 transition">
                                {{ $var }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Dispatch Mode --}}
            <div class="border-t border-slate-100 pt-4 space-y-3">
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">2. Dispatch Method</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-2.5 rounded-xl border p-3.5 cursor-pointer transition {{ $dispatchMode === 'sync' ? 'border-indigo-500 bg-indigo-50/40' : 'border-slate-200 hover:bg-slate-50' }}">
                        <input type="radio" wire:model.live="dispatchMode" value="sync" class="mt-0.5 text-indigo-600">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Send Immediately</div>
                            <div class="text-xs text-slate-500">Dispatch synchronously &amp; show sent/failed summary immediately (ideal for &le; 25 recipients).</div>
                        </div>
                    </label>
                    <label class="flex items-start gap-2.5 rounded-xl border p-3.5 cursor-pointer transition {{ $dispatchMode === 'queue' ? 'border-indigo-500 bg-indigo-50/40' : 'border-slate-200 hover:bg-slate-50' }}">
                        <input type="radio" wire:model.live="dispatchMode" value="queue" class="mt-0.5 text-indigo-600">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Background Queue</div>
                            <div class="text-xs text-slate-500">Paced sequentially in the queue worker to stay within WhatsApp rate limits.</div>
                        </div>
                    </label>
                </div>

                @if($dispatchMode === 'queue')
                    <div class="flex items-center gap-3 rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
                        <label class="text-xs font-medium text-slate-700">Delay between messages (seconds):</label>
                        <input type="number" wire:model="delaySeconds" min="0" max="30" class="w-24 rounded-xl border-slate-300 text-sm">
                        <span class="text-xs text-slate-500">Recommended 2–5s per message. Ensure <code>queue:work</code> is running.</span>
                    </div>
                @elseif($recipientCount > 25)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs text-amber-800">
                        Recipient count is currently <strong>{{ $recipientCount }} customers</strong>. Using <strong>Background Queue</strong> mode with a per-message delay is recommended to prevent request timeouts and WhatsApp spam throttling.
                    </div>
                @endif
            </div>

            <div class="pt-2 flex flex-wrap items-center justify-between gap-3">
                <div class="text-xs text-slate-500">
                    Active target: <strong class="text-slate-900">{{ $recipientCount }}</strong> customer(s)
                </div>
                <button type="submit"
                        wire:loading.attr="disabled"
                        @disabled($recipientCount === 0)
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed transition">
                    <svg wire:loading.remove wire:target="sendBroadcast" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                    <span wire:loading.remove wire:target="sendBroadcast">Send Broadcast to {{ $recipientCount }} Recipients</span>
                    <span wire:loading wire:target="sendBroadcast">Dispatching Broadcast...</span>
                </button>
            </div>
        </form>

        {{-- Right Column: Live WhatsApp Preview + Recipient Checklist --}}
        <div class="lg:col-span-5 space-y-6">
            {{-- WhatsApp Bubble Preview --}}
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-slate-900">WhatsApp Message Preview</h3>
                    <span class="text-[11px] font-medium text-slate-500">
                        {{ $previewCustomer ? 'Sample: ' . $previewCustomer->full_name : 'Sample Data' }}
                    </span>
                </div>
                <div class="rounded-2xl bg-[#e5ddd5] p-4 border border-slate-200">
                    <div class="ml-auto max-w-[95%] rounded-2xl rounded-tr-sm bg-[#dcf8c6] px-4 py-3 shadow-sm text-xs sm:text-sm text-slate-800 break-words">
                        @if($previewHtml !== '')
                            {!! $previewHtml !!}
                        @else
                            <span class="text-slate-500">Type your message on the left to preview resolved variables and WhatsApp formatting (*bold*, _italic_) here...</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Recipient Checklist & Filter --}}
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Recipients List ({{ $recipientCount }})</h3>
                        <p class="text-[11px] text-slate-500">
                            @if($segment === 'manual')
                                Check the customers you want to include.
                            @else
                                Uncheck any customer to exclude them from this broadcast.
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" wire:click="selectAllVisible" class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-200">
                            Select All
                        </button>
                        <button type="button" wire:click="deselectAllVisible" class="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-200">
                            Clear All
                        </button>
                    </div>
                </div>

                <input type="text"
                       wire:model.live.debounce.300ms="search"
                       placeholder="Search name, IQ- code, WA No., or PPPoE..."
                       class="w-full rounded-xl border-slate-300 text-xs">

                @if($segment !== 'manual' && count($excludedCustomerIds) > 0)
                    <div class="flex items-center justify-between rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-800">
                        <span>{{ count($excludedCustomerIds) }} customer(s) excluded from list.</span>
                        <button type="button" wire:click="resetExclusions" class="font-semibold underline">Reset</button>
                    </div>
                @elseif($segment === 'manual' && count($selectedCustomerIds) > 0)
                    <div class="flex items-center justify-between rounded-xl bg-indigo-50 border border-indigo-200 px-3 py-2 text-xs text-indigo-800">
                        <span>{{ count($selectedCustomerIds) }} customer(s) manually selected.</span>
                        <button type="button" wire:click="clearManualSelection" class="font-semibold underline">Clear</button>
                    </div>
                @endif

                @if($candidates->isEmpty())
                    <x-empty-state title="No matching customers"
                                   description="Adjust your segment, router, package filter, or search query." />
                @else
                    <div class="max-h-80 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200">
                        @foreach($candidates as $cand)
                            @php
                                $sub = $cand->subscriptions->first();
                                $isChecked = $segment === 'manual'
                                    ? in_array($cand->id, $selectedCustomerIds, true)
                                    : !in_array($cand->id, $excludedCustomerIds, true);
                            @endphp
                            <label wire:key="wa-cand-{{ $cand->id }}"
                                   class="flex items-center justify-between gap-3 px-3.5 py-2.5 cursor-pointer hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3 min-w-0">
                                    <input type="checkbox"
                                           @checked($isChecked)
                                           wire:click="toggleCustomer({{ $cand->id }})"
                                           class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    <div class="min-w-0">
                                        <div class="truncate text-xs font-semibold text-slate-900">{{ $cand->full_name }}</div>
                                        <div class="truncate font-mono text-[11px] text-slate-500">
                                            {{ $cand->customer_code }} &middot; {{ $cand->phone_whatsapp }}
                                        </div>
                                    </div>
                                </div>
                                @if($sub)
                                    <x-status-badge :status="$sub->status" class="shrink-0" />
                                @endif
                            </label>
                        @endforeach
                    </div>

                    @if($candidateTotalCount > 100)
                        <p class="text-[11px] text-slate-500">
                            Showing 100 of <strong>{{ $candidateTotalCount }}</strong> matching customers (all selected customers are still included in delivery).
                        </p>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Broadcast History --}}
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between gap-3">
            <div>
                <h3 class="font-bold text-slate-900">WhatsApp Broadcast History</h3>
                <p class="text-xs text-slate-500">Track sent/failed status per campaign and retry temporary failures.</p>
            </div>
            <button type="button" wire:click="$refresh" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                Refresh
            </button>
        </div>

        @if($broadcasts->isEmpty())
            <div class="p-6">
                <x-empty-state title="No broadcast history yet"
                               description="Bulk WhatsApp broadcasts sent from this page will be logged here." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Time &amp; Sender</th>
                            <th class="px-5 py-3">Campaign &amp; Message</th>
                            <th class="px-5 py-3">Progress</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($broadcasts as $bc)
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="px-5 py-3.5 text-xs whitespace-nowrap">
                                    <div class="font-semibold text-slate-900">{{ $bc->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</div>
                                    <div class="text-slate-500">By: {{ $bc->creator?->name ?? 'System' }} &middot; {{ strtoupper($bc->dispatch_mode) }}</div>
                                </td>
                                <td class="px-5 py-3.5 max-w-md">
                                    <div class="font-semibold text-slate-900">{{ $bc->title ?: 'Broadcast ' . ($segments[$bc->target_segment] ?? $bc->target_segment) }}</div>
                                    <div class="text-xs text-slate-500 truncate">{{ Str::limit($bc->message_body, 90) }}</div>
                                </td>
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <div class="text-xs font-semibold text-slate-800">
                                        <span class="text-emerald-600">{{ $bc->sent_count }}</span> / {{ $bc->total_recipients }} sent
                                        @if($bc->failed_count > 0)
                                            <span class="text-rose-600">({{ $bc->failed_count }} failed)</span>
                                        @endif
                                    </div>
                                    <div class="mt-1.5 h-1.5 w-36 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $bc->failed_count > 0 ? 'bg-amber-500' : 'bg-emerald-500' }}"
                                             style="width: {{ $bc->progressPercent() }}%"></div>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <x-status-badge :status="$bc->status" />
                                </td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                    <button type="button" wire:click="openLogs({{ $bc->id }})"
                                            class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                        View Logs
                                    </button>
                                    @if($bc->failed_count > 0)
                                        <button type="button" wire:click="retryFailedBroadcast({{ $bc->id }})"
                                                wire:loading.attr="disabled"
                                                class="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100">
                                            Retry Failed
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Broadcast Delivery Log Modal --}}
    @if($inspectedBroadcast)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="w-full max-w-4xl max-h-[88vh] flex flex-col rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">
                            Broadcast Log Details #{{ $inspectedBroadcast->id }} — {{ $inspectedBroadcast->title ?: ($segments[$inspectedBroadcast->target_segment] ?? $inspectedBroadcast->target_segment) }}
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Total {{ $inspectedBroadcast->total_recipients }} recipient(s) &middot; {{ $inspectedBroadcast->sent_count }} sent &middot; {{ $inspectedBroadcast->failed_count }} failed
                        </p>
                    </div>
                    <button type="button" wire:click="closeLogs" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="overflow-y-auto flex-1">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-50 text-[11px] uppercase text-slate-500 sticky top-0">
                            <tr>
                                <th class="px-5 py-3">Customer</th>
                                <th class="px-5 py-3">WhatsApp No.</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3">Gateway Response / Message Snippet</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($inspectedBroadcast->logs as $log)
                                <tr>
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <div class="font-semibold text-slate-900">{{ $log->customer?->full_name ?? '-' }}</div>
                                        <div class="font-mono text-slate-400">{{ $log->customer?->customer_code ?? '-' }}</div>
                                    </td>
                                    <td class="px-5 py-3 font-mono whitespace-nowrap">{{ $log->recipient_phone }}</td>
                                    <td class="px-5 py-3 whitespace-nowrap"><x-status-badge :status="$log->delivery_status" /></td>
                                    <td class="px-5 py-3 text-slate-600">
                                        <div class="line-clamp-2 text-slate-700">{{ $log->message_body }}</div>
                                        @if($log->gateway_response)
                                            <div class="mt-1 font-mono text-[11px] {{ $log->delivery_status === 'failed' ? 'text-rose-600' : 'text-slate-400' }}">
                                                {{ Str::limit($log->gateway_response, 120) }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-slate-500">No recipient logs recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3.5 border-t border-slate-200 bg-slate-50 flex items-center justify-end gap-2">
                    @if($inspectedBroadcast->failed_count > 0)
                        <button type="button" wire:click="retryFailedBroadcast({{ $inspectedBroadcast->id }})"
                                class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-700">
                            Retry {{ $inspectedBroadcast->failed_count }} Failed Messages
                        </button>
                    @endif
                    <button type="button" wire:click="closeLogs" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
