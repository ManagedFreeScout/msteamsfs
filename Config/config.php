<?php
return [
    // License Configuration — all credentials via .env only, never hardcoded
    'license_server_url' => env('MSTEAMSFS_LICENSE_SERVER_URL', 'https://stackpros.io'),
    'consumer_key'       => env('MSTEAMSFS_CONSUMER_KEY'),
    'consumer_secret'    => env('MSTEAMSFS_CONSUMER_SECRET'),
    'product_id'         => env('MSTEAMSFS_PRODUCT_ID', 'TO_BE_ASSIGNED'),
    'software'           => 2,
];
