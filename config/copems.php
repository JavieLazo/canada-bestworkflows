<?php

declare(strict_types=1);

/*
 * BestWorkflows runtime settings. Every module reads these through config() so that
 * `php artisan config:cache` works — env() is only consulted here.
 */
return [

    // Current employer label; frozen historical records keep their own copy.
    'company_name' => env('COMPANY_NAME', env('APP_NAME', 'BestWorkflows')),

    // Where the header's App link downloads the eDTR mobile app from: a full
    // address or a path on this host. The app file is uploaded by the
    // operator, never shipped in a release, so the link is drawn only once
    // this names where it is.
    'mobile_app_url' => env('BESTWORKFLOWS_MOBILE_APP_URL', env('COPEMS_MOBILE_APP_URL')),

    // Livewire poll cadence of the EDTR live feed, Portal "My punches" and
    // the open Chat page, in seconds.
    'poll_feed' => (int) env('BESTWORKFLOWS_POLL_FEED', env('COPEMS_POLL_FEED', 15)),

    'realtime' => [
        /*
         * Whether a punch or a notification is ANNOUNCED to open browsers over
         * a websocket, rather than waited for by the poll.
         *
         * Derived from the broadcaster rather than set by hand: `log` and
         * `null` cannot reach a browser, so on a host with no PUSHER_* keys
         * this is false, no event is ever dispatched, no Echo listener is
         * registered, and every screen runs on its poll alone. Filling in the
         * keys is the whole switch.
         */
        'enabled' => in_array(env('BROADCAST_CONNECTION', 'null'), ['pusher', 'ably', 'reverb'], true),

        /*
         * The poll cadence the feeds fall back to ONCE the socket is carrying
         * the news, in seconds. The poll is not removed when realtime is on —
         * it is the backstop for a dropped socket, a network that blocks
         * websockets, and a spent daily message quota, and because every tick
         * is a stamp read (App\Livewire\Concerns\WithChangeStamp) a
         * backstop costs almost nothing.
         */
        'poll_backstop' => (int) env('BESTWORKFLOWS_POLL_BACKSTOP', env('COPEMS_POLL_BACKSTOP', 60)),
    ],

    'inventory' => [
        // Each issued MR keeps its own copy for stable historical reprints.
        'office_name' => env('INVENTORY_OFFICE_NAME', env('APP_NAME', 'BestWorkflows')),
    ],

    'uploads' => [
        // Per-file cap for document/task uploads (2 MB, matching the
        // reference's API-mode attachmentRejection).
        'max_file_bytes' => (int) env('BESTWORKFLOWS_MAX_UPLOAD_BYTES', env('COPEMS_MAX_UPLOAD_BYTES', 2_000_000)),

        // Storage ceiling shared by employee, task and inventory files (1 GB).
        'storage_ceiling_bytes' => (int) env('BESTWORKFLOWS_STORAGE_CEILING_BYTES', env('COPEMS_STORAGE_CEILING_BYTES', 1_000_000_000)),

        // Community and Chat's own budget (500 MB). Their photos and files
        // never count against the ceiling above, and theirs never against
        // this one, so a busy chat can never block an HR document or a task
        // upload.
        'social_storage_ceiling_bytes' => (int) env('BESTWORKFLOWS_SOCIAL_STORAGE_CEILING_BYTES', env('COPEMS_SOCIAL_STORAGE_CEILING_BYTES', 500_000_000)),
    ],

    /*
     * CSV imports (App\Platform\Transfer\ImportRunner). A file is read from
     * Livewire's temporary upload and never stored, so these are not
     * attachment limits. The whole file is checked, then saved in ONE
     * transaction on a shared host with no worker, so both caps also bound
     * how long that one request runs.
     */
    'imports' => [
        // Largest CSV accepted, in bytes (2 MB).
        'max_file_bytes' => (int) env('BESTWORKFLOWS_IMPORT_MAX_BYTES', env('COPEMS_IMPORT_MAX_BYTES', 2_000_000)),

        // Most data rows in one file; a bigger sheet is split by the operator.
        'max_rows' => (int) env('BESTWORKFLOWS_IMPORT_MAX_ROWS', env('COPEMS_IMPORT_MAX_ROWS', 1_000)),
    ],
];
