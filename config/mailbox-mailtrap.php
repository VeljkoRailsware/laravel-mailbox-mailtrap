<?php

return [
    // Account-level API token (inbound message reads). https://mailtrap.io/api-tokens
    'api_token' => env('MAILTRAP_API_TOKEN'),

    // signing_secret returned when the inbound_receiving webhook was created.
    'signing_secret' => env('MAILTRAP_INBOUND_SIGNING_SECRET'),

    // Route path under config('mailbox.path'). Final URL: /{mailbox.path}/mailtrap
    'route' => 'mailtrap',

    // Only process events from this inbox; events from other inboxes on the account are
    // ignored. Null or empty = accept any inbox. Must be a positive integer when set;
    // anything else (including "0") makes the webhook answer 503 until the setting is fixed.
    'inbox_id' => env('MAILTRAP_INBOUND_INBOX_ID'),
];
