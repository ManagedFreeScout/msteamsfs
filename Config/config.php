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

    // -- invAIse (current license provider) --
    // Defaults to PROD (app.invaise.com), not ACC — flipped 2026-08-11. invAIse
    // is genuinely live with real tenants and real invoices already flowing
    // through it, and unlike the WordPress checkout connector (which has a real
    // reason to default to ACC — it's an internal test surface), every MSTeamsFS
    // install is a real customer with no sandbox tier to fall back to. A silent
    // ACC default here would just 401 in production with no obvious cause —
    // caught during card #99's live credential test.
    'invaise_base_url'     => env('MSTEAMSFS_INVAISE_BASE_URL', 'https://app.invaise.com'),
    // Real StackPros tenant credentials, generated 2026-08-10 via invAIse's
    // Organisation page (card #99). Still must be set explicitly via .env on
    // each real install — no hardcoded value here, this is a per-tenant secret,
    // not shared infra like backend_url below. Until set, invaiseRequest() logs
    // an error and every call fails closed (never silently "valid").
    'invaise_api_key'      => env('MSTEAMSFS_INVAISE_API_KEY', ''),
    'invaise_api_secret'   => env('MSTEAMSFS_INVAISE_API_SECRET', ''),
    // ManagedFreeScout Teams SSO / notification hub — same backend the handoff
    // login flow talks to (see TEAMS_SSO.md). Same value for every customer install
    // (it is our hub, not a per-tenant credential) so it gets a hardcoded default,
    // same pattern as license_server_url above — no manual per-install .env edit
    // should ever be required for this.
    // Flipped to the PROD hub 2026-07-17 for real org-wide rollout (was
    // acc.managedfreescout.com during ACC-only dev/testing).
    'backend_url'        => env('MSTEAMSFS_BACKEND_URL', 'https://app.managedfreescout.com'),
];
