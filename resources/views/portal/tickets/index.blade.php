@extends('layouts.app', ['title' => 'Customer Support Tickets'])

@section('content')
<div class="space-y-6">
    <x-page-header title="Support & Service Tickets" subtitle="Report connection issues, package change requests, or billing inquiries." />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <form method="POST" action="{{ route('portal.tickets.store') }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3 h-fit shadow-sm">
            @csrf
            <div>
                <h3 class="font-bold text-slate-900">Create New Ticket</h3>
                <p class="text-xs text-slate-500 mt-0.5">Our technical/billing team will respond to your request.</p>
            </div>
            @if($subscriptions->isNotEmpty())
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Related Service (Optional)</label>
                    <select name="subscription_id" class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="">-- General / All Services --</option>
                        @foreach($subscriptions as $s)
                            <option value="{{ $s->id }}">{{ $s->package->name }} ({{ $s->service_username }})</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="grid grid-cols-2 gap-2.5">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Category</label>
                    <select name="category" required class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="gangguan">Service Outage</option>
                        <option value="tagihan">Billing</option>
                        <option value="pemasangan">Installation</option>
                        <option value="lainnya">Other</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Priority</label>
                    <select name="priority" required class="w-full rounded-xl border-slate-300 text-sm">
                        <option value="medium">Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                        <option value="low">Low</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Issue Subject</label>
                <input type="text" name="subject" placeholder="e.g. Blinking LOS light / Slow speed" required class="w-full rounded-xl border-slate-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Issue Details</label>
                <textarea name="description" rows="4" placeholder="Briefly describe your issue..." required class="w-full rounded-xl border-slate-300 text-sm"></textarea>
            </div>
            <button type="submit" class="w-full min-h-11 rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                Submit Ticket
            </button>
        </form>

        <div class="lg:col-span-2">
            @if($tickets->isEmpty())
                <x-empty-state title="No support tickets yet" description="If you have connection or billing issues, create a new ticket using the form on the left." />
            @else
                <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Ticket</th>
                                    <th class="px-5 py-3">Category</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($tickets as $t)
                                    <tr>
                                        <td class="px-5 py-3.5">
                                            <div class="font-mono text-xs font-bold text-indigo-600">{{ $t->ticket_number }}</div>
                                            <div class="font-semibold text-slate-900">{{ $t->subject }}</div>
                                        </td>
                                        <td class="px-5 py-3.5 text-xs uppercase">{{ $t->category }}</td>
                                        <td class="px-5 py-3.5"><x-status-badge :status="$t->status" /></td>
                                        <td class="px-5 py-3.5 text-right">
                                            <a href="{{ route('portal.tickets.show', $t) }}" class="text-indigo-600 font-semibold hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
