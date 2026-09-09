<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

{{-- BestWorkflows logo (bestworkflows-logo.png), same mark the reference ships as src/app/icon.png --}}
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/bestworkflows-icon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
