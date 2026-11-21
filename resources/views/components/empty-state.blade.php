@props([
    'title' => 'No data yet',
    'description' => 'Records will appear here once added or synchronized.',
    'actionLabel' => null,
    'actionHref' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white/80 px-6 py-12 text-center']) }}>
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
        @if(isset($icon))
            {{ $icon }}
        @else
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
            </svg>
        @endif
    </div>
    <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $title }}</h3>
    <p class="mt-1 max-w-sm text-xs text-slate-500">{{ $description }}</p>

    @if($actionLabel && $actionHref)
        <a href="{{ $actionHref }}"
           class="mt-4 inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
            {{ $actionLabel }}
        </a>
    @elseif(isset($action))
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
