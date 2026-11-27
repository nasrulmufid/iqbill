<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connecting to Payment Gateway - {{ $invoice->invoice_number }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4 text-slate-800">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 p-6 sm:p-8 text-center">
        <div class="mb-5 flex items-center justify-center gap-2">
            <img src="{{ asset('images/iqbill-logo-192.png') }}" alt="IQ Bill" width="28" height="28" class="h-7 w-7 rounded-full object-contain">
            <span class="text-sm font-bold text-slate-800 tracking-tight">{{ $company->company_name ?? config('app.name', 'IQ Bill') }}</span>
        </div>
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600">
            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86l-7.1 12.27A2 2 0 004.92 19h14.16a2 2 0 001.73-2.87l-7.1-12.27a2 2 0 00-3.42 0z" />
            </svg>
        </div>

        <h1 class="text-xl font-bold text-slate-900">Connecting to Payment Gateway</h1>
        <p class="mt-2 text-sm text-slate-600">
            The connection to the QRIS payment gateway is temporarily unavailable for invoice
            <span class="font-mono font-semibold">{{ $invoice->invoice_number }}</span>.
        </p>

        <div class="mt-6 space-y-3">
            <a href="{{ route('public.pay', $invoice->public_token) }}"
               class="inline-flex w-full min-h-12 items-center justify-center rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                Retry Connection
            </a>
            <p class="text-xs text-slate-500">
                @if(!empty($company?->company_phone))
                    If this issue persists, please contact {{ $company->company_name ?: 'our support team' }}
                    at <span class="font-mono font-medium text-slate-700">{{ $company->company_phone }}</span>.
                @else
                    No login is required to pay via this link. Please try again in a moment or contact your internet provider.
                @endif
            </p>
        </div>
    </div>
</body>
</html>
