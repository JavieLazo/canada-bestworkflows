<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting
|--------------------------------------------------------------------------
| Trimmed to the three connections this app can actually use. Reverb and
| Soketi are absent on purpose: both are long-running daemons listening on
| their own port behind a wss:// proxy, and production is a shared cPanel
| account with no shell, no port and no Apache config. A hosted broadcaster
| needs none of that — the app makes ONE outbound HTTPS call and the browser
| connects to the vendor, not to us.
|
| Pusher rather than Ably for one reason: Reverb speaks the Pusher protocol.
| The day this app moves to a VPS, self-hosting the socket server is an .env
| change and nothing in this repository has to know.
|
| DEFAULTING TO `null` IS DELIBERATE. Until PUSHER_* is filled in on a host,
| every screen stays on the polls it has always used and no event leaves the
| process. See config/bestworkflows.php `realtime.enabled`, which is what the app
| actually asks — nothing dispatches an event to a broadcaster that cannot
| deliver it.
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                /*
                 * THE PUNCH MUST NOT WAIT FOR PUSHER, AND MUST NOT FAIL WITH
                 * IT. The announcement already runs after the response is
                 * flushed (App\Support\Realtime), so this timeout bounds a
                 * background call rather than a user-facing one — but an
                 * unbounded one would hold a PHP worker open on a host that
                 * has very few of them, which is how a vendor outage turns
                 * into an outage here.
                 */
                'timeout' => (int) env('PUSHER_TIMEOUT', 5),
                'connect_timeout' => (int) env('PUSHER_CONNECT_TIMEOUT', 3),
            ],
        ],

        /*
         * The escape hatch if 100 concurrent connections ever becomes the
         * binding limit — Ably's free tier is 200 connections and 6M messages
         * a month. Needs `composer require ably/ably-php`; the broadcaster
         * itself ships with the framework. Nothing else in this repository
         * changes: RealtimeChange, the channels and the listeners are
         * broadcaster-agnostic.
         */
        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        /*
         * SELF-HOSTED, and therefore NOT an option on the cPanel host — it is
         * a daemon on its own port behind a wss:// proxy. Two places it is:
         * a local socket server while verifying the loop offline, and a VPS
         * later. Both are reached through the `pusher` connection above with
         * PUSHER_HOST/PORT/SCHEME pointed at it, because Reverb speaks the
         * Pusher protocol — so there is deliberately no `reverb` connection
         * here to keep in sync, and `laravel/reverb` need not be installed in
         * production at all.
         */

        // Writes every event to the log instead of sending it. Useful in dev;
        // never on a host, where it turns a busy kiosk into a busy log file.
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
