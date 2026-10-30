<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title ?? 'Sign in' }} - {{ \App\Support\BillingSettings::get('app_name') ?: config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="login-command-page font-sans antialiased">
        <!-- Hanging bookmark ribbons -->
        <div class="page-3d-scene" aria-hidden="true">
            <span class="ribbon ribbon-one"></span>
            <span class="ribbon ribbon-two"></span>
            <span class="ribbon ribbon-three"></span>
        </div>

        {{ $slot }}

        @livewireScripts
    </body>
</html>
