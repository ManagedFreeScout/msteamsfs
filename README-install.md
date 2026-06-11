# FreeScout MSTeams SSO Module (SampleModule structure)

This module uses FreeScout's SampleModule layout. It provides `/teams-entry` and `/teams-sso-login` endpoints and will auto-create users when a Teams-authenticated email does not exist.

## Installation
1. Copy this folder (`MSTeamsFS_SampleModule`) into your FreeScout `Modules/` directory and rename to `MSTeamsFS`.
2. Run:

```
composer require guzzlehttp/guzzle firebase/php-jwt
php artisan config:clear && php artisan cache:clear && php artisan route:clear
```

3. Add to your `.env`:

```
MSTEAMS_CLIENT_ID=your-client-id
MSTEAMS_TENANT_ID=common
MSTEAMS_AUDIENCE=your-client-id
```

4. Ensure your Teams app manifest `contentUrl` points to `https://yourdomain.example/teams-entry`.

5. Test inside Microsoft Teams.

## Notes
- The module validates tokens using Azure AD JWKS.
- It matches users by email and creates new users if not found.
