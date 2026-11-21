<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\InvoiceService;
use Illuminate\Console\Command;
use Throwable;

class GenerateInvoicesCommand extends Command
{
    protected $signature = 'billing:generate-invoices';

    protected $description = 'Issue 1 invoice per billing cycle 7 days before next_billing_date and send a Smart Payment Link.';

    public function handle(InvoiceService $invoiceService): int
    {
        $targetDate = now('Asia/Jakarta')->addDays(7)->toDateString();

        $subscriptions = Subscription::query()
            ->with(['customer', 'package'])
            ->where('status', SubscriptionStatus::Active->value)
            ->whereDate('next_billing_date', '<=', $targetDate)
            ->get();

        $createdCount = 0;

        foreach ($subscriptions as $subscription) {
            try {
                if ($invoiceService->issueForSubscription($subscription) !== null) {
                    $createdCount++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Failed to issue invoice for subscription #{$subscription->id}: {$e->getMessage()}");
            }
        }

        $this->info("Generated {$createdCount} invoice(s).");

        return self::SUCCESS;
    }
}
