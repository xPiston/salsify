<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Token and organization
    |---------------------------------------------------------------------------
    |
    | The API token from your Salsify user profile, and the organization it
    | acts in - the id in the app URL right after /orgs/.
    |
    | Both are required, and the organization is not decoration: Salsify counts
    | the rate limit per organization, so the same token has a separate budget
    | in each one it belongs to. A user in several organizations who omits it
    | gets a 400 listing them all.
    |
    */

    'token' => env('SALSIFY_TOKEN'),

    'organization' => env('SALSIFY_ORGANIZATION'),

    /*
    |---------------------------------------------------------------------------
    | Host
    |---------------------------------------------------------------------------
    |
    | Only worth changing for a Salsify instance that is not the public one.
    |
    */

    'host' => env('SALSIFY_HOST', 'https://app.salsify.com'),

    /*
    |---------------------------------------------------------------------------
    | Webhook endpoint
    |---------------------------------------------------------------------------
    |
    | The URL you gave Salsify for webhooks, exactly as you gave it.
    |
    | It is part of what the signature covers, which is why it has to be
    | configured rather than read from the incoming request: taking it from
    | the request would mean verifying a signature against a value the sender
    | controls.
    |
    */

    'webhook_url' => env('SALSIFY_WEBHOOK_URL'),

    /*
    |---------------------------------------------------------------------------
    | Webhook tolerance
    |---------------------------------------------------------------------------
    |
    | How old a webhook may be, in seconds. Salsify recommends five minutes.
    | Raising it widens the window in which a captured request can be replayed.
    |
    */

    // Cast by the provider: env() returns mixed, and a config file is not
    // the place to argue with a static analyser about it.
    'webhook_tolerance' => env('SALSIFY_WEBHOOK_TOLERANCE', 300),

];
