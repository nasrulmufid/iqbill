<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Jobs\SendBulkWhatsAppMessageJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappNotificationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WhatsappBroadcastService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function segments(): array
    {
        return [
            'all' => 'All Customers',
            'active' => 'Active Service',
            'isolated' => 'Currently Isolated',
            'unpaid_invoice' => 'Has Unpaid Invoice',
            'pending_installation' => 'Pending Activation',
            'suspended' => 'Suspended Service',
            'manual' => 'Select Customers Manually',
        ];
    }

    /**
     * Query customers displayed in the target checklist.
     *
     * @param  array<string, mixed>  $filters
     */
    public function candidateCustomersQuery(array $filters): Builder
    {
        $segment = (string) ($filters['segment'] ?? 'all');
        $routerId = !empty($filters['mikrotik_router_id']) ? (int) $filters['mikrotik_router_id'] : null;
        $packageId = !empty($filters['package_id']) ? (int) $filters['package_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Customer::query()
            ->whereNotNull('phone_whatsapp')
            ->where('phone_whatsapp', '!=', '')
            ->with(['subscriptions.package', 'subscriptions.mikrotikRouter']);

        $subscriptionStatuses = ['active', 'isolated', 'pending_installation', 'suspended'];

        if (in_array($segment, $subscriptionStatuses, true) || $routerId !== null || $packageId !== null) {
            $query->whereHas('subscriptions', function (Builder $sub) use ($segment, $subscriptionStatuses, $routerId, $packageId): void {
                if (in_array($segment, $subscriptionStatuses, true)) {
                    $sub->where('status', $segment);
                }
                if ($routerId !== null) {
                    $sub->where('mikrotik_router_id', $routerId);
                }
                if ($packageId !== null) {
                    $sub->where('package_id', $packageId);
                }
            });
        }

        if ($segment === 'unpaid_invoice') {
            $query->whereHas('invoices', fn (Builder $inv) => $inv->whereIn('status', ['unpaid', 'overdue']));
        }

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('phone_whatsapp', 'like', "%{$search}%")
                    ->orWhereHas('subscriptions', fn (Builder $sub) => $sub->where('service_username', 'like', "%{$search}%"));
            });
        }

        return $query->orderBy('full_name');
    }

    /**
     * Return final customer IDs to receive the broadcast according to segment and checklist selections.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    public function resolveRecipientIds(array $filters): array
    {
        $segment = (string) ($filters['segment'] ?? 'all');

        if ($segment === 'manual') {
            $selected = array_values(array_unique(array_filter(array_map('intval', (array) ($filters['selected_ids'] ?? [])))));
            if ($selected === []) {
                return [];
            }

            return Customer::query()
                ->whereIn('id', $selected)
                ->whereNotNull('phone_whatsapp')
                ->where('phone_whatsapp', '!=', '')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $excluded = array_values(array_unique(array_filter(array_map('intval', (array) ($filters['excluded_ids'] ?? [])))));

        return $this->candidateCustomersQuery($filters)
            ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('id', $excluded))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function previewForCustomer(?Customer $customer, string $rawMessage): string
    {
        if (trim($rawMessage) === '') {
            return '';
        }

        if ($customer === null) {
            return WhatsAppService::previewSample($rawMessage);
        }

        $openInvoice = $customer->invoices()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->latest('id')
            ->first();

        return $this->whatsApp->renderCustomerMessage($customer, $rawMessage, $openInvoice);
    }

    /**
     * @param  array<int, int>  $customerIds
     * @param  array<string, mixed>  $payload
     */
    public function dispatchBroadcast(
        User $actor,
        array $customerIds,
        array $payload,
        ?string $ipAddress = null
    ): WhatsappBroadcast {
        $rawMessage = trim((string) ($payload['message_body'] ?? ''));
        if ($rawMessage === '') {
            throw new InvalidArgumentException('WhatsApp message body cannot be empty.');
        }

        $normalizedIds = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        $customers = Customer::query()
            ->whereIn('id', $normalizedIds)
            ->whereNotNull('phone_whatsapp')
            ->where('phone_whatsapp', '!=', '')
            ->with(['subscriptions.package'])
            ->orderBy('id')
            ->get();

        if ($customers->isEmpty()) {
            throw new InvalidArgumentException('No valid recipients with a WhatsApp number found.');
        }

        $dispatchMode = ($payload['dispatch_mode'] ?? 'sync') === 'queue' ? 'queue' : 'sync';
        $delaySeconds = max(0, min(30, (int) ($payload['delay_seconds'] ?? 2)));
        $templateKey = !empty($payload['template_key']) ? (string) $payload['template_key'] : null;
        $title = trim((string) ($payload['title'] ?? ''));

        $openInvoicesByCustomer = Invoice::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderByDesc('id')
            ->get()
            ->unique('customer_id')
            ->keyBy('customer_id');

        [$broadcast, $logs] = DB::transaction(function () use (
            $actor,
            $customers,
            $openInvoicesByCustomer,
            $rawMessage,
            $dispatchMode,
            $delaySeconds,
            $templateKey,
            $title,
            $payload,
            $ipAddress
        ): array {
            $broadcast = WhatsappBroadcast::query()->create([
                'created_by' => $actor->id,
                'title' => $title !== '' ? $title : null,
                'target_segment' => (string) ($payload['target_segment'] ?? 'all'),
                'segment_filters' => $payload['segment_filters'] ?? null,
                'template_key' => $templateKey,
                'message_body' => $rawMessage,
                'dispatch_mode' => $dispatchMode,
                'delay_seconds' => $delaySeconds,
                'total_recipients' => $customers->count(),
                'sent_count' => 0,
                'failed_count' => 0,
                'status' => $dispatchMode === 'sync' ? 'processing' : 'queued',
                'started_at' => $dispatchMode === 'sync' ? now() : null,
            ]);

            $logs = collect();
            $logTemplateType = $templateKey ?: 'bulk_broadcast';
            $now = now();

            foreach ($customers as $customer) {
                $invoice = $openInvoicesByCustomer->get($customer->id);
                $renderedMessage = $this->whatsApp->renderCustomerMessage($customer, $rawMessage, $invoice);

                $logs->push(WhatsappNotificationLog::query()->create([
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice?->id,
                    'broadcast_id' => $broadcast->id,
                    'template_type' => $logTemplateType,
                    'recipient_phone' => $customer->phone_whatsapp,
                    'message_body' => $renderedMessage,
                    'delivery_status' => 'queued',
                    'created_at' => $now,
                ]));
            }

            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'whatsapp_broadcast_dispatched',
                'auditable_type' => WhatsappBroadcast::class,
                'auditable_id' => $broadcast->id,
                'new_values' => [
                    'target_segment' => $broadcast->target_segment,
                    'total_recipients' => $broadcast->total_recipients,
                    'dispatch_mode' => $dispatchMode,
                ],
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ]);

            return [$broadcast, $logs];
        });

        if ($dispatchMode === 'sync') {
            foreach ($logs as $log) {
                $this->whatsApp->deliverExistingLog($log, 'bulk_broadcast');
                $this->recordJobOutcome($broadcast->id, $log->delivery_status === 'sent');
            }

            return $broadcast->refresh();
        }

        foreach ($logs->values() as $index => $log) {
            $pending = SendBulkWhatsAppMessageJob::dispatch($log->id, $broadcast->id);
            if ($delaySeconds > 0 && $index > 0) {
                $pending->delay(now()->addSeconds($index * $delaySeconds));
            }
        }

        return $broadcast->refresh();
    }

    public function retryFailed(WhatsappBroadcast $broadcast): int
    {
        [$logs, $dispatchMode, $delaySeconds] = DB::transaction(function () use ($broadcast): array {
            $locked = WhatsappBroadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();
            $failedLogs = $locked->logs()
                ->where('delivery_status', 'failed')
                ->orderBy('id')
                ->get();

            if ($failedLogs->isEmpty()) {
                return [collect(), $locked->dispatch_mode, $locked->delay_seconds];
            }

            WhatsappNotificationLog::query()
                ->whereIn('id', $failedLogs->pluck('id'))
                ->update([
                    'delivery_status' => 'queued',
                    'gateway_response' => 'Waiting for retry...',
                ]);

            $locked->forceFill([
                'failed_count' => max(0, $locked->failed_count - $failedLogs->count()),
                'status' => $locked->dispatch_mode === 'sync' ? 'processing' : 'queued',
                'completed_at' => null,
            ])->save();

            $requeuedLogs = WhatsappNotificationLog::query()
                ->whereIn('id', $failedLogs->pluck('id'))
                ->orderBy('id')
                ->get();

            return [$requeuedLogs, $locked->dispatch_mode, $locked->delay_seconds];
        });

        if ($logs->isEmpty()) {
            return 0;
        }

        if ($dispatchMode === 'sync') {
            foreach ($logs as $log) {
                $this->whatsApp->deliverExistingLog($log, 'bulk_broadcast_retry');
                $this->recordJobOutcome($broadcast->id, $log->delivery_status === 'sent');
            }
        } else {
            foreach ($logs->values() as $index => $log) {
                $pending = SendBulkWhatsAppMessageJob::dispatch($log->id, $broadcast->id);
                if ($delaySeconds > 0 && $index > 0) {
                    $pending->delay(now()->addSeconds($index * $delaySeconds));
                }
            }
        }

        return $logs->count();
    }

    public function recordJobOutcome(int $broadcastId, bool $success): void
    {
        DB::transaction(function () use ($broadcastId, $success): void {
            $broadcast = WhatsappBroadcast::query()->whereKey($broadcastId)->lockForUpdate()->first();
            if (!$broadcast) {
                return;
            }

            if ($broadcast->started_at === null) {
                $broadcast->started_at = now();
            }

            if ($success) {
                $broadcast->sent_count++;
            } else {
                $broadcast->failed_count++;
            }

            $processed = $broadcast->sent_count + $broadcast->failed_count;
            if ($processed >= $broadcast->total_recipients) {
                $broadcast->status = $broadcast->failed_count > 0 ? 'completed_with_errors' : 'completed';
                $broadcast->completed_at = now();
            } else {
                $broadcast->status = 'processing';
            }

            $broadcast->save();
        });
    }
}
