<?php

return [

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

    // API de Claude (Anthropic) — usada para la lectura inteligente de documentos.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),
    ],

    // Pasarela de pagos Wompi (Bancolombia): PSE, Nequi, tarjetas.
    // Las llaves se obtienen en comercios.wompi.co; el dinero se liquida en la
    // cuenta bancaria registrada en el panel de Wompi (no se configura aquí).
    'wompi' => [
        'public_key' => env('WOMPI_PUBLIC_KEY'),          // pub_prod_xxx o pub_test_xxx
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'), // firma de integridad del checkout
        'events_secret' => env('WOMPI_EVENTS_SECRET'),    // firma de los webhooks (eventos)
        'redirect_url' => env('WOMPI_REDIRECT_URL'),      // a dónde vuelve el cliente tras pagar
    ],

];
