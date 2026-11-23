<?php

/*
| Radiance Flow, the org's live channel: this app publishes small "something changed" events to Flow topics over its HTTPS
| Connect JSON API, and browsers listen on Flow's WebSocket with a short-lived ticket this app signs (GET /live/ticket).
| Nothing here is a secret: the app token and the ticket secret come from Flow, by file path or env, and are never committed.
| With no token the whole thing is off and the admin and the site keep their own refresh and polling.
*/

return [

    // Flow's public address (Connect JSON and the WebSocket)
    'url' => rtrim((string) env('FLOW_URL', 'https://live.radianceph.com:8443'), '/'),

    // The WebSocket the browser opens; by default FLOW_URL with wss:// and /ws
    'ws_url' => env('FLOW_WS_URL'),

    // The app token (scopes publish:826.*), from a file Flow's secret store writes, else the env
    'app_token' => env('FLOW_APP_TOKEN'),
    'app_token_file' => env('FLOW_APP_TOKEN_FILE'),

    // The ticket secret for this app (Flow's [[ticket]] of kind "flow"), the same way
    'ticket_secret' => env('FLOW_TICKET_SECRET'),
    'ticket_secret_file' => env('FLOW_TICKET_SECRET_FILE'),

    // How long a ticket opens subscriptions; the browser asks again before it runs out
    'ticket_ttl' => (int) env('FLOW_TICKET_TTL', 300),

    // Seconds to wait for Flow before giving up on one publish (a failure never breaks the request)
    'timeout' => (int) env('FLOW_TIMEOUT', 2),

    // After a failed publish, skip publishing for this many seconds
    'pause_after_failure' => (int) env('FLOW_PAUSE_AFTER_FAILURE', 20),

];
