<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $priority = $request->string('priority')->toString();

        $tickets = Ticket::query()
            ->with(['customer', 'subscription.package', 'assignedTo'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($priority !== '', fn ($q) => $q->where('priority', $priority))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'status' => $status,
            'priority' => $priority,
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $ticket->load(['customer', 'subscription.package', 'assignedTo', 'replies.user']);

        return view('admin.tickets.show', [
            'ticket' => $ticket,
        ]);
    }

    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $ticket->forceFill([
            'assigned_to_user_id' => $request->user()?->id,
            'status' => $ticket->status === 'open' ? 'in_progress' : $ticket->status,
        ])->save();

        TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => (int) $request->user()?->id,
            'message' => 'Ticket claimed by ' . ($request->user()?->name ?? 'Technician') . ' for handling.',
            'is_internal_note' => true,
            'created_at' => now(),
        ]);

        return back()->with('success', "Ticket {$ticket->ticket_number} claimed successfully.");
    }

    public function updateStatus(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'resolution_notes' => ['nullable', 'string'],
        ]);

        $updates = [
            'status' => $data['status'],
            'resolution_notes' => $data['resolution_notes'] ?? $ticket->resolution_notes,
        ];

        if ($data['status'] === 'resolved' && $ticket->resolved_at === null) {
            $updates['resolved_at'] = now();
        }
        if ($data['status'] === 'closed' && $ticket->closed_at === null) {
            $updates['closed_at'] = now();
        }

        $ticket->forceFill($updates)->save();

        if (in_array($data['status'], ['resolved', 'closed'], true)) {
            SendWhatsAppNotificationJob::dispatch($ticket->customer, 'ticket_updated', null);
        }

        return back()->with('success', 'Ticket status updated successfully.');
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'is_internal_note' => ['nullable', 'boolean'],
        ]);

        TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => (int) $request->user()?->id,
            'message' => $data['message'],
            'is_internal_note' => $request->boolean('is_internal_note'),
            'created_at' => now(),
        ]);

        if ($ticket->status === 'open' && !$request->boolean('is_internal_note')) {
            $ticket->forceFill(['status' => 'in_progress'])->save();
        }

        return back()->with('success', 'Ticket reply sent successfully.');
    }
}
