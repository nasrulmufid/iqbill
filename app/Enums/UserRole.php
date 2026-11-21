<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Finance = 'finance';
    case Noc = 'noc';
    case Customer = 'customer';

    public function isStaff(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Finance, self::Noc => true,
            self::Customer => false,
        };
    }
}
