# MSTeamsFS — Installation

Connects your FreeScout helpdesk to Microsoft Teams. Agents sign in with the
Microsoft 365 identity they already have open — no separate password.

## Requirements

- FreeScout 1.8.101 or newer.
- Apache or LiteSpeed (the module manages its own block in FreeScout's root
  `.htaccess` so Teams may embed the helpdesk). On nginx, add the header
  yourself: `Content-Security-Policy: frame-ancestors 'self'
  https://teams.microsoft.com https://*.teams.microsoft.com https://*.skype.com
  https://*.cloud.microsoft`.
- A ManagedFreeScout subscription: you receive a **Backend Secret** and a
  **license key**.

## Installation

1. In FreeScout, go to **Manage → Modules → Upload** and upload the
   `msteamsfs.zip` release asset (FreeScout extracts it into
   `Modules/MSTeamsFS/` itself), then activate the module.
2. Go to **Settings → MSTeams FS**, paste your **Backend Secret** and save.
3. On the same page, enter your **License Key** and click **Activate License**. The
   license is checked through the ManagedFreeScout hub with your Backend
   Secret: no invAIse or Azure credentials and no `.env` editing are needed.
4. Set **Allowed Domains** (comma-separated email domains, e.g.
   `example.com`). Only Microsoft accounts in these domains can sign in.
   Recommended: leave it blank only if every account in your Microsoft tenant
   may use the helpdesk.
5. Optional: under **User Creation**, tick **Create Users** ("Automatically
   create a FreeScout user on their first Teams sign-in") and choose the
   mailboxes new users get. New users always get the role User, never Admin,
   and this needs Allowed Domains.
6. In Microsoft Teams, install the "FreeScout for Teams" app.

## Notes

- Sign-in matches the Microsoft account's email to a FreeScout user. Without
  automatic creation, the user must already exist. After the first sign-in the
  user is tied to that Microsoft identity; disabled users are refused.
- Seats: each person who signs in through Teams occupies one seat of your
  license. When all seats are taken, new people see "No more license seats
  are available" until a seat is freed or added.
- The license is re-checked every 6 hours. If the hub cannot be reached, the
  last known status stays in force (for up to 14 days).
- Upgrading from 1.6.x: any `MSTEAMSFS_INVAISE_*` lines in `.env` are no longer
  used (since 1.7.0) and can be removed.
