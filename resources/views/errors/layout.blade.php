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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4 text-slate-800 antialiased">
    <div class="max-w-md w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center">
        <div class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 font-mono text-xl font-bold">
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
