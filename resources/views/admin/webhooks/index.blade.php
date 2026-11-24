@extends('layouts.app', ['title' => 'Sumopod Webhook Logs'])

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold text-slate-900">Sumopod Webhook Logs</h1>

    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <th class="px-6 py-3">Time</th>
                        <th class="px-6 py-3">Svix ID</th>
                        <th class="px-6 py-3">Event</th>
                        <th class="px-6 py-3">Order ID</th>
                        <th class="px-6 py-3">Method</th>
                        <th class="px-6 py-3">HTTP</th>
                        <th class="px-6 py-3">Processing Status</th>
                        <th class="px-6 py-3">Detail / Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($logs as $log)
                        @php
                            $statusClass = match ($log->processing_status) {
                                'processed', 'late_settled' => 'text-emerald-700',
                                'failed', 'rejected_invalid_auth' => 'text-rose-600',
                                default => 'text-slate-600',
                            };
                        @endphp
                        <tr>
                            <td class="px-6 py-3">{{ $log->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }}</td>
                            <td class="px-6 py-3 font-mono text-xs">{{ $log->svix_id ?? '-' }}</td>
                            <td class="px-6 py-3 font-mono">{{ $log->event_type }}</td>
                            <td class="px-6 py-3 font-mono">{{ $log->payment_code ?? '-' }}</td>
                            <td class="px-6 py-3">{{ $log->verification_method }}</td>
                            <td class="px-6 py-3">{{ $log->http_status_returned }}</td>
                            <td class="px-6 py-3 font-semibold {{ $statusClass }}">{{ $log->processing_status }}</td>
                            <td class="px-6 py-3 text-xs {{ $log->error_message ? 'text-rose-600' : 'text-slate-400' }}">{{ $log->error_message ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-6 text-center text-slate-500">No webhook logs recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $logs->links() }}
</div>
@endsection
