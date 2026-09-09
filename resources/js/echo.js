/*
 * Laravel Echo, speaking the Pusher protocol — the client half of
 * App\Support\Realtime.
 *
 * GUARDED ON THE KEY, AND THAT GUARD IS THE FEATURE FLAG. With no
 * VITE_PUSHER_APP_KEY in the build, `window.Echo` is never defined, no socket
 * is opened, and every screen stays on the polls it has always used. The
 * server half agrees by way of config/bestworkflows.php `realtime.enabled`, which is
 * false without PUSHER_* — so it never registers an Echo listener either, and
 * nothing warns into the console about an Echo that was never meant to exist.
 *
 * ⚠ VITE_* IS BAKED INTO THE BUILD, NOT READ AT RUNTIME. `npm run build`
 * (and therefore ./build-deploy.sh) freezes whatever is in the .env of the
 * machine doing the building into public/build. Setting PUSHER_* on the host
 * alone gives you a server that broadcasts to browsers that are not listening.
 * See DEPLOY.md.
 *
 * HOST OVERRIDES — why they exist even though production is hosted Pusher:
 * Reverb and Soketi speak the same protocol, so pointing these at a socket
 * server on localhost turns this file into a complete offline test of the
 * whole loop, with no vendor account. It is also, unchanged, what the eventual
 * move to a VPS running Reverb needs. Left unset, Echo talks to Pusher's
 * cluster over TLS, which is what a host does.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = import.meta.env.VITE_PUSHER_APP_KEY;

if (key) {
    const host = import.meta.env.VITE_PUSHER_HOST;
    const scheme = import.meta.env.VITE_PUSHER_SCHEME ?? 'https';
    const secure = scheme === 'https';

    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'pusher',
        key,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
        // Only set when self-hosting the socket server; otherwise Echo derives
        // the endpoint from the cluster, which is what hosted Pusher wants.
        ...(host
            ? {
                  wsHost: host,
                  wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 8080),
                  wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
              }
            : {}),
        forceTLS: secure,
        // ws/wss only. The HTTP fallbacks exist for networks that block
        // websockets, and on those we would rather fall back to the polls
        // still running underneath than hold a long-lived HTTP request open.
        enabledTransports: secure ? ['ws', 'wss'] : ['ws'],
    });
}
