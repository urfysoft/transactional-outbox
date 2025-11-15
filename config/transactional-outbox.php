<?php

return [
    'service_name' => env('URFYSOFT_OUTBOX_SERVICE_NAME', env('APP_NAME', 'laravel')),

    'api_key' => env('URFYSOFT_OUTBOX_API_KEY'),

    'headers' => [
        'message_id' => env('URFYSOFT_OUTBOX_HEADERS_MESSAGE_ID', 'X-Message-Id'),
        'source_service' => env('URFYSOFT_OUTBOX_HEADERS_SOURCE_SERVICE', 'X-Source-Service'),
        'event_type' => env('URFYSOFT_OUTBOX_HEADERS_EVENT_TYPE', 'X-Event-Type'),
        'api_key' => env('URFYSOFT_OUTBOX_HEADERS_API_KEY', 'X-Api-Key'),
        'custom_prefix' => env('URFYSOFT_OUTBOX_HEADERS_PREFIX', 'X-'),
    ],

    'services' => [
        'mis-service' => env('URFYSOFT_OUTBOX_SERVICES_MIS_SERVICE_URL', 'https://stage.test.dmed.uz'),
    ],

    'processing' => [
        'batch_size' => env('URFYSOFT_OUTBOX_PROCESSING_BATCH_SIZE', 100),
        'max_retries' => env('URFYSOFT_OUTBOX_PROCESSING_MAX_RETRIES', 5),
        'retry_delay' => env('URFYSOFT_OUTBOX_PROCESSING_RETRY_DELAY', 60), // seconds
    ],

    'driver' => env('URFYSOFT_OUTBOX_DRIVER', 'http'),

    'message_brokers' => [
        'http' => [
            'timeout' => env('OUTBOX_MESSAGE_BROKER_HTTP_TIMEOUT', 30),
            'retry_times' => env('OUTBOX_MESSAGE_BROKER_HTTP_RETRY', 3),
            'retry_delay' => env('OUTBOX_MESSAGE_BROKER_HTTP_RETRY_DELAY', 1000), // milliseconds
        ],
    ],

    'inbox' => [
        'handlers' => [
            // Example: App\Messaging\PaymentCompletedHandler::class
        ],
    ],
];
