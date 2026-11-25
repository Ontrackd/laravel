<?php

return [
    // Off entirely when false. The FEEDBACK_* names still work where the ONTRACKD_* ones are not set.
    'enabled' => env('ONTRACKD_ENABLED', env('FEEDBACK_ENABLED', true)),

    // The project secret, from the project's Setup tab. Set it where previews are deployed, not in
    // production: a deploy without it carries nothing of ours.
    'secret' => env('ONTRACKD_SECRET') ?: env('FEEDBACK_SECRET'),

    // Where the widget and the API live.
    'api_url' => env('ONTRACKD_API_URL') ?: env('FEEDBACK_API_URL', 'https://api.ontrackd.com'),

    // Add the widget to HTML responses automatically. Turn off to place `@ontrackd` in a layout instead.
    'inject' => env('ONTRACKD_INJECT', env('FEEDBACK_INJECT', true)),
];
