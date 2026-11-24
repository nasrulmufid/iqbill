<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\MikrotikToolsController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\TicketController as AdminTicketController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Portal\PortalAccountController;
use App\Http\Controllers\Portal\PortalDashboardController;
use App\Http\Controllers\Portal\PortalInvoiceApiController;
use App\Http\Controllers\Portal\PortalInvoiceController;
use App\Http\Controllers\Portal\TicketController as PortalTicketController;
use App\Http\Controllers\Public\SmartPaymentRedirectController;
use App\Livewire\Admin\PppoeManagerComponent;
use App\Livewire\Admin\SystemMonitoringComponent;
use App\Livewire\Admin\WhatsappBroadcastComponent;
use App\Livewire\Admin\WhatsappDeviceComponent;
use App\Livewire\Portal\InvoicePaymentComponent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return Auth::user()->role->isStaff()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('portal.dashboard');
    }

    return redirect()->route('login');
});

Route::get('/bayar/{public_token}', SmartPaymentRedirectController::class)
    ->middleware('throttle:20,1')
    ->name('public.pay');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'authenticate'])->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'active'])->prefix('portal')->as('portal.')->group(function () {
    Route::get('/dashboard', PortalDashboardController::class)->name('dashboard');
    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}/download', [PortalInvoiceController::class, 'download'])->name('invoices.download');
    Route::get('/invoices/{invoice}/print', [PortalInvoiceController::class, 'print'])->name('invoices.print');
    Route::get('/invoices/{invoice}', InvoicePaymentComponent::class)->name('invoices.show');
    Route::post('/invoices/{invoice}/session', [PortalInvoiceApiController::class, 'session'])
        ->middleware('throttle:10,1')
        ->name('invoices.session');
    Route::get('/invoices/{invoice}/status', [PortalInvoiceApiController::class, 'status'])->name('invoices.status');

    Route::get('/tickets', [PortalTicketController::class, 'index'])->name('tickets.index');
    Route::post('/tickets', [PortalTicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [PortalTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/reply', [PortalTicketController::class, 'reply'])->name('tickets.reply');

    Route::get('/account', [PortalAccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/profile', [PortalAccountController::class, 'updateProfile'])->name('account.profile');
    Route::put('/account/password', [PortalAccountController::class, 'updatePassword'])
        ->middleware('throttle:10,1')
        ->name('account.password');
});

Route::middleware(['auth', 'active', 'role:super_admin,finance,noc'])->prefix('admin')->as('admin.')->group(function () {
    Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
    Route::get('/invoices', [AdminInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [AdminInvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/webhooks', [WebhookLogController::class, 'index'])->name('webhooks.index');
    Route::get('/monitoring', SystemMonitoringComponent::class)->name('monitoring.index');
    Route::get('/whatsapp/broadcast', WhatsappBroadcastComponent::class)->name('whatsapp.broadcast');
    Route::get('/customers/quick-search', [OperationsController::class, 'quickSearchCustomers'])->name('customers.quick-search');

    // Support Tickets (Admin / Technician)
    Route::get('/tickets', [AdminTicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [AdminTicketController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/claim', [AdminTicketController::class, 'claim'])->name('tickets.claim');
    Route::post('/tickets/{ticket}/reply', [AdminTicketController::class, 'reply'])->name('tickets.reply');
    Route::patch('/tickets/{ticket}/status', [AdminTicketController::class, 'updateStatus'])->name('tickets.status');

    Route::middleware('role:super_admin,finance')->group(function () {
        Route::post('/invoices/{invoice}/manual-payment', [AdminInvoiceController::class, 'manualPayment'])
            ->name('invoices.manual-payment');
        Route::post('/subscriptions/{subscription}/receive-cash', [OperationsController::class, 'receiveCash'])
            ->name('subscriptions.receive-cash');
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FinanceController::class, 'exportXlsx'])
            ->middleware('throttle:10,1')
            ->name('finance.export');
    });

    Route::middleware('role:super_admin,noc')->group(function () {
        // Service Plans / Packages
        Route::get('/packages', [OperationsController::class, 'packages'])->name('packages.index');
        Route::post('/packages', [OperationsController::class, 'storePackage'])->name('packages.store');
        Route::put('/packages/{package}', [OperationsController::class, 'updatePackage'])->name('packages.update');
        Route::delete('/packages/{package}', [OperationsController::class, 'destroyPackage'])->name('packages.destroy');

        // Router MikroTik
        Route::get('/routers', [OperationsController::class, 'routers'])->name('routers.index');
        Route::post('/routers', [OperationsController::class, 'storeRouter'])->name('routers.store');
        Route::put('/routers/{router}', [OperationsController::class, 'updateRouter'])->name('routers.update');
        Route::delete('/routers/{router}', [OperationsController::class, 'destroyRouter'])->name('routers.destroy');
        Route::post('/mikrotik/{router}/test-connection', [OperationsController::class, 'testRouterConnection'])
            ->name('mikrotik.test-connection');
        Route::post('/mikrotik/{router}/sync-from-db', [OperationsController::class, 'syncRouterFromDatabase'])
            ->name('mikrotik.sync-from-db');
        Route::post('/mikrotik/{router}/setup-firewall', [OperationsController::class, 'setupDefaultFirewall'])
            ->name('mikrotik.setup-firewall');
        Route::post('/mikrotik/reconcile-pending', [OperationsController::class, 'reconcileAllPending'])
            ->name('mikrotik.reconcile');
        Route::post('/mikrotik/{router}/reconcile', [OperationsController::class, 'reconcileRouterPending'])
            ->name('mikrotik.router-reconcile');

        // Customers + Excel Import/Export + Customer Detail
        Route::get('/customers', [OperationsController::class, 'customers'])->name('customers.index');
        Route::post('/customers', [OperationsController::class, 'storeCustomer'])->name('customers.store');
        Route::get('/customers/export', [OperationsController::class, 'exportCustomers'])
            ->middleware('throttle:10,1')
            ->name('customers.export');
        Route::get('/customers/import-template', [OperationsController::class, 'downloadCustomerTemplate'])->name('customers.template');
        Route::post('/customers/import', [OperationsController::class, 'importCustomers'])
            ->middleware('throttle:10,1')
            ->name('customers.import');
        Route::get('/customers/{customer}', [OperationsController::class, 'showCustomer'])->name('customers.show');
        Route::put('/customers/{customer}', [OperationsController::class, 'updateCustomer'])->name('customers.update');
        Route::post('/customers/{customer}/portal-account', [OperationsController::class, 'updateCustomerPortalAccount'])->name('customers.portal-account');
        Route::delete('/customers/{customer}', [OperationsController::class, 'destroyCustomer'])->name('customers.destroy');

        // Subscriptions & Provisioning
        Route::get('/subscriptions', [OperationsController::class, 'subscriptions'])->name('subscriptions.index');
        Route::post('/subscriptions', [OperationsController::class, 'storeSubscription'])->name('subscriptions.store');
        Route::put('/subscriptions/{subscription}', [OperationsController::class, 'updateSubscription'])->name('subscriptions.update');
        Route::delete('/subscriptions/{subscription}', [OperationsController::class, 'destroySubscription'])->name('subscriptions.destroy');
        Route::post('/subscriptions/{subscription}/provision', [OperationsController::class, 'provisionSubscription'])
            ->name('subscriptions.provision');
        Route::post('/subscriptions/{subscription}/reconcile', [OperationsController::class, 'reconcileSubscriptionNow'])
            ->name('subscriptions.reconcile');

        // MikroTik PPPoE, Usage, Backup
        Route::get('/mikrotik/pppoe', PppoeManagerComponent::class)->name('mikrotik.pppoe');
        Route::get('/mikrotik/usage', [MikrotikToolsController::class, 'pppoeUsage'])->name('mikrotik.usage');
        Route::post('/mikrotik/usage/sync', [MikrotikToolsController::class, 'syncUsageNow'])->name('mikrotik.usage.sync');
        Route::get('/mikrotik/backups', [MikrotikToolsController::class, 'backups'])->name('mikrotik.backups');
        Route::post('/mikrotik/backups', [MikrotikToolsController::class, 'createBackup'])->name('mikrotik.backups.store');
        Route::get('/mikrotik/backups/{backup}/download', [MikrotikToolsController::class, 'downloadBackup'])->name('mikrotik.backups.download');
        Route::delete('/mikrotik/backups/{backup}', [MikrotikToolsController::class, 'destroyBackup'])->name('mikrotik.backups.destroy');
    });

    Route::middleware('role:super_admin')->group(function () {
        Route::get('/settings/payment', [PaymentSettingsController::class, 'edit'])->name('settings.payment.edit');
        Route::put('/settings/payment', [PaymentSettingsController::class, 'update'])->name('settings.payment.update');
        Route::post('/settings/sumopod/test-connection', [PaymentSettingsController::class, 'testConnection'])
            ->name('settings.sumopod.test');
        Route::post('/settings/telegram/test', [PaymentSettingsController::class, 'testTelegram'])
            ->name('settings.telegram.test');

        // Go-WhatsApp Device & Templates
        Route::get('/whatsapp/device', WhatsappDeviceComponent::class)->name('whatsapp.device');
        Route::get('/whatsapp/templates', [PaymentSettingsController::class, 'whatsappTemplates'])->name('whatsapp.templates');
        Route::put('/whatsapp/templates/{template}', [PaymentSettingsController::class, 'updateWhatsappTemplate'])
            ->name('whatsapp.templates.update');

        // Application User Management
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
