<?php

declare(strict_types=1);

/*
 * BestWorkflows runtime knobs. Every module reads these through config() so that
 * `php artisan config:cache` works — env() is only consulted here.
 */
return [

    // Livewire poll cadence of the EDTR live feed / Portal "My punches",
    // in seconds.
    'poll_feed' => (int) env('BESTWORKFLOWS_POLL_FEED', 15),

    'realtime' => [
        /*
         * Whether a punch or a notification is ANNOUNCED to open browsers over
         * a websocket, rather than waited for by the poll.
         *
         * Derived from the broadcaster rather than set by hand: `log` and
         * `null` cannot reach a browser, so on a host with no PUSHER_* keys
         * this is false, no event is ever dispatched, no Echo listener is
         * registered, and every screen behaves exactly as it did before
         * broadcasting existed. Filling in the keys is the whole switch.
         */
        'enabled' => in_array(env('BROADCAST_CONNECTION', 'null'), ['pusher', 'ably', 'reverb'], true),

        /*
         * The poll cadence the feeds fall back to ONCE the socket is carrying
         * the news, in seconds. The poll is not removed when realtime is on —
         * it is the backstop for a dropped socket, a network that blocks
         * websockets, and a spent daily message quota, and because every tick
         * is now a stamp read (App\Livewire\Concerns\WithChangeStamp) a
         * backstop costs almost nothing.
         */
        'poll_backstop' => (int) env('BESTWORKFLOWS_POLL_BACKSTOP', 60),
    ],

    'uploads' => [
        // Per-file cap for document/task uploads (2 MB, matching the
        // reference's API-mode attachmentRejection).
        'max_file_bytes' => (int) env('BESTWORKFLOWS_MAX_UPLOAD_BYTES', 2_000_000),

        // Whole-app storage ceiling shared by every upload surface (1 GB).
        'storage_ceiling_bytes' => (int) env('BESTWORKFLOWS_STORAGE_CEILING_BYTES', 1_000_000_000),
    ],
];
