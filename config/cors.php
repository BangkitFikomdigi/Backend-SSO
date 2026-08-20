<?php

// CORS_ALLOWED_ORIGINS di .env bisa diisi beberapa origin dipisah koma.
// Default di bawah ini sudah mencakup akses via IP LAN maupun localhost,
// supaya frontend bisa dites dari dua-duanya tanpa ubah config lagi.
$defaultOrigins = [
    'http://192.168.4.22:5173',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', $defaultOrigins)))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,   // <-- INI YANG PALING PENTING!
];