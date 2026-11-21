<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Notification\TelegramNotificationService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->appendToGroup('web', SecurityHeaders::class);

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (\Throwable $e): void {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return;
            }

            try {
                if (app()->bound(TelegramNotificationService::class) && !app()->runningUnitTests()) {
                    app(TelegramNotificationService::class)->notifyError(
                        class_basename($e),
                        $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
                    );
                }
            } catch (\Throwable) {
                // Prevent error handler failures from triggering cascading exceptions
            }
        });
    })->create();
