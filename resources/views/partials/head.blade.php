<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'BestWorkflows') : config('app.name', 'BestWorkflows') }}
</title>

{{-- BestWorkflows brand assets. --}}
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/bestworkflows-icon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{--
    Default the app to LIGHT for a visitor who has never chosen an appearance.
    Flux otherwise falls back to the OS ('system'), so a dark desktop shows a
    dark app on first load. This seeds 'light' once, before Flux's own script
    reads the value below.

    The one-time flag is the careful part: Flux records an explicit "system"
    choice as the ABSENCE of the key, so a plain "seed light whenever the key is
    missing" would silently overrule "system" on the next page load. The flag
    fires the seed only on the very first visit; every later choice — including
    "system" and "dark" — is left exactly as the person set it.
--}}
<script>
    try {
        var store = window.localStorage;
        if (! store.getItem('flux.appearance') && ! store.getItem('appearance.defaulted')) {
            store.setItem('flux.appearance', 'light');
            store.setItem('appearance.defaulted', '1');
        }
    } catch (e) {}
</script>
@fluxAppearance
