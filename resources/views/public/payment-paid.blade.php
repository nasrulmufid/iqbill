<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Paid - {{ $invoice->invoice_number }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4 text-slate-800">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8 text-center">
        <div class="mb-5 flex items-center justify-center gap-2">
            <img src="{{ asset('images/iqbill-logo-192.png') }}" alt="IQ Bill" width="28" height="28" class="h-7 w-7 rounded-full object-contain">
            <span class="text-sm font-bold text-slate-800 tracking-tight">{{ config('app.name', 'IQ Bill') }}</span>
        </div>
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h1 class="text-xl font-bold text-slate-900">Invoice Paid</h1>
        <p class="mt-1 text-sm text-slate-500">Payment for this invoice has been confirmed.</p>

        <div class="mt-6 rounded-xl bg-slate-50 border border-slate-100 p-4 text-left space-y-3 text-sm">
            <div class="flex justify-between gap-4">
                <span class="text-slate-500">Invoice Number</span>
                <span class="font-mono font-semibold text-slate-900">{{ $invoice->invoice_number }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-slate-500">Customer</span>
                <span class="font-medium text-slate-900">{{ $maskedName }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-slate-500">Service Package</span>
                <span class="font-medium text-slate-900">{{ $invoice->subscription->package->name ?? '-' }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-slate-500">Total Paid</span>
                <span class="font-bold text-emerald-600">Rp {{ number_format((float) $invoice->total_amount, 0, ',', '.') }}</span>
            </div>
            <div class="flex justify-between gap-4">
                <span class="text-slate-500">Paid At</span>
                <span class="font-medium text-slate-900">
                    {{ $invoice->paid_at?->timezone('Asia/Jakarta')->format('d M Y, H:i') }} WIB
                </span>
            </div>
        </div>

        <div class="mt-6">
            <a href="{{ route('login') }}"
               class="inline-flex w-full min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">
                Sign In to Customer Portal
            </a>
        </div>
    </div>
</body>
</html>
