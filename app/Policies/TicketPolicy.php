<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        if (!$user->is_active) {
            return false;
        }

        if ($user->role->isStaff()) {
            return true;
        }

        return $user->customer?->id === $ticket->customer_id;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }
}
