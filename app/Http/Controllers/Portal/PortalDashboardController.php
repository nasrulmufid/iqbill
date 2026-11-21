<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\PppoeUsageLog;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $customer = $request->user()?->customer;
        $company = CompanySetting::current();

        $subscriptions = $customer
            ? $customer->subscriptions()->with(['package', 'mikrotikRouter'])->orderBy('id')->get()
            : collect();

        $subIds = $subscriptions->pluck('id');

        $monthStartDate = now('Asia/Jakarta')->startOfMonth()->toDateString();
        $usageBySub = $subIds->isNotEmpty()
            ? PppoeUsageLog::query()
                ->whereIn('subscription_id', $subIds)
                ->where('recorded_date', '>=', $monthStartDate)
                ->selectRaw('subscription_id, SUM(rx_bytes_delta) as month_rx, SUM(tx_bytes_delta) as month_tx, MAX(uptime_seconds) as max_uptime, MAX(recorded_at) as last_recorded_at')
                ->groupBy('subscription_id')
                ->get()
                ->keyBy('subscription_id')
            : collect();

        $totalMonthBytes = (int) $usageBySub->sum(fn ($row) => (int) $row->month_rx + (int) $row->month_tx);

        $invoices = $customer
            ? Invoice::query()
                ->with(['subscription.package', 'payments'])
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(6)
                ->get()
            : collect();

        $unpaidQuery = $customer
            ? Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
            : null;

        $unpaidCount = $unpaidQuery ? (clone $unpaidQuery)->count() : 0;
        $unpaidTotal = $unpaidQuery ? (float) (clone $unpaidQuery)->sum('total_amount') : 0.0;
        $paidCount = $customer
            ? Invoice::query()->where('customer_id', $customer->id)->where('status', InvoiceStatus::Paid->value)->count()
            : 0;

        $activeInvoice = $customer
            ? Invoice::query()
                ->with(['subscription.package'])
                ->where('customer_id', $customer->id)
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
                ->orderByRaw("CASE WHEN status = 'overdue' THEN 0 ELSE 1 END")
                ->orderBy('due_date')
                ->first()
            : null;

        $recentTickets = $customer
            ? Ticket::query()
                ->where('customer_id', $customer->id)
                ->latest('id')
                ->limit(3)
                ->get()
            : collect();

        $openTicketCount = $customer
            ? Ticket::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['open', 'in_progress', 'waiting_customer'])
                ->count()
            : 0;

        return view('portal.dashboard', [
            'customer' => $customer,
            'company' => $company,
            'subscriptions' => $subscriptions,
            'usageBySub' => $usageBySub,
            'totalMonthBytes' => $totalMonthBytes,
            'invoices' => $invoices,
            'activeInvoice' => $activeInvoice,
            'unpaidCount' => $unpaidCount,
            'unpaidTotal' => $unpaidTotal,
            'paidCount' => $paidCount,
            'recentTickets' => $recentTickets,
            'openTicketCount' => $openTicketCount,
        ]);
    }
}
