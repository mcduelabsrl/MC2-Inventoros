<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title data-inertia>{{ config('app.name', 'Inventoros') }}</title>

        <!-- Favicon & app icons (?v= makes browsers fetch them again when they change) -->
        <link rel="icon" href="/favicon.ico?v=saxone-1" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="/images/brand/favicon-32.png?v=saxone-1">
        <link rel="icon" type="image/png" sizes="16x16" href="/images/brand/favicon-16.png?v=saxone-1">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=saxone-1">
        <meta name="theme-color" content="#2563eb">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        {{-- Plugin pages (Plugin::slug/Page) are not in the app's Vite manifest. --}}
        @vite(str_starts_with($page['component'], 'Plugin::') ? ['resources/js/app.js'] : ['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
