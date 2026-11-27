@php
    $code = $code ?? 500;
    $heading = $heading ?? 'Server Error';
    $message = $message ?? 'Please try again in a moment or contact the administrator.';
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $code }} — {{ $heading }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4 text-slate-800 antialiased">
    <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
        <img src="{{ asset('images/iqbill-logo-192.png') }}"
             alt="{{ config('app.name', 'IQ Bill') }}"
             width="44"
             height="44"
             class="mx-auto mb-4 h-11 w-11 rounded-full object-contain">
        <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 font-mono text-lg font-bold">
            {{ $code }}
        </div>
        <h1 class="mt-4 text-xl font-bold text-slate-900">{{ $heading }}</h1>
        <p class="mt-2 text-sm text-slate-500">{{ $message }}</p>
        <div class="mt-6 flex justify-center gap-3">
            <a href="/" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                Back to Home
            </a>
        </div>
    </div>
</body>
</html>
