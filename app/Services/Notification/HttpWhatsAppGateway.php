<?php

declare(strict_types=1);

namespace App\Services\Notification;

class HttpWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function __construct(
        private readonly GowaClient $gowaClient
    ) {
    }

    public function send(string $phone, string $message): array
    {
        return $this->gowaClient->sendMessage($phone, $message);
    }
}
