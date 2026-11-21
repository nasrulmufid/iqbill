<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\CompanySetting;
use App\Models\Invoice;

/**
 * Standard A4 PDF Invoice generator (PDF 1.4, built-in Type1 Helvetica/Courier)
 * with zero external PHP extension dependencies.
 */
class InvoicePdfService
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer', 'subscription.package', 'subscription.mikrotikRouter', 'payments']);
        $company = CompanySetting::current();

        $customer = $invoice->customer;
        $sub = $invoice->subscription;
        $package = $sub?->package;
        $paidPayment = $invoice->payments->firstWhere('status.value', 'paid')
            ?? $invoice->payments->sortByDesc('id')->first();

        $isPaid = $invoice->status === InvoiceStatus::Paid;
        $isOverdue = $invoice->status === InvoiceStatus::Overdue;
        $statusLabel = match ($invoice->status) {
            InvoiceStatus::Paid => 'PAID',
            InvoiceStatus::Overdue => 'OVERDUE',
            InvoiceStatus::Cancelled => 'CANCELLED',
            default => 'UNPAID',
        };

        $s = [];

        // Header brand bar (indigo dark navy)
        $s[] = '0.118 0.161 0.231 rg 40 725 515 78 re f';
        $s[] = $this->text(58, 774, $company->company_name ?: 'IQ Bill', 'F2', 17, [1, 1, 1]);
        $companySub = trim(implode(' | ', array_filter([
            $company->company_phone ?: null,
            $company->company_address ?: null,
        ])));
        if ($companySub !== '') {
            $s[] = $this->text(58, 754, $this->limitText($companySub, 78), 'F1', 8.5, [0.78, 0.82, 0.90]);
        }

        // Right side of header: INVOICE + status badge
        $s[] = $this->text(425, 774, 'INVOICE', 'F2', 18, [1, 1, 1]);
        $s[] = $this->text(425, 756, $invoice->invoice_number, 'F3', 9.5, [0.85, 0.89, 0.98]);

        if ($isPaid) {
            $s[] = '0.063 0.725 0.506 rg 425 733 118 16 re f';
            $s[] = $this->text(448, 738, 'STATUS: PAID', 'F2', 8.5, [1, 1, 1]);
        } elseif ($isOverdue) {
            $s[] = '0.882 0.114 0.282 rg 425 733 118 16 re f';
            $s[] = $this->text(456, 738, 'OVERDUE', 'F2', 8.5, [1, 1, 1]);
        } else {
            $s[] = '0.961 0.620 0.043 rg 425 733 118 16 re f';
            $s[] = $this->text(458, 738, $statusLabel, 'F2', 8.5, [0.1, 0.1, 0.1]);
        }

        // Info Cards: Left (Bill To) & Right (Invoice Details)
        $s[] = '0.973 0.980 0.988 rg 40 595 250 112 re f';
        $s[] = '0.886 0.910 0.941 RG 0.8 w 40 595 250 112 re S';
        $s[] = $this->text(54, 690, 'BILL TO', 'F2', 8, [0.39, 0.45, 0.55]);
        $s[] = $this->text(54, 672, $this->limitText((string) ($customer?->full_name ?? '-'), 36), 'F2', 11, [0.06, 0.09, 0.16]);
        $s[] = $this->text(54, 656, 'Customer ID  :', 'F1', 8.5, [0.39, 0.45, 0.55]);
        $s[] = $this->text(118, 656, (string) ($customer?->customer_code ?? '-'), 'F3', 8.5, [0.15, 0.20, 0.28]);
        $s[] = $this->text(54, 642, 'WhatsApp     :', 'F1', 8.5, [0.39, 0.45, 0.55]);
        $s[] = $this->text(118, 642, (string) ($customer?->phone_whatsapp ?? '-'), 'F3', 8.5, [0.15, 0.20, 0.28]);
        $s[] = $this->text(54, 628, 'Address      :', 'F1', 8.5, [0.39, 0.45, 0.55]);
        $addrLines = $this->wrapText((string) ($customer?->installation_address ?? '-'), 33, 3);
        foreach ($addrLines as $idx => $line) {
            $s[] = $this->text(118, 628 - ($idx * 11), $line, 'F1', 8.5, [0.28, 0.33, 0.41]);
        }

        $s[] = '0.973 0.980 0.988 rg 305 595 250 112 re f';
        $s[] = '0.886 0.910 0.941 RG 0.8 w 305 595 250 112 re S';
        $s[] = $this->text(319, 690, 'INVOICE & SERVICE DETAILS', 'F2', 8, [0.39, 0.45, 0.55]);
        $s[] = $this->text(319, 672, 'Issue Date     : ' . ($invoice->created_at?->timezone('Asia/Jakarta')->format('d M Y') ?? now()->format('d M Y')), 'F1', 9, [0.15, 0.20, 0.28]);
        $s[] = $this->text(319, 656, 'Due Date       : ' . $invoice->due_date->format('d M Y'), 'F1', 9, [0.15, 0.20, 0.28]);
        $s[] = $this->text(319, 640, 'Billing Period : ' . $invoice->billing_period_start->format('d M Y') . ' - ' . $invoice->billing_period_end->format('d M Y'), 'F1', 9, [0.15, 0.20, 0.28]);
        $s[] = $this->text(319, 624, 'PPPoE Account  : ' . ($sub?->service_username ?? '-'), 'F3', 8.5, [0.15, 0.20, 0.28]);
        $s[] = $this->text(319, 608, 'Service Plan   : ' . $this->limitText((string) ($package?->name ?? '-'), 30), 'F1', 9, [0.15, 0.20, 0.28]);

        // Table Header
        $s[] = '0.945 0.961 0.976 rg 40 545 515 26 re f';
        $s[] = '0.800 0.835 0.882 RG 0.8 w 40 545 515 26 re S';
        $s[] = $this->text(52, 554, 'ITEM DESCRIPTION', 'F2', 8.5, [0.28, 0.33, 0.41]);
        $s[] = $this->text(355, 554, 'DETAILS', 'F2', 8.5, [0.28, 0.33, 0.41]);
        $s[] = $this->text(485, 554, 'AMOUNT', 'F2', 8.5, [0.28, 0.33, 0.41]);

        $items = [];
        $pkgLabel = ($package?->name ?? 'Internet Subscription')
            . ($package ? " ({$package->speed_download_mbps}M/{$package->speed_upload_mbps}M)" : '');
        $items[] = [
            $this->limitText($pkgLabel, 48),
            $invoice->billing_period_start->format('d/m/Y') . ' - ' . $invoice->billing_period_end->format('d/m/Y'),
            $this->formatIdr((float) $invoice->subtotal),
        ];

        if ((float) $invoice->tax_amount > 0) {
            $taxRateLabel = $package && (float) $package->tax_rate > 0
                ? 'VAT ' . rtrim(rtrim((string) $package->tax_rate, '0'), '.') . '%'
                : 'VAT / Tax';
            $items[] = [$taxRateLabel, 'Value-Added Tax', $this->formatIdr((float) $invoice->tax_amount)];
        }

        if ((float) $invoice->gateway_fee_charged > 0) {
            $items[] = ['Payment Gateway Fee', 'QRIS / Virtual Account', $this->formatIdr((float) $invoice->gateway_fee_charged)];
        }

        $y = 515;
        foreach ($items as [$desc, $note, $amount]) {
            $s[] = "0.91 0.93 0.96 RG 0.5 w 40 {$y} m 555 {$y} l S";
            $s[] = $this->text(52, $y + 10, $desc, 'F1', 9.5, [0.06, 0.09, 0.16]);
            $s[] = $this->text(355, $y + 10, $note, 'F1', 8.5, [0.39, 0.45, 0.55]);
            $s[] = $this->text(470, $y + 10, $amount, 'F3', 9.5, [0.06, 0.09, 0.16]);
            $y -= 30;
        }

        // Total Summary Box
        $totalBoxY = $y - 24;
        $s[] = "0.933 0.949 1.000 rg 320 {$totalBoxY} 235 34 re f";
        $s[] = "0.494 0.463 0.937 RG 1.0 w 320 {$totalBoxY} 235 34 re S";
        $s[] = $this->text(334, $totalBoxY + 13, 'TOTAL AMOUNT', 'F2', 10, [0.22, 0.19, 0.64]);
        $s[] = $this->text(458, $totalBoxY + 13, $this->formatIdr((float) $invoice->total_amount), 'F2', 11, [0.22, 0.19, 0.64]);

        // Payment / Receipt Information Box
        $payBoxY = $totalBoxY - 102;
        if ($isPaid) {
            $s[] = "0.925 0.992 0.961 rg 40 {$payBoxY} 515 80 re f";
            $s[] = "0.431 0.906 0.718 RG 0.8 w 40 {$payBoxY} 515 80 re S";
            $s[] = $this->text(56, $payBoxY + 60, 'OFFICIAL PAYMENT RECEIPT (PAID)', 'F2', 9.5, [0.02, 0.37, 0.27]);
            $paidAtText = $invoice->paid_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '-';
            $s[] = $this->text(56, $payBoxY + 42, "Paid At        : {$paidAtText} WIB", 'F1', 9, [0.06, 0.25, 0.18]);
            $s[] = $this->text(56, $payBoxY + 26, 'Transaction ID : ' . ($paidPayment?->payment_code ?? '-'), 'F3', 9, [0.06, 0.25, 0.18]);
            $s[] = $this->text(56, $payBoxY + 11, 'Method         : ' . strtoupper((string) ($paidPayment?->gateway ?? 'CASH / MANUAL')), 'F1', 9, [0.06, 0.25, 0.18]);
        } else {
            $s[] = "0.996 0.973 0.914 rg 40 {$payBoxY} 515 80 re f";
            $s[] = "0.961 0.620 0.043 RG 0.8 w 40 {$payBoxY} 515 80 re S";
            $s[] = $this->text(56, $payBoxY + 60, 'ONLINE PAYMENT INFORMATION', 'F2', 9.5, [0.57, 0.25, 0.05]);
            $payLink = route('public.pay', $invoice->public_token);
            $s[] = $this->text(56, $payBoxY + 42, 'Please complete your payment via the Customer Portal or link below:', 'F1', 9, [0.35, 0.20, 0.05]);
            $s[] = $this->text(56, $payBoxY + 25, $this->limitText($payLink, 80), 'F3', 8.5, [0.22, 0.19, 0.64]);
            $s[] = $this->text(56, $payBoxY + 10, 'Service activates or restores automatically upon payment confirmation.', 'F1', 8.5, [0.45, 0.25, 0.05]);
        }

        // Footer
        $s[] = '0.85 0.88 0.92 RG 0.6 w 40 72 m 555 72 l S';
        $s[] = $this->text(40, 56, 'This invoice was generated electronically by ' . ($company->company_name ?: 'IQ Bill') . ' billing system.', 'F1', 8, [0.45, 0.50, 0.58]);
        $s[] = $this->text(40, 44, 'Valid without physical signature. Generated on ' . now('Asia/Jakarta')->format('d M Y H:i') . ' WIB.', 'F1', 8, [0.45, 0.50, 0.58]);

        $contentStream = implode("\n", $s);

        return $this->compilePdf($contentStream);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private function text(float $x, float $y, string $text, string $font, float $size, array $rgb): string
    {
        $escaped = $this->escapePdfText($text);

        return sprintf(
            '%.3f %.3f %.3f rg BT /%s %.1f Tf %.2f %.2f Td (%s) Tj ET',
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $font,
            $size,
            $x,
            $y,
            $escaped
        );
    }

    private function escapePdfText(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $clean = $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', ' ', $text);
        $clean = str_replace(["\r", "\n", "\t"], ' ', (string) $clean);

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $clean);
    }

    private function formatIdr(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    private function limitText(string $text, int $max): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return substr($trimmed, 0, max(0, $max - 3)) . '...';
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, int $width, int $maxLines): array
    {
        $wrapped = explode("\n", wordwrap(trim(preg_replace('/\s+/', ' ', $text) ?? ''), $width, "\n", true));
        $sliced = array_slice($wrapped, 0, $maxLines);
        if (count($wrapped) > $maxLines && $sliced !== []) {
            $sliced[$maxLines - 1] = $this->limitText($sliced[$maxLines - 1] . '...', $width);
        }

        return $sliced;
    }

    private function compilePdf(string $contentStream): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            7 => "<< /Length " . strlen($contentStream) . " >>\nstream\n{$contentStream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($objects as $num => $_) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
