@extends('layouts.app', ['title' => 'Ticket ' . $ticket->ticket_number])

@section('content')
<div class="max-w-3xl space-y-6">
    <x-page-header :title="$ticket->ticket_number . ' — ' . $ticket->subject"
                   :subtitle="'Current status: ' . strtoupper($ticket->status)">
        <x-slot:actions>
            <a href="{{ route('portal.tickets.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Back</a>
        </x-slot:actions>
    </x-page-header>

    <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-3">
        <div class="flex items-center gap-2">
            <x-status-badge :status="$ticket->status" />
            <span class="text-xs text-slate-400">Created {{ $ticket->created_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</span>
        </div>
        <p class="text-sm text-slate-700 whitespace-pre-line">{{ $ticket->description }}</p>
        @if($ticket->resolution_notes)
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-800">
                <strong>Resolution Notes:</strong> {{ $ticket->resolution_notes }}
            </div>
        @endif
    </div>

    <div class="space-y-3">
        @foreach($ticket->replies as $reply)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span class="font-semibold text-slate-900">{{ $reply->user->name }}</span>
                    <span>{{ $reply->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ $reply->message }}</p>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('portal.tickets.reply', $ticket) }}" class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3 shadow-sm">
        @csrf
        <textarea name="message" rows="3" required placeholder="Write a reply..." class="w-full rounded-xl border-slate-300 text-sm"></textarea>
        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white">Send Reply</button>
    </form>
</div>
@endsection
