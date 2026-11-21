@props(['title', 'subtitle' => null])

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">{{ $title }}</h2>
        @if($subtitle)
            <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2.5 shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
