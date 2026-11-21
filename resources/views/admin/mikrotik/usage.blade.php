@extends('layouts.app', ['title' => 'PPPoE Usage Tracking'])

@section('content')
<div class="space-y-6">
    <x-page-header title="PPPoE Bandwidth Usage" subtitle="Periodic recording of active PPPoE session Rx/Tx byte counters every 5 minutes.">
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.mikrotik.usage.sync') }}">
                @csrf
                <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Sync Now
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if(!$usageTrackingEnabled)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Usage tracking (<code>usage_tracking_enabled</code>) is currently <strong>disabled</strong>. Enable it in
            <a href="{{ route('admin.settings.payment.edit') }}" class="underline font-semibold">System Settings</a>.
        </div>
    @endif

    <form method="GET" class="bg-white rounded-2xl border border-slate-200 p-4 flex items-center gap-3 shadow-sm">
        <input type="date" name="date" value="{{ $date }}" class="rounded-xl border-slate-300 text-sm">
        <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Show Date</button>
    </form>

    @if($dailySummary->isEmpty())
        <x-empty-state title="No usage logs on this date" description="Run manual sync or wait for the 5-minute scheduler when PPPoE sessions are active." />
    @else
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Customer &amp; PPPoE</th>
                            <th class="px-5 py-3">Router</th>
                            <th class="px-5 py-3">Download (Rx)</th>
                            <th class="px-5 py-3">Upload (Tx)</th>
                            <th class="px-5 py-3">Total Traffic</th>
                            <th class="px-5 py-3">Last Recorded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($dailySummary as $row)
                            @php
                                $rxMb = $row->total_rx / 1048576;
                                $txMb = $row->total_tx / 1048576;
                            @endphp
                            <tr>
                                <td class="px-5 py-3.5">
                                    <div class="font-semibold text-slate-900">{{ $row->subscription->customer->full_name ?? '-' }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ $row->subscription->service_username ?? '-' }}</div>
                                </td>
                                <td class="px-5 py-3.5 text-xs">{{ $row->mikrotikRouter->name ?? '-' }}</td>
                                <td class="px-5 py-3.5 font-mono text-emerald-700">{{ number_format($rxMb, 2) }} MB</td>
                                <td class="px-5 py-3.5 font-mono text-sky-700">{{ number_format($txMb, 2) }} MB</td>
                                <td class="px-5 py-3.5 font-mono font-bold">{{ number_format(($rxMb + $txMb) / 1024, 3) }} GB</td>
                                <td class="px-5 py-3.5 text-xs text-slate-500">{{ \Carbon\Carbon::parse($row->last_recorded_at)->timezone('Asia/Jakarta')->format('H:i:s') }} WIB</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $dailySummary->links() }}
    @endif
</div>
@endsection
