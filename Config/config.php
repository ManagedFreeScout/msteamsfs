<?php
return [
    // License Configuration — all credentials via .env only, never hardcoded
    'license_server_url' => env('MSTEAMSFS_LICENSE_SERVER_URL', 'https://stackpros.io'),
    'consumer_key'       => env('MSTEAMSFS_CONSUMER_KEY'),
    'consumer_secret'    => env('MSTEAMSFS_CONSUMER_SECRET'),
    'product_id'         => env('MSTEAMSFS_PRODUCT_ID', 'TO_BE_ASSIGNED'),
    'software'           => 2,

    // ManagedFreeScout Teams SSO / notification hub — same backend the handoff
    // login flow talks to (see TEAMS_SSO.md). Same value for every customer install
    // (it is our hub, not a per-tenant credential) so it gets a hardcoded default,
    // same pattern as license_server_url above — no manual per-install .env edit
    // should ever be required for this.
    // Flipped to the PROD hub 2026-07-17 for real org-wide rollout (was
    // acc.managedfreescout.com during ACC-only dev/testing).
    'backend_url'        => env('MSTEAMSFS_BACKEND_URL', 'https://app.managedfreescout.com'),
];
