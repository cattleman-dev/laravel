import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// Cattleman Sockets speaks the Pusher protocol: pusher-js connects to your
// worker's host instead of a Pusher cluster.
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_CATTLEMAN_APP_KEY,
    cluster: 'mt1', // required by pusher-js, unused with a custom host
    wsHost: import.meta.env.VITE_CATTLEMAN_HOST,
    wsPort: import.meta.env.VITE_CATTLEMAN_PORT ?? 443,
    wssPort: import.meta.env.VITE_CATTLEMAN_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_CATTLEMAN_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});
