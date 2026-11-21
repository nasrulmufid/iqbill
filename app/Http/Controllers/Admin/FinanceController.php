<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Shuchkin\SimpleXLSXGen;

class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $baseQuery = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->when($from !== '', fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('paid_at', '<=', $to));

        $totals = (clone $baseQuery)
            ->selectRaw('COALESCE(SUM(amount), 0) as gross_total, COALESCE(SUM(gateway_fee), 0) as fee_total, COALESCE(SUM(net_amount), 0) as net_total, COUNT(*) as tx_count')
            ->first();

        $byGateway = (clone $baseQuery)
            ->selectRaw('gateway, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as gross, COALESCE(SUM(gateway_fee), 0) as fee, COALESCE(SUM(net_amount), 0) as net')
            ->groupBy('gateway')
            ->get();

        $payments = (clone $baseQuery)
            ->with(['invoice.customer', 'recordedBy'])
            ->latest('paid_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.finance.index', [
            'totals' => $totals,
            'byGateway' => $byGateway,
            'payments' => $payments,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function exportXlsx(Request $request): Response
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $payments = Payment::query()
            ->with(['invoice.customer'])
            ->where('status', PaymentStatus::Paid->value)
            ->when($from !== '', fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->latest('paid_at')
            ->get();

        $rows = [['Paid Date', 'Payment Code', 'Invoice', 'Customer', 'Channel', 'Gross', 'Gateway Fee', 'Net']];
        foreach ($payments as $p) {
            $rows[] = [
                $p->paid_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i') ?? '-',
                $p->payment_code,
                $p->invoice->invoice_number ?? '-',
                $p->invoice->customer->full_name ?? '-',
                $p->gateway,
                (float) $p->amount,
                (float) $p->gateway_fee,
                (float) $p->net_amount,
            ];
        }

        return response(SimpleXLSXGen::fromArray($rows)->__toString(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="finance-report-' . now('Asia/Jakarta')->format('Ymd') . '.xlsx"',
        ]);
    }
}
