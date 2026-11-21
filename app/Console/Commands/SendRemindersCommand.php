<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Invoice;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'billing:send-reminders';

    protected $description = 'Send H-3 and H-1 WhatsApp payment reminders before due date with a Smart Payment Link.';

    public function handle(): int
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $targets = [
            'reminder_h3' => $today->copy()->addDays(3)->toDateString(),
            'reminder_h1' => $today->copy()->addDay()->toDateString(),
        ];

        $sent = 0;

        foreach ($targets as $template => $dueDate) {
            $invoices = Invoice::query()
                ->with('customer')
                ->where('status', InvoiceStatus::Unpaid->value)
                ->whereDate('due_date', $dueDate)
                ->get();

            foreach ($invoices as $invoice) {
                SendWhatsAppNotificationJob::dispatch($invoice->customer, $template, $invoice);
                $sent++;
            }
        }

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
