<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\SystemHealthService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function health(SystemHealthService $healthService): JsonResponse
    {
        $checks = $healthService->checkMinimalReadiness();
        $healthy = $checks['database'] && $checks['cache'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'timestamp' => now('Asia/Jakarta')->toIso8601String(),
            'checks' => [
                'database' => $checks['database'] ? 'up' : 'down',
                'cache' => $checks['cache'] ? 'up' : 'down',
            ],
        ], $healthy ? 200 : 503);
    }

    public function metrics(SystemHealthService $healthService): JsonResponse
    {
        $automation = $healthService->getAutomationStatus();

        return response()->json([
            'timestamp' => now('Asia/Jakarta')->toIso8601String(),
            'host' => $healthService->collectHostMetrics(),
            'services' => $healthService->checkConnectivity(includeExternal: true, automation: $automation),
            'automation' => $automation,
            'stats' => $healthService->collectPanelStats(),
        ]);
    }
}
