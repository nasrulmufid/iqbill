<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mikrotik\MikrotikProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerOnboardingService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly ManualCashExtensionService $cashService
    ) {
    }

    public function generateUniqueCustomerCode(): string
    {
        $datePart = now('Asia/Jakarta')->format('ymd');

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $rand = str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $candidate = "IQ-{$datePart}{$rand}";

            if (!Customer::query()->where('customer_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Millisecond fallback if 30 random attempts collide
        return "IQ-{$datePart}" . substr((string) (int) (microtime(true) * 1000), -4);
    }

    public function defaultPortalUsername(string $customerCode): string
    {
        $stripped = preg_replace('/^IQ-?/i', '', trim($customerCode)) ?? trim($customerCode);

        return $stripped !== '' ? $stripped : trim($customerCode);
    }

    /**
     * Register customer profile, portal user account, PPPoE subscription, and initial payment (online or cash).
     *
     * @param  array<string, mixed>  $data
     * @return array{customer: Customer, user: User, subscription: ?Subscription, portal_username: string, portal_password: string}
     */
    public function register(array $data, ?User $actor = null, ?string $ipAddress = null): array
    {
        if (!empty($data['package_id']) && !empty($data['mikrotik_router_id'])) {
            Package::assertRouterAllowed((int) $data['package_id'], (int) $data['mikrotik_router_id']);
        }

        $customerCode = trim((string) ($data['customer_code'] ?? ''));
        if ($customerCode === '') {
            $customerCode = $this->generateUniqueCustomerCode();
        }

        $portalUsername = trim((string) ($data['portal_username'] ?? ''));
        if ($portalUsername === '') {
            $portalUsername = $this->defaultPortalUsername($customerCode);
        }

        $portalPassword = (string) ($data['portal_password'] ?? '');
        if ($portalPassword === '') {
            $portalPassword = Str::password(12, letters: true, numbers: true, symbols: false);
        }

        $normalizedWhatsapp = Customer::normalizeWhatsappPhone((string) ($data['phone_whatsapp'] ?? ''));
        $phoneWhatsapp = $normalizedWhatsapp !== '' ? $normalizedWhatsapp : trim((string) ($data['phone_whatsapp'] ?? ''));

        $result = DB::transaction(function () use ($data, $customerCode, $portalUsername, $portalPassword, $phoneWhatsapp): array {
            $email = !empty($data['email']) ? trim((string) $data['email']) : null;
            $userEmail = ($email !== null && !User::query()->where('email', $email)->exists()) ? $email : null;
            $userPhone = !User::query()->where('phone', $phoneWhatsapp)->exists()
                ? $phoneWhatsapp
                : null;

            $user = User::query()->create([
                'name' => $data['full_name'],
                'email' => $userEmail,
                'username' => $portalUsername,
                'phone' => $userPhone,
                'password' => Hash::make($portalPassword),
                'role' => UserRole::Customer,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $customer = Customer::query()->create([
                'user_id' => $user->id,
                'customer_code' => $customerCode,
                'full_name' => $data['full_name'],
                'nik_npwp' => $data['nik_npwp'] ?? null,
                'phone_whatsapp' => $phoneWhatsapp,
                'email' => $email,
                'installation_address' => $data['installation_address'],
                'latitude' => !empty($data['latitude']) ? $data['latitude'] : null,
                'longitude' => !empty($data['longitude']) ? $data['longitude'] : null,
            ]);

            $subscription = null;
            if (!empty($data['package_id']) && !empty($data['mikrotik_router_id'])) {
                $billingDay = (int) ($data['billing_day'] ?? now('Asia/Jakarta')->day);
                $billingDay = max(1, min(28, $billingDay));
                $isolirDay = !empty($data['isolir_day'])
                    ? max(1, min(28, (int) $data['isolir_day']))
                    : $billingDay;

                $paymentMethod = $data['payment_method'] ?? 'online';
                $isCash = $paymentMethod === 'cash';

                $pppoeUsername = trim((string) ($data['service_username'] ?? ''));
                if ($pppoeUsername === '') {
                    $pppoeUsername = $portalUsername;
                }

                $pppoePassword = (string) ($data['service_password'] ?? '');
                if ($pppoePassword === '') {
                    $pppoePassword = $portalPassword;
                }

                $nextBillingDate = now('Asia/Jakarta')->toDateString();

                $subscription = Subscription::query()->create([
                    'customer_id' => $customer->id,
                    'package_id' => $data['package_id'],
                    'mikrotik_router_id' => $data['mikrotik_router_id'],
                    'service_username' => $pppoeUsername,
                    'service_password_encrypted' => $pppoePassword,
                    'static_ip_address' => !empty($data['static_ip_address']) ? trim((string) $data['static_ip_address']) : null,
                    'isolir_profile_name' => !empty($data['isolir_profile_name']) ? trim((string) $data['isolir_profile_name']) : null,
                    'billing_day' => $billingDay,
                    'isolir_day' => $isolirDay,
                    'auto_isolate' => (bool) ($data['auto_isolate'] ?? true),
                    'next_billing_date' => $nextBillingDate,
                    'status' => $isCash ? SubscriptionStatus::Active : SubscriptionStatus::PendingInstallation,
                    'activated_at' => $isCash ? now() : null,
                ]);
            }

            return [
                'customer' => $customer,
                'user' => $user,
                'subscription' => $subscription,
                'portal_username' => $portalUsername,
                'portal_password' => $portalPassword,
            ];
        });

        /** @var Subscription|null $subscription */
        $subscription = $result['subscription'];

        if ($subscription !== null) {
            app(MikrotikProvisioningService::class)->queueAction(
                $subscription,
                'sync_secret',
                'manual_noc',
                afterCommit: false
            );

            $paymentMethod = $data['payment_method'] ?? 'online';
            if ($paymentMethod === 'cash') {
                // Activate immediately, record cash payment & extend validity without sending initial billing WA notice
                $invoice = $this->invoiceService->issueForSubscription($subscription, notify: false);
                $this->cashService->receiveCash($subscription, $invoice, $actor, $ipAddress, sendWaReceipt: false);
            } else {
                // Online payment: issue invoice + QRIS + dispatch WhatsApp notification with Smart Payment Link
                $this->invoiceService->issueForSubscription($subscription, notify: true);
            }
        }

        return $result;
    }
}
