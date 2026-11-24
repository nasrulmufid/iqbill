@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? (string) $status->value : strtolower((string) $status);
    $classes = match ($value) {
        'active', 'paid', 'resolved', 'synced', 'sent', 'connected', 'logged_in', 'ok', 'completed' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
        'unpaid', 'pending', 'in_progress', 'generated', 'queued', 'medium', 'completed_with_errors', 'disconnected', 'logged_out' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        'isolated', 'overdue', 'failed', 'urgent', 'high', 'suspended', 'terminated' => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        'open', 'pending_installation', 'processing' => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
        default => 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {$classes}"]) }}>
    {{ str_replace('_', ' ', $value) }}
</span>
