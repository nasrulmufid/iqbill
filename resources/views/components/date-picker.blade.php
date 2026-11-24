@props([
    'name' => 'date',
    'id' => 'date-picker-input',
    'model' => 'value',
    'today' => null,
    'originalModel' => null,
    'label' => 'Select Date',
    'required' => true,
])

@php
    $todayDate = $today ?: now('Asia/Jakarta')->toDateString();
@endphp

<div x-data="{
        viewYear: {{ (int) substr($todayDate, 0, 4) }},
        viewMonth: {{ (int) substr($todayDate, 5, 2) - 1 }},
        todayYmd: @js($todayDate),
        monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        dayNames: ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'],
        parseYmd(ymd) {
            if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
            const [y, m, d] = ymd.split('-').map(Number);
            const dt = new Date(Date.UTC(y, m - 1, d));
            if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
            return { y, m, d, ms: dt.getTime() };
        },
        formatYmd(y, m, d) {
            const dt = new Date(Date.UTC(y, m - 1, d));
            return dt.getUTCFullYear() + '-' + String(dt.getUTCMonth() + 1).padStart(2, '0') + '-' + String(dt.getUTCDate()).padStart(2, '0');
        },
        syncCalendarTo(ymd) {
            const parsed = this.parseYmd(ymd);
            if (parsed) {
                this.viewYear = parsed.y;
                this.viewMonth = parsed.m - 1;
            }
        },
        prevMonth() {
            if (this.viewMonth === 0) {
                this.viewMonth = 11;
                this.viewYear -= 1;
            } else {
                this.viewMonth -= 1;
            }
        },
        nextMonth() {
            if (this.viewMonth === 11) {
                this.viewMonth = 0;
                this.viewYear += 1;
            } else {
                this.viewMonth += 1;
            }
        },
        shiftDate(currentYmd, days) {
            const base = this.parseYmd(currentYmd) || this.parseYmd(this.todayYmd);
            const dt = new Date(base.ms + days * 86400000);
            const next = this.formatYmd(dt.getUTCFullYear(), dt.getUTCMonth() + 1, dt.getUTCDate());
            this.syncCalendarTo(next);
            return next;
        },
        calendarCells() {
            const firstOfMonth = new Date(Date.UTC(this.viewYear, this.viewMonth, 1));
            const startOffset = (firstOfMonth.getUTCDay() + 6) % 7;
            const daysInMonth = new Date(Date.UTC(this.viewYear, this.viewMonth + 1, 0)).getUTCDate();
            const cells = [];

            for (let i = 0; i < startOffset; i++) {
                cells.push({ key: 'empty-' + i, ymd: null, day: null });
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const ymd = this.formatYmd(this.viewYear, this.viewMonth + 1, d);
                cells.push({ key: ymd, ymd, day: d });
            }
            return cells;
        }
     }"
     x-init="syncCalendarTo({{ $model }}); $watch('{{ $model }}', value => syncCalendarTo(value))"
     class="space-y-3">

    <div>
        <label for="{{ $id }}" class="block text-xs font-semibold text-slate-700 mb-1">
            {{ $label }}
        </label>
        <div class="relative flex items-center">
            <input type="date"
                   id="{{ $id }}"
                   name="{{ $name }}"
                   x-model="{{ $model }}"
                   @if($required) required @endif
                   class="w-full rounded-xl border-slate-300 text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500">
        </div>
    </div>

    {{-- Quick Add / Reduce Shortcuts --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] font-medium text-slate-500 mr-1">Quick adjust:</span>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, -30)"
                class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-100 transition">-30d</button>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, -7)"
                class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-100 transition">-7d</button>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, -1)"
                class="rounded-lg border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-100 transition">-1d</button>
        <button type="button" @click="{{ $model }} = todayYmd; syncCalendarTo(todayYmd)"
                class="rounded-lg border border-slate-200 bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-200 transition">Today</button>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, 1)"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 transition">+1d</button>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, 7)"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 transition">+7d</button>
        <button type="button" @click="{{ $model }} = shiftDate({{ $model }}, 30)"
                class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 transition">+30d</button>
    </div>

    {{-- Interactive Calendar Picker --}}
    <div class="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
        <div class="flex items-center justify-between mb-2.5">
            <button type="button" @click="prevMonth()"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 transition"
                    aria-label="Previous month">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>
            <div class="text-xs font-bold text-slate-800" x-text="monthNames[viewMonth] + ' ' + viewYear"></div>
            <button type="button" @click="nextMonth()"
                    class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 transition"
                    aria-label="Next month">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-semibold uppercase text-slate-400 mb-1">
            <template x-for="dayName in dayNames" :key="dayName">
                <div x-text="dayName"></div>
            </template>
        </div>

        <div class="grid grid-cols-7 gap-1">
            <template x-for="cell in calendarCells()" :key="cell.key">
                <div>
                    <template x-if="cell.ymd">
                        <button type="button"
                                @click="{{ $model }} = cell.ymd"
                                :class="{
                                    'bg-indigo-600 text-white font-bold shadow-sm hover:bg-indigo-700': {{ $model }} === cell.ymd,
                                    'bg-white text-indigo-700 font-semibold ring-1 ring-indigo-300 hover:bg-indigo-50': {{ $model }} !== cell.ymd && cell.ymd === todayYmd,
                                    @if($originalModel)
                                    'bg-amber-50 text-amber-800 font-semibold ring-1 ring-amber-300 hover:bg-amber-100': {{ $model }} !== cell.ymd && cell.ymd !== todayYmd && cell.ymd === {{ $originalModel }},
                                    'bg-white text-slate-700 hover:bg-slate-100': {{ $model }} !== cell.ymd && cell.ymd !== todayYmd && cell.ymd !== {{ $originalModel }}
                                    @else
                                    'bg-white text-slate-700 hover:bg-slate-100': {{ $model }} !== cell.ymd && cell.ymd !== todayYmd
                                    @endif
                                }"
                                class="w-full h-8 rounded-lg text-xs transition flex items-center justify-center"
                                x-text="cell.day">
                        </button>
                    </template>
                </div>
            </template>
        </div>

        <div class="mt-2.5 flex flex-wrap items-center gap-3 text-[10px] text-slate-500">
            <span class="inline-flex items-center gap-1">
                <span class="h-2.5 w-2.5 rounded-sm bg-indigo-600"></span> Selected
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="h-2.5 w-2.5 rounded-sm bg-white ring-1 ring-indigo-400"></span> Today (WIB)
            </span>
            @if($originalModel)
                <span class="inline-flex items-center gap-1">
                    <span class="h-2.5 w-2.5 rounded-sm bg-amber-100 ring-1 ring-amber-400"></span> Current Active Date
                </span>
            @endif
        </div>
    </div>
</div>
