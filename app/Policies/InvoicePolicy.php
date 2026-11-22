<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        if (!$user->is_active) {
            return false;
        }

        if ($user->hasRole(UserRole::SuperAdmin, UserRole::Finance, UserRole::Noc)) {
            return true;
        }

        return $user->customer?->id === $invoice->customer_id;
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        if (!$user->is_active) {
            return false;
        }

        return $user->customer?->id === $invoice->customer_id;
    }
}
