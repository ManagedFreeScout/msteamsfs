<?php
return [
    // Which backend actually validates/activates licenses — 'invaise' is the
    // default and current provider as of the 2026-07 migration. Flip to 'dlm'
    // via MSTEAMSFS_LICENSE_PROVIDER only for emergency rollback (see
    // LicenseService::activateLicenseViaDLM() and its siblings, kept intact
    // but unused unless this is set).
    'license_provider'   => env('MSTEAMSFS_LICENSE_PROVIDER', 'invaise'),

    // -- DLM (legacy — deprecated in favor of invAIse, kept for rollback only) --
    // All credentials via .env only, never hardcoded
    'license_server_url' => env('MSTEAMSFS_LICENSE_SERVER_URL', 'https://stackpros.io'),
    'consumer_key'       => env('MSTEAMSFS_CONSUMER_KEY'),
    'consumer_secret'    => env('MSTEAMSFS_CONSUMER_SECRET'),
    'product_id'         => env('MSTEAMSFS_PRODUCT_ID', 'TO_BE_ASSIGNED'),
    'software'           => 2,

    // -- invAIse (license provider) --
    // Since 1.7.0 (card #268) license checks go through the hub below, signed
    // with the Backend Secret; this install holds no invAIse credentials. Any
    // MSTEAMSFS_INVAISE_* lines left in .env are ignored and can be removed.
    // ManagedFreeScout Teams SSO / notification hub — same backend the handoff
    // login flow talks to (see TEAMS_SSO.md). Same value for every customer install
    // (it is our hub, not a per-tenant credential) so it gets a hardcoded default,
    // same pattern as license_server_url above — no manual per-install .env edit
    // should ever be required for this.
    // Flipped to the PROD hub 2026-07-17 for real org-wide rollout (was
    // acc.managedfreescout.com during ACC-only dev/testing).
    'backend_url'        => env('MSTEAMSFS_BACKEND_URL', 'https://app.managedfreescout.com'),
];
