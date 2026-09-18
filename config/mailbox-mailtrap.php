<?php

return [
    // Account-level API token (inbound message reads). https://mailtrap.io/api-tokens
    'api_token' => env('MAILTRAP_API_TOKEN'),

    // signing_secret returned when the inbound_receiving webhook was created.
    'signing_secret' => env('MAILTRAP_INBOUND_SIGNING_SECRET'),

    // Route path under config('mailbox.path'). Final URL: /{mailbox.path}/mailtrap
    'route' => 'mailtrap',

    // Reject deliveries whose events carry a different inbox_id (null = accept any).
    'inbox_id' => env('MAILTRAP_INBOUND_INBOX_ID'),
];
