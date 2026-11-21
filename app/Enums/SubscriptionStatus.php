<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    case PendingInstallation = 'pending_installation';
    case Active = 'active';
    case Isolated = 'isolated';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
}
