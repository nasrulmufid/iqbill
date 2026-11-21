@extends('layouts.app', ['title' => 'Customer Support Tickets'])

@section('content')
<div class="space-y-6">
    <x-page-header title="Support & Incident Tickets" subtitle="Technicians/NOC can claim tickets, log troubleshooting notes, and update resolution status.">
        <x-slot:actions>
            <form method="GET" class="flex flex-wrap gap-2">
                <select name="status" class="rounded-xl border-slate-300 text-sm">
                    <option value="">All Statuses</option>
                    @foreach(['open', 'in_progress', 'resolved', 'closed'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ strtoupper(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
                <select name="priority" class="rounded-xl border-slate-300 text-sm">
                    <option value="">All Priorities</option>
                    @foreach(['low', 'medium', 'high', 'urgent'] as $p)
                        <option value="{{ $p }}" @selected($priority === $p)>{{ strtoupper($p) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if($tickets->isEmpty())
        <x-empty-state title="No support tickets yet" description="Incident reports or customer inquiries submitted through the Customer Portal will appear here." />
    @else
        <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Ticket</th>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Priority</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Technician</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($tickets as $t)
                            <tr>
                                <td class="px-5 py-3.5">
                                    <div class="font-mono text-xs font-bold text-indigo-600">{{ $t->ticket_number }}</div>
                                    <div class="font-semibold text-slate-900">{{ $t->subject }}</div>
                                    <div class="text-xs text-slate-400 uppercase">{{ $t->category }}</div>
                                </td>
                                <td class="px-5 py-3.5 text-xs">
                                    <div class="font-semibold text-slate-800">{{ $t->customer->full_name }}</div>
                                    <div class="text-slate-500">{{ $t->subscription?->service_username ?? '-' }}</div>
                                </td>
                                <td class="px-5 py-3.5"><x-status-badge :status="$t->priority" /></td>
                                <td class="px-5 py-3.5"><x-status-badge :status="$t->status" /></td>
                                <td class="px-5 py-3.5 text-xs">{{ $t->assignedTo->name ?? 'Unassigned' }}</td>
                                <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-1.5">
                                    @if(!$t->assigned_to_user_id)
                                        <form method="POST" action="{{ route('admin.tickets.claim', $t) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white">Claim Ticket</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('admin.tickets.show', $t) }}" class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">Manage</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $tickets->links() }}
    @endif
</div>
@endsection
