<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('APP_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('APP_DB_PORT') ?: '3306',
        'name' => getenv('APP_DB_NAME') ?: 'karam_app',
        'user' => getenv('APP_DB_USER') ?: 'karam_app',
        'password' => getenv('APP_DB_PASSWORD') ?: '',
    ],
    'app_base_url' => rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/'),
    'mail_from' => getenv('APP_MAIL_FROM') ?: '',
    'oee_ingest_api_key' => getenv('OEE_INGEST_API_KEY') ?: '',
    'oee_max_payload_bytes' => (int) (getenv('OEE_MAX_PAYLOAD_BYTES') ?: 5242880),
    'oee_max_rows' => (int) (getenv('OEE_MAX_ROWS') ?: 5000),
];
