<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? 'Sign in' }} - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-sans antialiased text-gray-900">
        <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-br from-brand-50 via-white to-emerald-50 px-4 py-10">
            <div class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-brand-200/40 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -right-24 h-80 w-80 rounded-full bg-emerald-200/50 blur-3xl"></div>

            <div class="relative w-full max-w-md">
                <div class="mb-8 text-center">
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-brand-600 shadow-lg shadow-brand-600/30">
                        <x-lm.icon name="book" class="h-9 w-9 text-white" />
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ \App\Support\BillingSettings::get('app_name') ?: config('app.name') }}</h1>
                    <p class="mt-1 text-sm text-slate-500">Library Management System</p>
                </div>

                <div class="rounded-2xl border border-slate-200/80 bg-white/90 p-8 shadow-xl shadow-slate-200/60 backdrop-blur">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @livewireScripts
    </body>
</html>
