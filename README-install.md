# MSTeamsFS — Installation

Connects your FreeScout helpdesk to Microsoft Teams. Agents sign in with the
Microsoft 365 identity they already have open — no separate password.

## Installation

1. In FreeScout, go to **Manage → Modules → Upload** and upload the
   `msteamsfs.zip` release asset (no manual file copying — FreeScout extracts
   it into `Modules/MSTeamsFS/` itself).
2. Go to **Settings → MSTeams FS**.
3. Paste your **Backend Secret** (provided by ManagedFreeScout when you
   activated your license) and save. This is the only required configuration
   — no Azure app registration, no `.env` editing.
4. Optionally set **Allowed Domains** (comma-separated) to restrict sign-in to
   specific email domains. Leave blank to allow any domain.
5. In Microsoft Teams, install the "FreeScout for Teams" app (from the Teams
   app store, or sideload the manifest if not yet published for your org).

## Notes

- Sign-in matches by **email only** — the module does **not** create
  FreeScout users automatically. The Microsoft account's email must already
  match an existing FreeScout user, or sign-in is refused.
- Route: `GET /teams-sso-handoff` (see `TeamsSsoController::handoff()`), not
  `/teams-entry` or `/teams-sso-login` — this file previously described an
  earlier prototype design that used those names; it never shipped that way.
- Token validation happens on the **ManagedFreeScout backend**
  (`app.managedfreescout.com`), not against Azure AD JWKS directly from this
  module — see the main `README.md`'s "Full SSO flow" section for the
  complete picture.
