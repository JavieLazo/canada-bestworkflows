{{--
    THE one error page. Every status draws this file: the company mark, a
    heading, a sentence or two in plain words, and the error number. Nothing is
    boxed, and a link appears only where there is somewhere useful to go — the
    numbered files beside this one only name their status.

    IT STANDS ON ITS OWN. An error page is shown when something else has
    failed, so it must not need the things that fail: no Vite build (a missing
    manifest would throw inside the page that reports the first error), no
    session, no signed-in account and no database. Its styles are in this file
    and carry the same colours as the tokens in resources/css/app.css; its
    links are root-relative, because `artisan down --render=errors::503` draws
    the 503 page ONCE, from the command line, where there is no request to
    build a URL from and no exception to read.

    Livewire shows a failed request's page inside an overlay on the page the
    person was using, so this must read well in that smaller box too.
--}}
@php
    $status = (int) ($status ?? (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500));

    // Heading, message, and — only where it leads somewhere useful — a link.
    $pages = [
        401 => ['Sign In Required', 'Please sign in to open this page.', '/login', 'Sign In'],
        403 => ['No Access to This Page', 'Your account is not allowed to open this page or do this action. If you need it for your work, ask your administrator.', '/', 'Go to Home'],
        404 => ['Page Not Found', 'We could not find the page or record you were looking for. It may have been moved or removed, or the link may be mistyped.', '/', 'Go to Home'],
        419 => ['Page Expired', 'This page was open for too long, so what you sent was not saved. Go back, refresh the page, and try again.'],
        429 => ['Too Many Attempts', 'That was tried too many times in a short while. Please wait a minute, then try again.'],
        503 => ['We Are Updating the System', 'The system is closed for a short while so an update can be installed. Your saved records are safe. Please come back in a few minutes.'],
    ];

    // Every other status shares one of two pages. NOTHING ABOUT A FAILURE IS
    // SHOWN: the detail is in the host's log.
    $refused = ['This Request Could Not Be Completed', 'The system could not do what was asked from this page. Go back and try again. If it keeps happening, tell your administrator what you were doing.'];
    $failed = ['Something Went Wrong', 'The system ran into a problem. It is not something you did. Please try again in a moment. If it keeps happening, tell your administrator what you were doing and what time it was.'];

    [$heading, $message, $href, $link] = array_pad($pages[$status] ?? ($status >= 500 ? $failed : $refused), 4, null);

    // A reason given where a request was refused ("Account disabled — contact
    // HR.") is shown as written. The framework's own stock phrases are not,
    // and neither is any other status's message: a missing record's names the
    // model class.
    if ($status === 403 && isset($exception)) {
        $reason = trim($exception->getMessage());
        if ($reason !== '' && ! in_array($reason, ['Forbidden', 'This action is unauthorized.'], true)) {
            $message = $reason;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex" />

        <title>{{ $heading }} - {{ config('app.name', 'BestWorkflows') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/bestworkflows-icon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap">

        <style>
            :root {
                --background: oklch(0.972 0.005 247.9);
                --foreground: oklch(0.23 0.035 260);
                --primary: oklch(0.55 0.19 258);
                --muted-foreground: oklch(0.53 0.028 258);
            }

            .dark {
                --background: oklch(0.19 0.025 260);
                --foreground: oklch(0.96 0.006 250);
                --primary: oklch(0.66 0.16 258);
                --muted-foreground: oklch(0.72 0.02 256);
            }

            *,
            *::before,
            *::after {
                box-sizing: border-box;
            }

            html {
                -webkit-text-size-adjust: 100%;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background: var(--background);
                color: var(--foreground);
                font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                font-size: 1rem;
                line-height: 1.6;
                text-align: center;
                -webkit-font-smoothing: antialiased;
            }

            main {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 2rem 1.5rem;
            }

            .error-brand {
                margin-bottom: 2rem;
            }

            .error-brand img {
                display: block;
                width: 16rem;
                max-width: 100%;
                height: auto;
                margin: 0 auto;
                border-radius: 0.375rem;
            }

            /* The logo is dark artwork on a transparent background: on the dark
               page it sits on a white plate so it can still be read. */
            .dark .error-brand img {
                padding: 0.5rem 0.75rem;
                border-radius: 0.375rem;
                background: #fff;
            }

            .error-brand p {
                margin: 0.5rem 0 0;
                font-size: 0.75rem;
                color: var(--muted-foreground);
            }

            h1 {
                margin: 0;
                font-size: 1.75rem;
                font-weight: 700;
                line-height: 1.25;
                text-wrap: balance;
            }

            .error-message {
                max-width: 30rem;
                margin: 0.75rem 0 0;
                color: var(--muted-foreground);
                text-wrap: pretty;
            }

            .error-link {
                display: inline-block;
                margin-top: 1.25rem;
                color: var(--primary);
                font-weight: 600;
                text-decoration: none;
            }

            .error-link:hover {
                text-decoration: underline;
                text-underline-offset: 4px;
            }

            .error-code {
                margin: 2rem 0 0;
                font-size: 0.75rem;
                letter-spacing: 0.04em;
                color: var(--muted-foreground);
            }

            a:focus-visible {
                outline: 2px solid var(--primary);
                outline-offset: 2px;
            }

            footer {
                padding: 0 1rem 1.5rem;
                font-size: 0.75rem;
                color: var(--muted-foreground);
            }

            footer a {
                color: inherit;
                font-weight: 500;
                text-decoration: none;
            }

            footer a:hover {
                color: var(--foreground);
                text-decoration: underline;
                text-underline-offset: 4px;
            }

            @media (max-width: 400px) {
                h1 {
                    font-size: 1.5rem;
                }
            }
        </style>

        {{--
            The page follows the appearance the person chose in the app: Flux
            keeps "dark" or "light" under this key and keeps "follow my device"
            as no key at all.
        --}}
        <script>
            try {
                var chosen = window.localStorage.getItem('flux.appearance');
                var device = window.matchMedia('(prefers-color-scheme: dark)').matches;
                var defaulted = window.localStorage.getItem('appearance.defaulted');
                if (chosen === 'dark' || (! chosen && defaulted && device)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        </script>
    </head>
    <body>
        <main>
            <div class="error-brand">
                <img src="/bestworkflows-horizontal-view.png" alt="BestWorkflows — Local Compliance, Global Workflows" width="2172" height="724" />
                <p>Management Information System</p>
            </div>

            <h1>{{ $heading }}</h1>

            <p class="error-message">{{ $message }}</p>

            @if ($href !== null)
                <a class="error-link" href="{{ $href }}">{{ $link }}</a>
            @endif

            <p class="error-code">Error {{ $status }}</p>
        </main>

        @include('partials.copyright')
    </body>
</html>
