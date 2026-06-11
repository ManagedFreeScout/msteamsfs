# MSTeamsFS — ManagedFreeScout Teams SSO (FreeScout Module)

**Module alias:** `msteamsfs`
**Version:** 1.0.0
**Namespace:** `Modules\MSTeamsFS`
**GitHub:** https://github.com/ManagedFreeScout/msteamsfs

---

## What this module does

MSTeamsFS is the FreeScout-side component of the ManagedFreeScout Teams SSO flow.
It receives a signed handoff token from the ManagedFreeScout backend, verifies it, and logs the agent into FreeScout automatically.

It does **not** do any Azure JWT validation — that work is done entirely by the backend at `app.managedfreescout.com`.

---

## Full SSO flow

```
Teams tab (browser)
  │
  ├─▶ GET  app.managedfreescout.com/teams/entry
  │         Serves the entry page with TeamsJS SDK
  │
  ├─▶ TeamsJS.getAuthToken() → Azure issues a JWT
  │
  ├─▶ POST app.managedfreescout.com/teams/auth  { token: azureJwt }
  │         Backend: validates JWT, looks up tenant in teams_installs DB,
  │         issues HMAC-SHA256 handoff token (60-second TTL), responds with redirect URL
  │
  └─▶ GET  <freescout_url>/teams-sso-handoff?token=<base64url_token>
            ← THIS MODULE handles this request
            Verifies HMAC, checks expiry, looks up user, Auth::login(), → /mailboxes
```

---

## Token format

The backend (Express/Node) encodes the token as:

```javascript
const tokenPayload = JSON.stringify({ email, exp: Date.now() + 60000 });  // exp in ms
const sig = crypto.createHmac("sha256", backend_secret).update(tokenPayload).digest("hex");
const token = Buffer.from(JSON.stringify({ payload: tokenPayload, sig })).toString("base64url");
```

So in PHP the verification is:

```php
$outer = json_decode(base64_decode(strtr($token, "-_", "+/")), true);
$expected = hash_hmac("sha256", $outer["payload"], $backendSecret);
hash_equals($expected, $outer["sig"]);   // must be true
$payload = json_decode($outer["payload"], true);
$payload["exp"] > (int)(microtime(true) * 1000);  // must be true (exp is ms)
```

---

## Settings

Go to **Settings → MSTeams FS** in FreeScout.

| Field | Description |
|---|---|
| **Backend Secret** | 64-char hex string provided by ManagedFreeScout. Stored as `msteamsfs.backend_secret`. |
| **Allowed Domains** | Optional comma-separated list of email domains (e.g. `stackpros.io,example.com`). Leave blank to allow all. |

### CRITICAL: native FreeScout settings handler

The settings form uses FreeScout's native save handler:

```blade
<form method="POST" action="">
    {{ csrf_field() }}
    <input type="hidden" name="settings[dummy]" value="1" />
    <!-- fields with name="settings[msteamsfs.KEY]" -->
    <button type="submit" name="action" value="msteamsfs_save">Save</button>
</form>
```

**Do not add a custom POST route for settings save.** FreeScout's own controller calls `\Option::set()` automatically when `action` matches `<alias>_save`. Adding a custom route costs hours of debugging.

---

## Reading settings in PHP

Always coalesce — `\Option::get()` returns `null` (not a default) when the key is absent:

```php
$secret  = (string)(\Option::get("msteamsfs.backend_secret")  ?? "");
$domains = (string)(\Option::get("msteamsfs.allowed_domains") ?? "");
```

---

## License

License management is handled by `LicenseService.php` and the license panel in the settings view.
`product_id` is set to `TO_BE_ASSIGNED` in `Config/config.php` — update this once the product is registered.

---

## CSP / Teams embedding

The ServiceProvider adds `frame-ancestors` entries via:

1. `app.csp_frame_ancestors` filter (FreeScout 1.8.219+)
2. `command.after_app_update` hook — patches `.htaccess` after auto-updates

Domains added: `teams.microsoft.com`, `*.teams.microsoft.com`, `*.skype.com`, `*.cloud.microsoft`

---

## Files

```
MSTeamsFS/
├── module.json                          Module manifest
├── composer.json                        No external deps (no JWT library needed)
├── version.txt                          1.0.0
├── start.php                            Loads routes
├── Config/config.php                    License config only
├── Http/
│   ├── routes.php                       GET /teams-sso-handoff + admin license routes
│   └── Controllers/
│       ├── TeamsSsoController.php       handoff() — verifies token, Auth::login()
│       └── MSTeamsFSController.php      License management admin actions
├── Models/MSTeamsFSLicense.php
├── Services/LicenseService.php
├── Providers/MSTeamsFSServiceProvider.php
└── Resources/views/
    ├── handoff-error.blade.php          Shown on invalid/expired tokens
    └── settings/
        ├── msteamsfs.blade.php          Backend Secret + Allowed Domains form
        └── partials/license.blade.php   License activation panel
```

---

## Changelog

### 1.0.1 (2026-06-11)
- Fix: redirect after login now goes to `/` (FreeScout root) instead of `/mailboxes`
- Fix: license gate added at top of `handoff()` — returns 403 if no active license
- Fix: module icon re-copied from MSTeamsSso to ensure it renders on Manage → Modules

### 1.0.0 (2026-06-11)
- Initial build
- Receives ManagedFreeScout HMAC handoff token at GET /teams-sso-handoff
- Verifies HMAC-SHA256 signature and millisecond expiry
- Auth::login() + redirect to /mailboxes
- Settings: Backend Secret + Allowed Domains
- No Azure JWT/JWKS code — backend handles all Azure token validation
