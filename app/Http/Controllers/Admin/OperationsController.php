<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\MikrotikRouter;
use App\Models\Package;
use App\Models\PppoeUsageLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CustomerOnboardingService;
use App\Services\Billing\CustomerSpreadsheetService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\ManualCashExtensionService;
use App\Services\Mikrotik\MikrotikProvisioningService;
use App\Services\Mikrotik\MikrotikReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class OperationsController extends Controller
{
    // --- SERVICE PACKAGES ---

    public function packages(): View
    {
        return view('admin.operations.packages', [
            'packages' => Package::query()
                ->with('routers:id,name,ros_version_type,is_active')
                ->withCount('subscriptions')
                ->latest('id')
                ->get(),
            'routers' => MikrotikRouter::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'ros_version_type', 'is_active']),
        ]);
    }

    /**
     * Shared validation for creating & updating service packages including MikroTik router assignment.
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>}
     */
    private function validatePackagePayload(Request $request, ?Package $ignore = null): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:30', $ignore ? Rule::unique('packages', 'code')->ignore($ignore->id) : 'unique:packages,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'service_type' => ['nullable', 'in:pppoe'],
            'mikrotik_profile_name' => ['required', 'string', 'max:80'],
            'speed_download_mbps' => ['required', 'integer', 'min:1'],
            'speed_upload_mbps' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'router_ids' => ['nullable', 'array'],
            'router_ids.*' => ['integer', 'distinct', 'exists:mikrotik_routers,id'],
        ];

        if ($ignore !== null) {
            $rules['is_active'] = ['nullable', 'boolean'];
        }

        $data = $request->validate($rules);
        $data['service_type'] = 'pppoe';
        $routerIds = array_values(array_unique(array_map('intval', $data['router_ids'] ?? [])));
        unset($data['router_ids']);

        return [$data, $routerIds];
    }

    public function storePackage(Request $request): RedirectResponse
    {
        [$data, $routerIds] = $this->validatePackagePayload($request);

        $package = Package::query()->create($data + ['is_active' => true]);
        $package->routers()->sync($routerIds);

        return back()->with('success', 'Package added successfully.');
    }

    public function updatePackage(Request $request, Package $package): RedirectResponse
    {
        [$data, $routerIds] = $this->validatePackagePayload($request, $package);

        $provisioningChanged = $package->mikrotik_profile_name !== $data['mikrotik_profile_name']
            || (int) $package->speed_download_mbps !== (int) $data['speed_download_mbps']
            || (int) $package->speed_upload_mbps !== (int) $data['speed_upload_mbps']
            || $package->service_type !== $data['service_type'];

        $package->update($data + ['is_active' => $request->boolean('is_active')]);

        if ($request->boolean('sync_routers') || $request->has('router_ids')) {
            $package->routers()->sync($routerIds);
        }

        if ($provisioningChanged) {
            $package->subscriptions()
                ->where('status', '!=', SubscriptionStatus::PendingInstallation->value)
                ->update([
                    'mikrotik_sync_status' => 'pending',
                    'mikrotik_pending_action' => 'sync_secret',
                    'mikrotik_last_sync_error' => null,
                ]);
        }

        return back()->with('success', "Package {$package->name} updated successfully.");
    }

    public function destroyPackage(Package $package): RedirectResponse
    {
        if ($package->subscriptions()->exists()) {
            return back()->with('error', 'Package cannot be deleted because it is still referenced by active or historical subscriptions.');
        }

        $package->delete();

        return back()->with('success', 'Package deleted successfully.');
    }

    // --- MIKROTIK ROUTERS ---

    public function routers(): View
    {
        $routers = MikrotikRouter::query()
            ->withCount([
                'subscriptions',
                'subscriptions as pending_sync_count' => fn ($q) => $q->whereIn('mikrotik_sync_status', ['pending', 'failed']),
            ])
            ->latest('id')
            ->get();

        $pendingSubscriptions = Subscription::query()
            ->with(['customer', 'package', 'mikrotikRouter'])
            ->whereIn('mikrotik_sync_status', ['pending', 'failed'])
            ->whereNotNull('mikrotik_pending_action')
            ->latest('updated_at')
            ->limit(25)
            ->get();

        return view('admin.operations.routers', [
            'routers' => $routers,
            'pendingSubscriptions' => $pendingSubscriptions,
        ]);
    }

    private function routerHostValidationRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_string($value) || !MikrotikRouter::isValidHost($value)) {
                $fail('Router host must be a valid IPv4 address or hostname and cannot target loopback, metadata, or internal services.');
            }
        };
    }

    private function routerUsernameValidationRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (!is_string($value) || !MikrotikRouter::isValidUsername($value)) {
                $fail('Router username must start with an alphanumeric character and only contain letters, numbers, ., _, @, or -.');
            }
        };
    }

    public function storeRouter(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ros_version_type' => ['required', 'in:v6,v7'],
            'host' => ['required', 'string', 'max:120', $this->routerHostValidationRule()],
            'api_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'rest_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:80', $this->routerUsernameValidationRule()],
            'password' => ['required', 'string'],
            'isolir_address_list' => ['required', 'string', 'max:80'],
            'isolir_profile_name' => ['nullable', 'string', 'max:80'],
        ]);

        MikrotikRouter::query()->create([
            'name' => $data['name'],
            'ros_version_type' => $data['ros_version_type'],
            'host' => trim($data['host']),
            'api_port' => $data['api_port'],
            'rest_port' => $data['rest_port'] ?? ($request->boolean('use_ssl') ? 443 : 80),
            'ssh_port' => $data['ssh_port'] ?? 22,
            'use_ssl' => $request->boolean('use_ssl'),
            'username' => trim($data['username']),
            'password_encrypted' => $data['password'],
            'isolir_address_list' => $data['isolir_address_list'],
            'isolir_profile_name' => $data['isolir_profile_name'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('success', 'MikroTik router added successfully.');
    }

    public function updateRouter(Request $request, MikrotikRouter $router): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'ros_version_type' => ['required', 'in:v6,v7'],
            'host' => ['required', 'string', 'max:120', $this->routerHostValidationRule()],
            'api_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'rest_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:80', $this->routerUsernameValidationRule()],
            'password' => ['nullable', 'string'],
            'isolir_address_list' => ['required', 'string', 'max:80'],
            'isolir_profile_name' => ['nullable', 'string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $updates = [
            'name' => $data['name'],
            'ros_version_type' => $data['ros_version_type'],
            'host' => trim($data['host']),
            'api_port' => $data['api_port'],
            'rest_port' => $data['rest_port'] ?? $router->rest_port,
            'ssh_port' => $data['ssh_port'] ?? $router->ssh_port,
            'use_ssl' => $request->boolean('use_ssl'),
            'username' => trim($data['username']),
            'isolir_address_list' => $data['isolir_address_list'],
            'isolir_profile_name' => $data['isolir_profile_name'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        if (!empty($data['password'])) {
            $updates['password_encrypted'] = $data['password'];
        }

        $router->update($updates);

        return back()->with('success', "Router {$router->name} updated successfully.");
    }

    public function destroyRouter(MikrotikRouter $router): RedirectResponse
    {
        if ($router->subscriptions()->exists()) {
            return back()->with('error', 'Router cannot be deleted because it still has associated subscriptions.');
        }

        foreach ($router->backups()->get(['id', 'file_path']) as $backup) {
            $path = (string) $backup->file_path;
            if (str_starts_with($path, 'mikrotik-backups/') && !str_contains($path, '..') && \Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($path);
            }
        }

        $router->delete();

        return back()->with('success', 'Router deleted successfully.');
    }

    public function testRouterConnection(
        Request $request,
        MikrotikRouter $router,
        MikrotikProvisioningService $service,
        MikrotikReconciliationService $reconciliation
    ): JsonResponse|RedirectResponse {
        try {
            $info = $service->testConnection($router);

            $hasPending = $router->subscriptions()
                ->whereIn('mikrotik_sync_status', ['pending', 'failed'])
                ->whereNotNull('mikrotik_pending_action')
                ->exists();

            $reconciledNote = '';
            if ($hasPending) {
                $summary = $reconciliation->reconcileAll($router->id, 'manual_noc');
                $info['reconciled'] = $summary;
                if ($summary['synced'] > 0) {
                    $reconciledNote = " ({$summary['synced']} pending sync task(s) automatically reconciled!)";
                }
            }

            if ($request->expectsJson()) {
                return response()->json(['ok' => true, 'data' => $info]);
            }

            return back()->with('success', "Connected to router {$info['identity']}! RouterOS {$info['version']} (Uptime: {$info['uptime']}){$reconciledNote}");
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MikroTik router connection test failed', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'error' => $e->getMessage(),
            ]);

            $safeError = 'Unable to connect to the MikroTik router. Verify host, port, credentials, and network reachability.';

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $safeError], 422);
            }

            return back()->with('error', "Failed to connect to {$router->name}: {$safeError}");
        }
    }

    public function setupDefaultFirewall(Request $request, MikrotikRouter $router, MikrotikProvisioningService $service): RedirectResponse
    {
        $data = $request->validate([
            'redirect_portal_ip' => ['nullable', 'ip'],
        ]);

        try {
            $result = $service->setupDefaultFirewall($router, $data['redirect_portal_ip'] ?? null);

            return back()->with('success', "Default firewall setup completed for {$router->name} ({$result['rules_added']} rule(s) added for list {$result['address_list']}).");
        } catch (Throwable $e) {
            return back()->with('error', "Failed to configure firewall on {$router->name}: {$e->getMessage()}");
        }
    }

    // --- CUSTOMERS ---

    public function customers(Request $request): View
    {
        $search = trim($request->string('search')->toString());

        $customers = Customer::query()
            ->with(['user', 'subscriptions.package', 'subscriptions.mikrotikRouter'])
            ->when($search !== '', function ($q) use ($search): void {
                $q->where('customer_code', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhere('phone_whatsapp', 'like', "%{$search}%")
                    ->orWhereHas('subscriptions', fn ($s) => $s
                        ->where('service_username', 'like', "%{$search}%")
                        ->orWhere('static_ip_address', 'like', "%{$search}%"));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.operations.customers', [
            'customers' => $customers,
            'search' => $search,
            'packages' => Package::query()->with('routers:id,name')->where('is_active', true)->orderBy('price')->get(),
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
            'googleMapsApiKey' => CompanySetting::current()->google_maps_api_key_encrypted,
        ]);
    }

    public function quickSearchCustomers(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());
        $canViewCustomerDetail = $request->user()?->hasRole(UserRole::SuperAdmin, UserRole::Noc) ?? false;

        $customers = Customer::query()
            ->with([
                'subscriptions' => fn ($q) => $q->with(['package', 'mikrotikRouter'])->orderByDesc('id'),
                'invoices' => fn ($q) => $q->whereIn('status', ['unpaid', 'overdue'])->latest('id'),
            ])
            ->when($query !== '', function ($q) use ($query): void {
                $q->where(function ($inner) use ($query): void {
                    $inner->where('customer_code', 'like', "%{$query}%")
                        ->orWhere('full_name', 'like', "%{$query}%")
                        ->orWhere('phone_whatsapp', 'like', "%{$query}%")
                        ->orWhereHas('subscriptions', fn ($s) => $s
                            ->where('service_username', 'like', "%{$query}%")
                            ->orWhere('static_ip_address', 'like', "%{$query}%"));
                });
            })
            ->when(
                $query !== '',
                fn ($q) => $q->orderByRaw('CASE WHEN customer_code LIKE ? THEN 0 WHEN full_name LIKE ? THEN 1 ELSE 2 END', ["{$query}%", "{$query}%"]),
                fn ($q) => $q->latest('id')
            )
            ->orderBy('full_name')
            ->limit(10)
            ->get();

        $results = $customers->map(function (Customer $customer) use ($canViewCustomerDetail): array {
            $sub = $customer->subscriptions->first();
            $unpaidInvoice = $customer->invoices->first();

            return [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'full_name' => $customer->full_name,
                'phone_whatsapp' => $customer->phone_whatsapp,
                'address' => Str::limit((string) $customer->installation_address, 65),
                'package_name' => $sub?->package?->name,
                'router_name' => $sub?->mikrotikRouter?->name,
                'pppoe_username' => $sub?->service_username,
                'static_ip' => $sub?->static_ip_address,
                'status' => $sub?->status?->value ?? 'no_subscription',
                'url' => $canViewCustomerDetail
                    ? route('admin.customers.show', $customer)
                    : route('admin.invoices.index', ['search' => $customer->customer_code]),
                'invoice_url' => $unpaidInvoice ? route('admin.invoices.show', $unpaidInvoice) : null,
                'invoice_number' => $unpaidInvoice?->invoice_number,
            ];
        })->values();

        return response()->json([
            'query' => $query,
            'is_recent' => $query === '',
            'can_view_detail' => $canViewCustomerDetail,
            'results' => $results,
        ]);
    }

    public function showCustomer(Customer $customer): View
    {
        $customer->load([
            'user',
            'subscriptions.package',
            'subscriptions.mikrotikRouter',
            'invoices' => fn ($q) => $q->with('payments')->latest('id'),
            'tickets' => fn ($q) => $q->latest('id'),
        ]);

        $usageBySub = PppoeUsageLog::query()
            ->whereIn('subscription_id', $customer->subscriptions->pluck('id'))
            ->selectRaw('subscription_id, SUM(rx_bytes_delta) as total_rx, SUM(tx_bytes_delta) as total_tx')
            ->groupBy('subscription_id')
            ->get()
            ->keyBy('subscription_id');

        return view('admin.operations.customer-show', [
            'customer' => $customer,
            'usageBySub' => $usageBySub,
            'packages' => Package::query()->with('routers:id,name')->where('is_active', true)->orderBy('price')->get(),
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    private function normalizePhoneWhatsappRequest(Request $request): void
    {
        if ($request->has('phone_whatsapp')) {
            $request->merge([
                'phone_whatsapp' => Customer::normalizeWhatsappPhone((string) $request->input('phone_whatsapp')),
            ]);
        }
    }

    public function storeCustomer(Request $request, CustomerOnboardingService $onboarding): RedirectResponse
    {
        $this->normalizePhoneWhatsappRequest($request);

        $data = $request->validate([
            'customer_code' => ['nullable', 'string', 'max:30', 'unique:customers,customer_code'],
            'full_name' => ['required', 'string', 'max:150'],
            'nik_npwp' => ['nullable', 'string', 'max:30'],
            'phone_whatsapp' => ['required', 'string', 'max:25', 'regex:/^[0-9]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'installation_address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'mikrotik_router_id' => ['required_with:package_id', 'nullable', 'exists:mikrotik_routers,id'],
            'service_username' => ['nullable', 'string', 'max:80', 'unique:subscriptions,service_username'],
            'service_password' => ['nullable', 'string'],
            'static_ip_address' => ['nullable', 'ip', 'unique:subscriptions,static_ip_address'],
            'isolir_profile_name' => ['nullable', 'string', 'max:80'],
            'billing_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'isolir_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'payment_method' => ['nullable', 'in:online,cash'],
            'portal_username' => ['nullable', 'string', 'max:80', 'unique:users,username'],
            'portal_password' => ['nullable', 'string', 'min:8'],
        ], [
            'customer_code.unique' => 'Customer ID ":input" is already in use by another customer. Change it or leave blank to auto-generate.',
            'phone_whatsapp.regex' => 'WhatsApp number must contain digits only in 628xxxxxxxxxx format.',
            'static_ip_address.unique' => 'PPPoE IP ":input" is already assigned to another customer. Please choose a different IP.',
            'service_username.unique' => 'PPPoE username ":input" is already registered to another customer.',
            'portal_username.unique' => 'Portal username ":input" is already used by another account.',
        ]);

        Package::assertRouterAllowed(
            !empty($data['package_id']) ? (int) $data['package_id'] : null,
            !empty($data['mikrotik_router_id']) ? (int) $data['mikrotik_router_id'] : null
        );

        $data['auto_isolate'] = $request->boolean('auto_isolate', true);

        try {
            $result = $onboarding->register($data, $request->user(), $request->ip());
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Failed to register customer: ' . $e->getMessage());
        }

        $cust = $result['customer'];
        $msg = "Customer {$cust->full_name} ({$cust->customer_code}) added successfully. Portal username: {$result['portal_username']}.";

        if ($result['subscription'] !== null) {
            $msg .= ($data['payment_method'] ?? 'online') === 'cash'
                ? ' Service activated immediately (Cash).'
                : ' Invoice & Smart Payment Link sent to customer WhatsApp.';
        }

        return redirect()
            ->route('admin.customers.show', $cust)
            ->with('success', $msg)
            ->with('generated_portal_password', $result['portal_password']);
    }

    public function updateCustomer(Request $request, Customer $customer): RedirectResponse
    {
        $this->normalizePhoneWhatsappRequest($request);

        $data = $request->validate([
            'customer_code' => ['required', 'string', 'max:30', Rule::unique('customers', 'customer_code')->ignore($customer->id)],
            'full_name' => ['required', 'string', 'max:150'],
            'nik_npwp' => ['nullable', 'string', 'max:30'],
            'phone_whatsapp' => ['required', 'string', 'max:25', 'regex:/^[0-9]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'installation_address' => ['required', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ], [
            'customer_code.unique' => 'Customer ID ":input" is already in use by another customer.',
            'phone_whatsapp.regex' => 'WhatsApp number must contain digits only in 628xxxxxxxxxx format.',
        ]);

        $customer->update($data);

        return back()->with('success', "Customer profile for {$customer->full_name} updated successfully.");
    }

    public function updateCustomerPortalAccount(Request $request, Customer $customer): RedirectResponse
    {
        $userId = $customer->user_id;

        $data = $request->validate([
            'portal_username' => ['required', 'string', 'max:80', Rule::unique('users', 'username')->ignore($userId)],
            'portal_password' => ['nullable', 'string', 'min:8'],
        ], [
            'portal_username.unique' => 'Portal username ":input" is already used by another account.',
        ]);

        if ($customer->user !== null) {
            abort_unless(
                $customer->user->role === UserRole::Customer,
                403,
                'Cannot modify a non-customer user account from customer portal settings.'
            );

            $credentialsChanged = $customer->user->username !== $data['portal_username'] || !empty($data['portal_password']);
            $updates = ['username' => $data['portal_username'], 'name' => $customer->full_name];
            if (!empty($data['portal_password'])) {
                $updates['password'] = Hash::make($data['portal_password']);
            }
            $customer->user->update($updates);

            if ($credentialsChanged) {
                $customer->user->revokeAllSessions();
            }

            return back()->with('success', "Customer portal account ({$data['portal_username']}) updated successfully.");
        }

        $generatedPassword = !empty($data['portal_password'])
            ? (string) $data['portal_password']
            : Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::query()->create([
            'name' => $customer->full_name,
            'email' => $customer->email && !User::query()->where('email', $customer->email)->exists() ? $customer->email : null,
            'username' => $data['portal_username'],
            'password' => Hash::make($generatedPassword),
            'role' => UserRole::Customer,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $customer->forceFill(['user_id' => $user->id])->save();

        return back()
            ->with('success', "Customer portal account ({$data['portal_username']}) created successfully.")
            ->with('generated_portal_password', empty($data['portal_password']) ? $generatedPassword : null);
    }

    public function destroyCustomer(Customer $customer): RedirectResponse
    {
        if ($customer->invoices()->exists()) {
            return back()->with('error', 'Customer has invoice history and cannot be deleted.');
        }

        $customer->delete();

        return back()->with('success', 'Customer deleted successfully.');
    }

    public function exportCustomers(CustomerSpreadsheetService $spreadsheet): Response
    {
        $binary = $spreadsheet->exportXlsxBinary();

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="customers-export-' . now('Asia/Jakarta')->format('Ymd-His') . '.xlsx"',
        ]);
    }

    public function downloadCustomerTemplate(CustomerSpreadsheetService $spreadsheet): Response
    {
        $binary = $spreadsheet->templateXlsxBinary();

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="customer-import-template.xlsx"',
        ]);
    }

    public function importCustomers(Request $request, CustomerSpreadsheetService $spreadsheet): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv', 'max:10240'],
        ]);

        try {
            $result = $spreadsheet->importFile($request->file('file'));
            $msg = "Import completed: {$result['imported']} created, {$result['updated']} updated.";

            if ($result['errors'] !== []) {
                $firstErrors = implode(' | ', array_slice($result['errors'], 0, 3));

                return back()->with('error', "{$msg} Some rows were skipped: {$firstErrors}");
            }

            return back()->with('success', $msg);
        } catch (Throwable $e) {
            return back()->with('error', 'Failed to import Excel/CSV file: ' . $e->getMessage());
        }
    }

    // --- SUBSCRIPTIONS & PROVISIONING ---

    public function subscriptions(Request $request): View
    {
        $status = $request->string('status')->toString();

        return view('admin.operations.subscriptions', [
            'subscriptions' => Subscription::query()
                ->with(['customer', 'package', 'mikrotikRouter'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'status' => $status,
            'customers' => Customer::query()->orderBy('full_name')->get(),
            'packages' => Package::query()->with('routers:id,name')->where('is_active', true)->get(),
            'routers' => MikrotikRouter::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function storeSubscription(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'mikrotik_router_id' => ['required', 'exists:mikrotik_routers,id'],
            'service_username' => ['required', 'string', 'max:80', 'unique:subscriptions,service_username'],
            'service_password' => ['required', 'string'],
            'static_ip_address' => ['nullable', 'ip', 'unique:subscriptions,static_ip_address'],
            'isolir_profile_name' => ['nullable', 'string', 'max:80'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'isolir_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'next_billing_date' => ['required', 'date'],
        ], [
            'static_ip_address.unique' => 'PPPoE IP ":input" is already assigned to another customer.',
        ]);

        Package::assertRouterAllowed((int) $data['package_id'], (int) $data['mikrotik_router_id']);

        Subscription::query()->create([
            'customer_id' => $data['customer_id'],
            'package_id' => $data['package_id'],
            'mikrotik_router_id' => $data['mikrotik_router_id'],
            'service_username' => $data['service_username'],
            'service_password_encrypted' => $data['service_password'],
            'static_ip_address' => $data['static_ip_address'] ?? null,
            'isolir_profile_name' => $data['isolir_profile_name'] ?? null,
            'billing_day' => $data['billing_day'],
            'isolir_day' => $data['isolir_day'] ?? null,
            'auto_isolate' => $request->boolean('auto_isolate', true),
            'next_billing_date' => $data['next_billing_date'],
            'status' => SubscriptionStatus::PendingInstallation,
        ]);

        return back()->with('success', 'Subscription added successfully.');
    }

    public function updateSubscription(Request $request, Subscription $subscription): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'mikrotik_router_id' => ['required', 'exists:mikrotik_routers,id'],
            'service_username' => ['required', 'string', 'max:80', Rule::unique('subscriptions', 'service_username')->ignore($subscription->id)],
            'service_password' => ['nullable', 'string'],
            'static_ip_address' => ['nullable', 'ip', Rule::unique('subscriptions', 'static_ip_address')->ignore($subscription->id)],
            'isolir_profile_name' => ['nullable', 'string', 'max:80'],
            'billing_day' => ['required', 'integer', 'min:1', 'max:28'],
            'isolir_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'next_billing_date' => ['required', 'date'],
        ], [
            'static_ip_address.unique' => 'PPPoE IP ":input" is already assigned to another customer.',
        ]);

        Package::assertRouterAllowed((int) $data['package_id'], (int) $data['mikrotik_router_id']);

        $updates = [
            'package_id' => $data['package_id'],
            'mikrotik_router_id' => $data['mikrotik_router_id'],
            'service_username' => $data['service_username'],
            'static_ip_address' => $data['static_ip_address'] ?? null,
            'isolir_profile_name' => $data['isolir_profile_name'] ?? null,
            'billing_day' => $data['billing_day'],
            'isolir_day' => $data['isolir_day'] ?? null,
            'auto_isolate' => $request->boolean('auto_isolate'),
            'next_billing_date' => $data['next_billing_date'],
        ];

        if (!empty($data['service_password'])) {
            $updates['service_password_encrypted'] = $data['service_password'];
        }

        $provisioningChanged = (int) $subscription->package_id !== (int) $data['package_id']
            || (int) $subscription->mikrotik_router_id !== (int) $data['mikrotik_router_id']
            || $subscription->service_username !== $data['service_username']
            || !empty($data['service_password'])
            || ($subscription->static_ip_address ?? null) !== ($data['static_ip_address'] ?? null)
            || ($subscription->isolir_profile_name ?? null) !== ($data['isolir_profile_name'] ?? null);

        $subscription->update($updates);

        if ($provisioningChanged && $subscription->status !== SubscriptionStatus::PendingInstallation) {
            $subscription->forceFill([
                'mikrotik_sync_status' => 'pending',
                'mikrotik_pending_action' => $subscription->status === SubscriptionStatus::Isolated ? 'isolate' : 'sync_secret',
                'mikrotik_last_sync_error' => null,
            ])->save();
        }

        return back()->with('success', "Subscription {$subscription->service_username} updated successfully.");
    }

    public function destroySubscription(Subscription $subscription): RedirectResponse
    {
        if ($subscription->invoices()->exists()) {
            return back()->with('error', 'Subscription has invoice history and cannot be deleted.');
        }

        $subscription->delete();

        return back()->with('success', 'Subscription deleted successfully.');
    }

    public function provisionSubscription(
        Request $request,
        Subscription $subscription,
        InvoiceService $invoiceService,
        MikrotikProvisioningService $provisioning,
        MikrotikReconciliationService $reconciliation
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'action' => ['required', 'in:activate,isolate,restore,sync_secret'],
        ]);

        $action = $validated['action'];

        if ($action === 'activate') {
            if ($subscription->status !== SubscriptionStatus::PendingInstallation) {
                $message = 'Only subscriptions with pending_installation status can be activated.';

                return $request->expectsJson()
                    ? response()->json(['queued' => false, 'message' => $message], 422)
                    : back()->with('error', $message);
            }

            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'activated_at' => now(),
            ])->save();

            $provisioning->queueAction($subscription, 'sync_secret', 'manual_noc', afterCommit: false);
            $invoiceService->issueForSubscription($subscription);
        } elseif ($action === 'isolate') {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Isolated,
                'isolated_at' => now(),
            ])->save();

            $provisioning->queueAction($subscription, 'isolate', 'manual_noc', afterCommit: false);
            SendWhatsAppNotificationJob::dispatch($subscription->customer, 'service_isolated', null);
        } elseif ($action === 'restore') {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'isolated_at' => null,
            ])->save();

            $provisioning->queueAction($subscription, 'restore', 'manual_noc', notifyRestored: true, afterCommit: false);
        } else {
            // Manual sync_secret: run 1-way authoritative sync (App DB -> MikroTik) immediately for instant feedback
            $subscription->forceFill(['mikrotik_pending_action' => 'sync_secret'])->save();
            $result = $reconciliation->reconcileSubscription($subscription, 'manual_noc');

            if ($request->expectsJson()) {
                return response()->json([
                    'queued' => false,
                    'synced' => $result['ok'],
                    'action' => $result['action'],
                    'error' => $result['error'],
                ], $result['ok'] ? 200 : 422);
            }

            if ($result['ok']) {
                return back()->with('success', "Secret {$subscription->service_username} and package profile synced to MikroTik successfully.");
            }

            $routerName = $subscription->mikrotikRouter?->name ?? 'MikroTik';
            $prefix = ($result['is_connection_error'] ?? true)
                ? "Router {$routerName} unreachable during sync_secret"
                : "Failed sync_secret {$subscription->service_username} to {$routerName}";

            return back()->with('error', "{$prefix}: {$result['error']}");
        }

        if ($request->expectsJson()) {
            return response()->json(['queued' => true, 'action' => $action]);
        }

        return back()->with('success', "Action '{$action}' processed successfully.");
    }

    public function reconcileAllPending(MikrotikReconciliationService $reconciliation): RedirectResponse
    {
        $summary = $reconciliation->reconcileAll(null, 'manual_noc');

        if ($summary['routers_checked'] === 0) {
            return back()->with('success', 'No pending MikroTik synchronization tasks found.');
        }

        $msg = "Reconciliation completed ({$summary['routers_checked']} router(s) checked): {$summary['synced']} synced";
        if ($summary['routers_offline'] > 0 || $summary['failed'] > 0) {
            $msg .= ", {$summary['routers_offline']} router(s) still offline, {$summary['failed']} failed.";

            return back()->with('error', $msg);
        }

        return back()->with('success', $msg . '.');
    }

    public function reconcileRouterPending(MikrotikRouter $router, MikrotikReconciliationService $reconciliation): RedirectResponse
    {
        $summary = $reconciliation->reconcileAll($router->id, 'manual_noc');

        if ($summary['routers_offline'] > 0) {
            return back()->with('error', "Router {$router->name} is still offline or unresponsive.");
        }

        return back()->with('success', "Router {$router->name} reconciliation: {$summary['synced']} synced, {$summary['failed']} failed.");
    }

    public function syncRouterFromDatabase(MikrotikRouter $router, MikrotikProvisioningService $provisioning): RedirectResponse
    {
        try {
            $summary = $provisioning->syncRouterFromDatabase($router, 'manual_sync');
        } catch (Throwable $e) {
            return back()->with('error', "Failed 1-way sync to {$router->name}: {$e->getMessage()}");
        }

        $msg = "Sync DB -> {$router->name} completed: {$summary['profiles_synced']} profile(s) ({$summary['profiles_created']} created, {$summary['profiles_updated']} updated), {$summary['subscriptions_synced']}/{$summary['subscriptions_total']} subscription(s) synced.";

        if ($summary['subscriptions_failed'] > 0) {
            $firstErr = $summary['errors'][0] ?? '';

            return back()->with('error', "{$msg} {$summary['subscriptions_failed']} failed" . ($firstErr !== '' ? " ({$firstErr})" : '.'));
        }

        return back()->with('success', $msg);
    }

    public function reconcileSubscriptionNow(Subscription $subscription, MikrotikReconciliationService $reconciliation): RedirectResponse
    {
        $result = $reconciliation->reconcileSubscription($subscription, 'manual_noc');

        if ($result['ok']) {
            return back()->with('success', "Sync '{$result['action']}' for {$subscription->service_username} to MikroTik succeeded.");
        }

        $routerName = $subscription->mikrotikRouter?->name ?? 'Router';
        $prefix = ($result['is_connection_error'] ?? true)
            ? "Router {$routerName} unreachable during {$result['action']} for {$subscription->service_username}"
            : "Failed to sync {$result['action']} {$subscription->service_username} to {$routerName}";

        return back()->with('error', "{$prefix}: {$result['error']}");
    }

    public function receiveCash(
        Request $request,
        Subscription $subscription,
        ManualCashExtensionService $cashService
    ): RedirectResponse {
        $result = $cashService->receiveCash($subscription, null, $request->user(), $request->ip());

        return back()->with(
            'success',
            "Cash payment recorded ({$result['payment']->payment_code}). Active period extended until {$subscription->fresh()->next_billing_date->format('d M Y')}."
        );
    }
}
