@extends('layouts.app', ['title' => 'Ticket ' . $ticket->ticket_number])

@section('content')
<div class="space-y-6">
    <x-page-header :title="$ticket->ticket_number . ' — ' . $ticket->subject"
                   :subtitle="$ticket->customer->full_name . ' (' . $ticket->customer->phone_whatsapp . ')'">
        <x-slot:actions>
            @if(!$ticket->assigned_to_user_id)
                <form method="POST" action="{{ route('admin.tickets.claim', $ticket) }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Claim Ticket</button>
                </form>
            @endif
            <a href="{{ route('admin.tickets.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-5">
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-3">
                <div class="flex items-center gap-2">
                    <x-status-badge :status="$ticket->status" />
                    <x-status-badge :status="$ticket->priority" />
                    <span class="text-xs text-slate-400">{{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</span>
                </div>
                <p class="text-sm text-slate-700 whitespace-pre-line">{{ $ticket->description }}</p>
            </div>

            <div class="space-y-3">
                @foreach($ticket->replies as $reply)
                    <div class="rounded-2xl border p-4 {{ $reply->is_internal_note ? 'border-amber-200 bg-amber-50/70' : 'border-slate-200 bg-white' }}">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span class="font-semibold text-slate-900">
                                {{ $reply->user->name }}
                                @if($reply->is_internal_note)
                                    <span class="ml-1 rounded bg-amber-200/70 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">INTERNAL NOTE</span>
                                @endif
                            </span>
                            <span>{{ $reply->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ $reply->message }}</p>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3 shadow-sm">
                @csrf
                <h3 class="font-bold text-slate-900">Send Reply / Note</h3>
                <textarea name="message" rows="3" required placeholder="Write a reply to the customer or an internal technician note..." class="w-full rounded-xl border-slate-300 text-sm"></textarea>
                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="is_internal_note" value="1" class="rounded border-slate-300">
                        Internal note (not visible to customer)
                    </label>
                    <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white">Send Reply</button>
                </div>
            </form>
        </div>

        <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4 h-fit shadow-sm">
            @csrf
            @method('PATCH')
            <h3 class="font-bold text-slate-900">Update Handling</h3>
            <div>
                <label class="block text-xs font-medium text-slate-600">Assigned Technician</label>
                <div class="mt-1 text-sm font-semibold text-slate-900">{{ $ticket->assignedTo->name ?? 'Unassigned' }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600">Ticket Status</label>
                <select name="status" class="mt-1 w-full rounded-xl border-slate-300 text-sm">
                    @foreach(['open', 'in_progress', 'resolved', 'closed'] as $s)
                        <option value="{{ $s }}" @selected($ticket->status === $s)>{{ strtoupper(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600">Resolution Notes</label>
                <textarea name="resolution_notes" rows="4" class="mt-1 w-full rounded-xl border-slate-300 text-sm">{{ $ticket->resolution_notes }}</textarea>
            </div>
            <button type="submit" class="w-full rounded-xl bg-emerald-600 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                Save Ticket Status
            </button>
        </form>
    </div>
</div>
@endsection
