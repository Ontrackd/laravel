<?php

return [
    // Off entirely when false.
    'enabled' => env('FEEDBACK_ENABLED', true),

    // The project secret, from the project's Setup tab. Set it where previews are deployed, not in
    // production: a deploy without it carries nothing of ours.
    'secret' => env('FEEDBACK_SECRET'),

    // Where the widget and the API live.
    'api_url' => env('FEEDBACK_API_URL', 'https://api.ontrackd.com'),

    // Add the widget to HTML responses automatically. Turn off to place `@feedback` in a layout instead.
    'inject' => env('FEEDBACK_INJECT', true),
];
