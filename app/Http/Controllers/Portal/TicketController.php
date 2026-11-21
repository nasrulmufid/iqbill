<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->user()?->customer;

        $tickets = $customer
            ? Ticket::query()
                ->with(['subscription.package', 'assignedTo'])
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->paginate(15)
            : collect();

        $subscriptions = $customer
            ? $customer->subscriptions()->with('package')->get()
            : collect();

        return view('portal.tickets.index', [
            'customer' => $customer,
            'tickets' => $tickets,
            'subscriptions' => $subscriptions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $request->user()?->customer;
        if ($customer === null) {
            return back()->with('error', 'Your account is not linked to a customer profile.');
        }

        $data = $request->validate([
            'subscription_id' => ['nullable', 'exists:subscriptions,id'],
            'category' => ['required', 'in:gangguan,pemasangan,tagihan,lainnya'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:3000'],
        ]);

        if (!empty($data['subscription_id'])) {
            $ownsSub = $customer->subscriptions()->where('id', $data['subscription_id'])->exists();
            if (!$ownsSub) {
                abort(403);
            }
        }

        $nextSeq = (int) Ticket::query()->max('id') + 1;
        $ticket = Ticket::query()->create([
            'ticket_number' => sprintf('TKT-%s-%04d', now('Asia/Jakarta')->format('Ym'), $nextSeq),
            'customer_id' => $customer->id,
            'subscription_id' => $data['subscription_id'] ?? null,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => 'open',
        ]);

        return redirect()->route('portal.tickets.show', $ticket)
            ->with('success', "Ticket {$ticket->ticket_number} created successfully. Our support team will follow up shortly.");
    }

    public function show(Ticket $ticket): View
    {
        Gate::authorize('view', $ticket);

        $ticket->load([
            'subscription.package',
            'assignedTo',
            'replies' => fn ($q) => $q->where('is_internal_note', false)->with('user'),
        ]);

        return view('portal.tickets.show', [
            'ticket' => $ticket,
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('reply', $ticket);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        TicketReply::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => (int) $request->user()?->id,
            'message' => $data['message'],
            'is_internal_note' => false,
            'created_at' => now(),
        ]);

        return back()->with('success', 'Reply sent successfully.');
    }
}
