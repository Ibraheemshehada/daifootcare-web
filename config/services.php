<?php

return [

    /*
     * The Python inference sidecar. Loopback only: it runs the models and
     * authenticates nobody, so it must never be reachable from outside the
     * host. Laravel is its only client and does the authorising.
     */
    'inference' => [
        'url' => env('INFERENCE_URL', 'http://127.0.0.1:8500'),
        // A wound analysis is about a second of CPU; anything past this is a
        // sidecar in trouble, and a patient waiting on a spinner is worse than
        // a patient told to try again.
        'timeout' => (int) env('INFERENCE_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
