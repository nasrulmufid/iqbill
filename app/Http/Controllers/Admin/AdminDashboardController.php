<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MikrotikRouter;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Monitoring\SystemHealthService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(SystemHealthService $healthService): View
    {
        $paidStats = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(amount), 0) as gross, COALESCE(SUM(gateway_fee), 0) as fee, COALESCE(SUM(net_amount), 0) as net, COUNT(*) as count')
            ->first();

        $invoiceByStatus = Invoice::query()
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof InvoiceStatus ? $row->status->value : (string) $row->status);

        $invoiceStats = [
            'unpaid_count' => (int) ($invoiceByStatus[InvoiceStatus::Unpaid->value]->count ?? 0),
            'unpaid_total' => (float) ($invoiceByStatus[InvoiceStatus::Unpaid->value]->total ?? 0),
            'overdue_count' => (int) ($invoiceByStatus[InvoiceStatus::Overdue->value]->count ?? 0),
            'overdue_total' => (float) ($invoiceByStatus[InvoiceStatus::Overdue->value]->total ?? 0),
            'paid_count' => (int) ($invoiceByStatus[InvoiceStatus::Paid->value]->count ?? 0),
            'paid_total' => (float) ($invoiceByStatus[InvoiceStatus::Paid->value]->total ?? 0),
        ];

        $subByStatus = Subscription::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof SubscriptionStatus ? $row->status->value : (string) $row->status);

        $subscriptionStats = [
            'active' => (int) ($subByStatus[SubscriptionStatus::Active->value]->count ?? 0),
            'isolated' => (int) ($subByStatus[SubscriptionStatus::Isolated->value]->count ?? 0),
            'pending_installation' => (int) ($subByStatus[SubscriptionStatus::PendingInstallation->value]->count ?? 0),
        ];

        $routerCount = MikrotikRouter::query()->where('is_active', true)->count();
        $offlineRoutersCount = MikrotikRouter::query()->where('is_active', true)->where('is_reachable', false)->count();
        $pendingSyncCount = Subscription::query()
            ->whereIn('mikrotik_sync_status', ['pending', 'failed'])
            ->whereNotNull('mikrotik_pending_action')
            ->count();

        $recentInvoices = Invoice::query()
            ->with(['customer', 'subscription.package'])
            ->latest('id')
            ->limit(6)
            ->get();

        $monthNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];

        $now = now('Asia/Jakarta');
        $currentMonthKey = $now->format('Y-m');
        $startMonth = $now->copy()->startOfMonth()->subMonths(11);
        $endMonth = $now->copy()->endOfMonth();

        $buckets = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $startMonth->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $buckets[$key] = [
                'key' => $key,
                'short_label' => $monthNames[$month->month],
                'year_short' => "'" . $month->format('y'),
                'full_label' => $monthNames[$month->month] . ' ' . $month->year,
                'show_year' => $i === 0 || $month->month === 1,
                'net' => 0.0,
                'gross' => 0.0,
                'fee' => 0.0,
                'count' => 0,
                'is_current' => $key === $currentMonthKey,
            ];
        }

        $queryStart = $startMonth->copy()->subDay();
        $queryEnd = $endMonth->copy()->addDay();

        $paidWindowPayments = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->where(function ($q) use ($queryStart, $queryEnd): void {
                $q->whereBetween('paid_at', [$queryStart, $queryEnd])
                    ->orWhere(function ($q2) use ($queryStart, $queryEnd): void {
                        $q2->whereNull('paid_at')
                            ->whereBetween('created_at', [$queryStart, $queryEnd]);
                    });
            })
            ->select(['amount', 'gateway_fee', 'net_amount', 'paid_at', 'created_at'])
            ->get();

        foreach ($paidWindowPayments as $payment) {
            $effectiveDate = ($payment->paid_at ?? $payment->created_at)?->timezone('Asia/Jakarta');
            if ($effectiveDate === null) {
                continue;
            }
            $key = $effectiveDate->format('Y-m');
            if (!isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['gross'] += (float) $payment->amount;
            $buckets[$key]['fee'] += (float) $payment->gateway_fee;
            $buckets[$key]['net'] += (float) $payment->net_amount;
            $buckets[$key]['count']++;
        }

        $monthlyRevenue = array_values(array_map(static function (array $row): array {
            $row['gross'] = round($row['gross'], 2);
            $row['fee'] = round($row['fee'], 2);
            $row['net'] = round($row['net'], 2);

            return $row;
        }, $buckets));

        return view('admin.dashboard', [
            'paidStats' => $paidStats,
            'invoiceStats' => $invoiceStats,
            'subscriptionStats' => $subscriptionStats,
            'routerCount' => $routerCount,
            'offlineRoutersCount' => $offlineRoutersCount,
            'pendingSyncCount' => $pendingSyncCount,
            'automation' => $healthService->getAutomationStatus(),
            'recentInvoices' => $recentInvoices,
            'monthlyRevenue' => $monthlyRevenue,
        ]);
    }
}
