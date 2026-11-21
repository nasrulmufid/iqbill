<?php

declare(strict_types=1);

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;

class RouterOsClientFactory
{
    public function make(MikrotikRouter $router): RouterOsClientInterface
    {
        return $router->isRouterOs7()
            ? new RouterOs7RestClient($router)
            : new RouterOs6BinaryClient($router);
    }
}
