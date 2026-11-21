<?php

declare(strict_types=1);

namespace App\Services\Notification;

interface WhatsAppGatewayInterface
{
    /**
     * @return array{success: bool, response: string}
     */
    public function send(string $phone, string $message): array;
}
