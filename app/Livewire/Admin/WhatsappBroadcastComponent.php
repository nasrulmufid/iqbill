<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\CompanySetting;
use App\Models\MikrotikRouter;
use App\Models\NotificationTemplate;
use App\Models\Package;
use App\Models\WhatsappBroadcast;
use App\Services\Notification\WhatsappBroadcastService;
use App\Services\Notification\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

class WhatsappBroadcastComponent extends Component
{
    public string $title = '';

    public string $segment = 'all';

    public ?int $routerId = null;

    public ?int $packageId = null;

    public string $search = '';

    public string $templateKey = '';

    public string $messageBody = '';

    public string $dispatchMode = 'sync';

    public int $delaySeconds = 2;

    /** @var array<int, int> */
    public array $selectedCustomerIds = [];

    /** @var array<int, int> */
    public array $excludedCustomerIds = [];

    public ?int $inspectBroadcastId = null;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(Request $request): void
    {
        $preselected = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $request->query('customer_ids', [])
        ))));

        if ($preselected !== []) {
            $this->segment = 'manual';
            $this->selectedCustomerIds = $preselected;
        }
    }

    public function updatedSegment(string $value): void
    {
        $this->excludedCustomerIds = [];
        $this->reset(['errorMessage']);
    }

    public function updatedTemplateKey(string $value): void
    {
        if ($value === '') {
            return;
        }

        $template = NotificationTemplate::query()->where('key', $value)->first();
        if ($template !== null) {
            $this->messageBody = $template->body_template;
            if (trim($this->title) === '') {
                $this->title = $template->label;
            }
        }
    }

    public function toggleCustomer(int $customerId): void
    {
        if ($this->segment === 'manual') {
            if (in_array($customerId, $this->selectedCustomerIds, true)) {
                $this->selectedCustomerIds = array_values(array_diff($this->selectedCustomerIds, [$customerId]));
            } else {
                $this->selectedCustomerIds[] = $customerId;
                $this->selectedCustomerIds = array_values(array_unique($this->selectedCustomerIds));
            }

            return;
        }

        if (in_array($customerId, $this->excludedCustomerIds, true)) {
            $this->excludedCustomerIds = array_values(array_diff($this->excludedCustomerIds, [$customerId]));
        } else {
            $this->excludedCustomerIds[] = $customerId;
            $this->excludedCustomerIds = array_values(array_unique($this->excludedCustomerIds));
        }
    }

    public function selectAllVisible(WhatsappBroadcastService $service): void
    {
        $ids = $service->candidateCustomersQuery($this->filterPayload())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->segment === 'manual') {
            $this->selectedCustomerIds = array_values(array_unique(array_merge($this->selectedCustomerIds, $ids)));
        } else {
            $this->excludedCustomerIds = array_values(array_diff($this->excludedCustomerIds, $ids));
        }
    }

    public function deselectAllVisible(WhatsappBroadcastService $service): void
    {
        $ids = $service->candidateCustomersQuery($this->filterPayload())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->segment === 'manual') {
            $this->selectedCustomerIds = array_values(array_diff($this->selectedCustomerIds, $ids));
        } else {
            $this->excludedCustomerIds = array_values(array_unique(array_merge($this->excludedCustomerIds, $ids)));
        }
    }

    public function resetExclusions(): void
    {
        $this->excludedCustomerIds = [];
    }

    public function clearManualSelection(): void
    {
        $this->selectedCustomerIds = [];
    }

    public function sendBroadcast(WhatsappBroadcastService $service): void
    {
        $this->reset(['errorMessage', 'statusMessage']);

        $this->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'segment' => ['required', 'in:all,active,isolated,unpaid_invoice,pending_installation,suspended,manual'],
            'messageBody' => ['required', 'string', 'max:4000'],
            'dispatchMode' => ['required', 'in:sync,queue'],
            'delaySeconds' => ['required', 'integer', 'min:0', 'max:30'],
        ], [
            'messageBody.required' => 'WhatsApp message body is required.',
        ]);

        $filters = $this->filterPayload();
        $recipientIds = $service->resolveRecipientIds($filters);

        if ($recipientIds === []) {
            $this->errorMessage = 'No eligible customers selected with a valid WhatsApp number.';

            return;
        }

        try {
            $broadcast = $service->dispatchBroadcast(
                auth()->user(),
                $recipientIds,
                [
                    'title' => $this->title,
                    'target_segment' => $this->segment,
                    'segment_filters' => [
                        'router_id' => $this->routerId,
                        'package_id' => $this->packageId,
                        'search' => $this->search,
                    ],
                    'template_key' => $this->templateKey ?: null,
                    'message_body' => $this->messageBody,
                    'dispatch_mode' => $this->dispatchMode,
                    'delay_seconds' => $this->delaySeconds,
                ],
                request()->ip()
            );

            if ($broadcast->dispatch_mode === 'sync') {
                if ($broadcast->failed_count > 0 && $broadcast->sent_count === 0) {
                    $this->errorMessage = "All {$broadcast->failed_count} message(s) failed to send. Please check Go-WhatsApp configuration/device connection and review logs.";
                } elseif ($broadcast->failed_count > 0) {
                    $this->statusMessage = "Broadcast completed: {$broadcast->sent_count} sent, {$broadcast->failed_count} failed.";
                } else {
                    $this->statusMessage = "Broadcast completed! {$broadcast->sent_count} WhatsApp message(s) sent successfully.";
                }
            } else {
                $this->statusMessage = "Broadcast queued for {$broadcast->total_recipients} customer(s) ({$broadcast->delay_seconds}s delay between messages).";
            }
        } catch (InvalidArgumentException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            $this->errorMessage = 'Failed to run WhatsApp broadcast: ' . $e->getMessage();
        }
    }

    public function retryFailedBroadcast(int $broadcastId, WhatsappBroadcastService $service): void
    {
        $this->reset(['errorMessage', 'statusMessage']);

        $broadcast = WhatsappBroadcast::query()->find($broadcastId);
        if (!$broadcast) {
            $this->errorMessage = 'Broadcast history record not found.';

            return;
        }

        $retried = $service->retryFailed($broadcast);
        $this->statusMessage = $retried > 0
            ? "{$retried} failed message(s) re-processed / re-queued."
            : 'No failed recipients found in this campaign.';
    }

    public function openLogs(int $broadcastId): void
    {
        $this->inspectBroadcastId = $broadcastId;
    }

    public function closeLogs(): void
    {
        $this->inspectBroadcastId = null;
    }

    public function render(WhatsappBroadcastService $service): View
    {
        $settings = CompanySetting::current();
        $filters = $this->filterPayload();

        $candidateQuery = $service->candidateCustomersQuery($filters);
        $candidateTotalCount = (clone $candidateQuery)->count();
        $candidates = (clone $candidateQuery)->limit(100)->get();

        $recipientIds = $service->resolveRecipientIds($filters);
        $recipientCount = count($recipientIds);

        $previewCustomer = null;
        if ($recipientIds !== []) {
            $previewCustomer = $candidates->firstWhere('id', $recipientIds[0])
                ?? \App\Models\Customer::query()->with(['subscriptions.package'])->find($recipientIds[0]);
        }

        $previewMessage = $service->previewForCustomer($previewCustomer, $this->messageBody);
        $previewHtml = WhatsAppService::formatPreviewHtml($previewMessage);

        if (NotificationTemplate::query()->count() === 0) {
            foreach (NotificationTemplate::defaults() as $item) {
                NotificationTemplate::query()->firstOrCreate(['key' => $item['key']], $item + ['is_active' => true]);
            }
        }

        $broadcasts = WhatsappBroadcast::query()
            ->with('creator:id,name')
            ->latest('id')
            ->limit(15)
            ->get();

        $hasActiveBroadcast = $broadcasts->contains(
            fn (WhatsappBroadcast $b) => in_array($b->status, ['queued', 'processing'], true)
        );

        $inspectedBroadcast = $this->inspectBroadcastId
            ? WhatsappBroadcast::query()
                ->with(['logs' => fn ($q) => $q->with('customer:id,customer_code,full_name')->orderBy('id')])
                ->find($this->inspectBroadcastId)
            : null;

        return view('livewire.admin.whatsapp-broadcast-component', [
            'segments' => WhatsappBroadcastService::segments(),
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'packages' => Package::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'service_type']),
            'templates' => NotificationTemplate::query()->orderBy('id')->get(),
            'placeholders' => WhatsAppService::availablePlaceholders(),
            'candidates' => $candidates,
            'candidateTotalCount' => $candidateTotalCount,
            'recipientCount' => $recipientCount,
            'previewCustomer' => $previewCustomer,
            'previewMessage' => $previewMessage,
            'previewHtml' => $previewHtml,
            'broadcasts' => $broadcasts,
            'hasActiveBroadcast' => $hasActiveBroadcast,
            'inspectedBroadcast' => $inspectedBroadcast,
            'gatewayConfigured' => !empty($settings->wa_gateway_url),
            'gatewayUrl' => $settings->wa_gateway_url,
            'isSuperAdmin' => auth()->user()?->hasRole(UserRole::SuperAdmin) ?? false,
        ])->layout('layouts.app', ['title' => 'Bulk WhatsApp Broadcast']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterPayload(): array
    {
        return [
            'segment' => $this->segment,
            'mikrotik_router_id' => $this->routerId,
            'package_id' => $this->packageId,
            'search' => $this->search,
            'selected_ids' => $this->selectedCustomerIds,
            'excluded_ids' => $this->excludedCustomerIds,
        ];
    }
}
