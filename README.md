# MSTeamsFS — ManagedFreeScout Teams SSO (FreeScout Module)

**Module alias:** `msteamsfs`
**Version:** 1.3.0
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
├── version.txt                          1.3.0
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

### 1.3.0 (2026-07-23) — ✅ CASE CLOSED: mobile "Sorry" page root-caused (external, not a module bug)

**The mobile stale-404 investigation (v1.2.4–1.2.9) is closed.** Root cause: the FreeScout
icon pinned in the bottom bar of Rutger's Teams Android app was the **old single-tenant
MSTeamsSso app** (App ID `09c12fb0-...`, short name "FreeScout") — installed org-wide months
ago and still pinned, not the new multi-tenant "FreeScout for MS Teams" app. Its `contentUrl`
pointed at the old single-tenant entry route on support.stackpros.io, which was deliberately
retired during Rutger's own single-tenant cleanup a few days prior. Every tap on that pin
loaded a dead route → FreeScout's own styled 404. **This explains every anomaly found during
the investigation at once**: zero requests ever reaching `handoff()` (confirmed via the
`[MSTeamsFS-DEBUG]` logging added in 1.2.4/1.2.5 — right code, wrong door, since the
traffic was hitting a completely different, dead route); identical behavior regardless of
which module version was live (the broken app doesn't call into this module's code at all);
cache-clearing changing nothing (no caching involved anywhere — the URL was just dead); and
the real, current entry flow working fine throughout. The orphaned single-tenant SSO route
and `teams-entry.blade.php` view — found and confirmed dead via a live `curl` **twice** during
this investigation (v1.2.7 and again while chasing this) — was exactly this corpse.
**Resolution**: Rutger has blocked the old app in Teams admin center. No code fix was ever
possible or needed for this specific symptom — flagging that plainly rather than
retroactively implying otherwise.

**Removed** (this release): every `[MSTeamsFS-DEBUG]` debug line and the `$debugUser`
tracking added across 1.2.4/1.2.5 in `TeamsSsoController::handoff()` — root cause is found,
so per this project's own stated discipline they come out completely, not left "just in
case." Verified zero `[MSTeamsFS-DEBUG]`/`$debugUser` references remain anywhere in the
module's actual code (changelog/session-log history below still documents what those
builds did, deliberately — that's not code, and rewriting past entries to hide what was
actually shipped would be dishonest).

**Kept** (all independently real fixes/improvements found along the way, unrelated to the
mobile root cause above):
- The `Http/Middleware/InjectTeams404ReloadScript.php` 404-detecting middleware — verified
  correct after two real bugs of its own were found and fixed (1.2.8: exception vs. returned
  response; 1.2.9: Content-Type header timing). A legitimate safety net for any *real* 404
  reaching this server, even though it wasn't what this specific symptom needed.
- The `pageshow`/`persisted` listener in `msteamsfs.js` (v1.2.6) — harmless, still a
  reasonable defensive measure for genuine bfcache-restore scenarios elsewhere.
- The `\App\Conversation::find()` defensive check before redirecting to a specific
  conversation (v1.2.3) — a real fix for a real (if different) bug: a stale-but-otherwise-
  valid conversationId no longer silently producing FreeScout's 404.
- The `data-trigger="modal"` click-interceptor fix in `msteamsfs.js` (v1.2.2) — real fix for
  the Kanban Add Card 405 bug, unrelated to any of the above.
- The unsaved-content guard shared by both the `pageshow` listener and the 404 middleware.

**Also fixed in this release**: the README's top `**Version:**` header and the `version.txt`
line in the files-tree diagram had been stale at `1.0.1` since the very first build —
reconciled now to `1.3.0`.

**Separate, explicitly parked, not touched in this release**: the orphaned single-tenant SSO
code on the FreeScout host (`Modules/Http/routes.php`, `Modules/Resources/views/teams-entry.blade.php`,
referencing `Modules\MSTeamsSso\...\TeamsSsoController`) is now confirmed safe to delete,
since the one thing pointing at it has been deliberately blocked in Teams admin center. Left
as a future cleanup-pass item, not part of this module's own release.

### 1.2.9 (2026-07-23) — 🐛 SECOND REAL BUG FOUND IN THIS MIDDLEWARE, FIXED — STILL EXPERIMENTAL
Rutger reported the "Sorry" page still appearing after installing 1.2.8. Investigated by
directly checking the live install rather than trusting the version number: `module.json`
showed 1.2.8 correctly, `module:list` showed Enabled, and the deployed
`Http/Middleware/InjectTeams404ReloadScript.php` did have the 1.2.8 exception-handling fix
in it. But a live `curl` against a real 404 URL still showed zero injection, byte-identical
to before either fix existed.

Also found, while investigating: the log showed a real, fatal, recurring
`Class "Modules\MSTeamsFS\Providers\MSTeamsFSServiceProvider" not found` error clustered
around 15:19–16:01 — traced this to the normal delete-then-reupload window of a manual
module reinstall (one instance came from `Nwidart\Modules\Commands\MigrateCommand`, i.e. a
CLI command running mid-reinstall while the module folder was briefly absent). Confirmed
this had stopped and the module was loading cleanly for real requests (5/5 clean responses)
by the time of the actual investigation — a real but transient reinstall artifact, not an
ongoing problem, and not the cause of the reported bug.

**The actual cause — found via a full, faithful `Kernel::handle()` request simulation**,
not just re-reading the code: built a throwaway diagnostic script (run from the app root,
cleaned up after, same non-live-install-affecting pattern used throughout this project) that
resolves the real `Illuminate\Contracts\Http\Kernel`, constructs a `Request` with a proper
`Host` header (needed to get past `TrustHosts`), and calls `$kernel->handle($request)` for
a URL that doesn't exist — exactly what `public/index.php` does for a genuine browser
request. Confirmed via reflection that `InjectTeams404ReloadScript` **was** correctly
registered at the front of the Kernel's middleware array (the `prependMiddleware()` call
worked). The response status came back 404 correctly. But the injected script still wasn't
there — and the diagnostic printed `Content-Type: ` (empty).

**Root cause**: this middleware's own 404/HTML gate checked
`$response->headers->get('Content-Type')` for `text/html` — but at the point this
middleware's post-`$next()` code runs, that header is still empty. Symfony's
`Response::prepare($request)` — which fills in sensible header defaults, Content-Type
included — runs *after* the entire middleware pipeline finishes (in `Kernel::handle()`,
called via the response object before `send()`), not before. So the Content-Type check was
never going to pass, on any response, ever — this middleware had *never once* actually
fired on a real request, even after the 1.2.8 fix corrected the separate thrown-exception
problem.

**Fix**: dropped the Content-Type header check entirely. Now relies solely on the presence
of a literal `</body>` in the content — already a strictly stronger, more direct signal
that this is real injectable HTML (a JSON error payload will never contain that string),
and it doesn't depend on response-lifecycle timing at all.

**Also fixed a related correctness issue found while re-reviewing, before it could become a
third bug**: catching `Throwable` broadly (not just 404s) meant this middleware was
handling the exception itself and never letting it reach `Kernel::handle()`'s own outer
catch — which normally both *reports* (logs) and *renders* an exception. Only calling
`render()` ourselves silently would have suppressed Laravel's own error logging for any
*other* exception type that happens to pass through here (not just 404s — those were never
logged anyway, per Laravel's `internalDontReport` list, so no change there specifically).
Now calls `$handler->report($e)` before `render()`, matching what `Kernel::handle()` itself
does, so genuine bugs elsewhere in the app don't silently stop being logged.

**Verified via an isolated test matching the exact confirmed-live scenario** (thrown
exception, empty Content-Type) plus four other cases (returned 404 HTML with Content-Type
set, 404 JSON, plain 200 HTML, and that the exception-reporting call actually fires) — all
6 passed. Did not attempt to push this fix directly to the live install for a real
end-to-end retest — sticking to the isolated-test + manual-reinstall-cycle verification
path established for this module.

### 1.2.8 (2026-07-23) — 🐛 REAL BUG FOUND IN 1.2.7'S MIDDLEWARE, FIXED — STILL EXPERIMENTAL
Found this by actually checking the 1.2.7 install rather than assuming it worked: version,
files, and `module:list` all looked correct (module Enabled, file present), and no fatal
errors after the reinstall settled — but a live `curl` against a real 404 URL showed the
response was byte-for-byte identical to before the middleware existed. It was never firing.

**Root cause, confirmed against the actual vendored `Kernel.php`, not assumed:** a genuine
404 (an unmatched route, or `Conversation::findOrFail()` → `ModelNotFoundException` →
`NotFoundHttpException`) is **thrown**, not returned, from the routing/controller layer. In
Laravel's `Kernel::handle()`, the try/catch that converts exceptions to responses
(`renderException()` → `$this->app[ExceptionHandler::class]->render($request, $e)`) wraps
*outside* `sendRequestThroughRouter()` — the method that builds and runs the entire
middleware pipeline. So the exception unwinds straight past every middleware's
post-`$next()` code, including this one, and is only converted to a Response *after*
leaving the pipeline entirely. The original `$response = $next($request);` line in
1.2.7 never saw a 404 — it never got a chance to, since `$next()` itself was throwing, not
returning. No error, no crash, just a silent no-op — which is exactly why it looked fine on
inspection (module active, no fatal errors) while doing nothing at all.

**Fix**: wrap `$next($request)` in a try/catch; on any `Throwable`, render it ourselves via
`app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($request, $e)` — the exact
same call Laravel's own `Kernel::renderException()` makes — so we get the real Response to
inspect/modify, instead of letting it skip past us entirely.

**Verified with an isolated test simulating the actual real-world path** (not just the
happy-path "already-a-Response" case tested in 1.2.7): `$next` throws → middleware catches
it, renders via the stubbed exception handler, injects correctly, status code 404 confirmed.
A normal non-throwing 200 response still passes through untouched. All 3 cases passed.

Tried to verify this live against the actual install too, the same way the 1.2.7 bug was
found — pushing directly to the live install was (correctly) blocked by the permission
classifier, since that's not this project's established deploy path. Sticking with the
isolated-test verification and the manual DirectAdmin install cycle, same as always.

**Also worth noting**: while investigating, found `bootstrap/cache/config.php`/`services.php`/
`packages.php` were regenerated during the 1.2.7 reinstall (consistent with the documented
transient "ServiceProvider not found" / "Module does not exist!" errors logged during the
delete-then-reupload window, ~15:19–15:47 — expected reinstall noise, not a real problem,
confirmed resolved by 15:47 with no further errors since). That was a red herring for *this*
bug, though — a full cache clear afterward did not fix the missing injection; the real
cause was the exception-vs-response handling above.

### 1.2.7 (2026-07-23) — 🧪 EXPERIMENT #2, STILL NOT CONFIRMED
The v1.2.6 `pageshow`/`persisted` experiment did **not** work — Rutger reproduced the exact
same stale-404 symptom after Clear Memory + reopen, confirming the residual uncertainty
flagged in that changelog entry was real (this specific Android/Teams WebView restoration
either doesn't fire `pageshow` with `persisted=true` reliably, or not in a way the listener
caught).

New approach: detect the actual broken page directly instead of inferring staleness from a
lifecycle event. FreeScout's 404 view (`resources/views/errors/404.blade.php`) sets
`@section('title', 'Page Not Found')` — a **hardcoded, non-translated literal**, unlike the
visible body text (`{{ __('Sorry, the page you are looking for could not be found.') }}`),
which *is* run through `__()` and would differ per agent locale. Confirmed unique against
FreeScout's other error pages (403 = "Access denied", 500 = "Error") and confirmed live via
a direct `curl` of an actual 404 response — `<title>Page Not Found</title>` comes through
exactly as the template says.

**Found a structural blocker before implementing anything, reported it, and got a decision
before proceeding**: `msteamsfs.js` cannot run on this page at all. FreeScout's 404 response
extends Laravel's own bare `errors::layout`
(`vendor/laravel/framework/.../Exceptions/views/layout.blade.php`) — a completely separate,
minimal template from FreeScout's normal app layout, with zero `javascripts`/`stylesheets`
Eventy filter hooks. Confirmed directly: the actual live 404 HTML response has no `<script>`
tags at all. So a client-side check written into `msteamsfs.js` would never get a chance to
run on the one page it needs to act on.

Decision (Rutger): solve this via global middleware instead of editing
`errors/404.blade.php` directly — keeps the fix entirely inside this module (consistent
with this whole build's "never touch FreeScout core" discipline) and avoids a core-file
edit that would silently get wiped by any FreeScout self-update, or not even survive this
module's *own* reinstall (our zip only ever touches `Modules/MSTeamsFS/`).

**Middleware registration — verified the mechanism, no in-repo precedent to copy**: no
existing FreeScout module here has ever registered custom middleware, so there was no
established pattern to mirror. Confirmed instead that the standard, documented Laravel
mechanism exists and is exactly what's needed: `Illuminate\Foundation\Http\Kernel::prependMiddleware()`
(verified present, public, in the actual vendored `Kernel.php`), called from
`MSTeamsFSServiceProvider::boot()`. Registered as **global** middleware, not scoped to the
`web` middleware group — a genuinely unmatched route never runs group middleware at all,
only global middleware sees every response regardless of whether a route matched.
`prependMiddleware` (not `push`) makes it the outermost layer, so its after-`$next()` logic
runs last and sees the truly final response.

New file: `Http/Middleware/InjectTeams404ReloadScript.php`. On every response: only acts if
status is 404 **and** `Content-Type` is HTML — everything else passes through completely
untouched. Injects a small inline `<script>` just before `</body>`, reusing the same
reload-once + unsaved-content guard logic already built for the 1.2.6 experiment (skips the
reload if any visible Summernote editor/textarea/input still has content — kept even though
a genuine 404 page realistically never has one, per instruction that a wrong assumption
there is worse than a redundant check). One-shot guard via `sessionStorage` keyed to the
exact URL, so a reload that lands on the same stale 404 again doesn't loop — falls through
to the normal FreeScout 404 with its own Homepage link instead. Same Teams-iframe-only guard
(`window.self !== window.top`) as the rest of this file. No `document.title` detection
needed this time — we already know it's a 404 at the point of injection.

Added the nonce (`Helper::cspNonceAttr()`) to the injected script defensively, even though
CSP does not appear to be enforced on this error page at all right now (no CSP meta tag on
it, and FreeScout core's own CSP header line in `ResponseHeaders.php` is commented out) —
cheap to include, matches the pattern already used elsewhere in this module.

**Verified without touching the live install**: a permission classifier blocked pushing
this new file to the live install for a direct functional test (reasonable — that's not the
established deploy path here, DirectAdmin manual install is). Instead built an isolated
test harness (stub `\Helper`, a minimal fake Response object, the real middleware class
loaded unmodified) covering five cases: 404 HTML with `</body>` (must inject), 200 HTML,
404 JSON, 404 HTML with no `</body>` tag, and 403 HTML (all four must NOT inject). All five
passed.

Also found and ruled out, while investigating: a second, orphaned Teams SSO implementation
(`Modules/Http/routes.php` + `Modules/Resources/views/teams-entry.blade.php`, referencing
`Modules\MSTeamsSso\...\TeamsSsoController`) sitting outside any module folder — looked like
it could explain the bug bypassing our controller. Confirmed dead via a live `curl` to
`/teams-entry` (plain 404, not actually registered/loaded). Not the cause — worth a cleanup
pass separately sometime.

Still framed as experimental — this is a much more targeted mechanism than 1.2.6 (detects
what the failure actually looks like rather than guessing when it happens, so it shouldn't
depend on any particular lifecycle event firing correctly on this specific WebView), but it
hasn't been confirmed live yet either.

### 1.2.6 (2026-07-23) — 🧪 EXPERIMENT, NOT A CONFIRMED FIX
Targets the Teams-Android stale-page-on-resume bug (reopening Teams Android after it's
been killed/frozen shows FreeScout's generic 404 inside the tab) via `msteamsfs.js`, since
debug logging already confirmed this never reaches `TeamsSsoController::handoff()` at all —
something serves a stale page before our SSO code runs. Teams' own official app-lifecycle
resume handler (`app.lifecycle.registerOnResumeHandler`) explicitly does not support Android
per Microsoft's own docs (Desktop/iOS only), so that channel isn't available here.

Added a `pageshow` listener: on `event.persisted === true`, force `window.location.reload()`.
**Verified before implementing, not from memory:**
- `pageshow`/`event.persisted` is current and correct — MDN: "Baseline widely available"
  since 2015, unaffected by Chromium's Android-support gap above (different, unrelated API).
  MDN explicitly lists **"restoring a frozen page on mobile OSes"** as one of the scenarios
  that fires this event — this is a documented, intended signal for exactly this class of
  bug, not a side-channel hack.
- **False-positive risk, specifically researched per the concern about interrupting an
  in-progress draft**: `pageshow`/`pagehide` are tied to actual freeze/bfcache-restore
  transitions, not visibility changes — confirmed via MDN ("won't fire when you minimize
  the window or switch tabs") and the WICG Page Lifecycle spec directly (FROZEN requires
  "system initiated CPU suspension", explicitly **not** merely losing focus; "simply
  switching tabs does not automatically trigger FROZEN"). So ordinary Teams-internal tab
  switching, away from and back to the FreeScout tab, should not trigger this at all.
- **Residual uncertainty, being honest about it**: none of MDN/web.dev/the WICG spec give
  Android-**WebView**-specific (as opposed to Chrome-browser) confirmation — Teams Android
  embeds a WebView component, not Chrome itself, and its bfcache/freeze implementation could
  plausibly differ; a Chromium bug referencing `persisted` being "incorrect" in some
  scenario exists but is sign-in-gated, so I couldn't confirm its nature or resolution
  status; and freeze timing is explicitly resource-pressure-driven ("N minutes" depending
  on device constraints), not a fixed/guaranteed duration.
- Given that residual uncertainty, **shipped the safer variant anyway rather than a pure
  blanket reload**: skips the forced reload entirely if any visible Summernote editor,
  textarea, or text input still has unsaved content — protects against the concrete harm
  (silently discarding an in-progress reply/note/Kanban card) regardless of how reliable
  the `persisted` signal turns out to be on this specific WebView.
- Scoped to inside the Teams iframe only (same top-level guard as the rest of this file) —
  a forced reload on a normal desktop browser tab restored from bfcache would defeat the
  entire point of bfcache for everyone else.

**Notable side-finding, investigated and ruled out**: found a second, separate, undocumented
Teams SSO implementation — `Modules/Http/routes.php` and `Modules/Resources/views/teams-entry.blade.php`,
sitting at a bare top-level path outside any proper module folder, registering `/teams-entry`,
`/teams-sso-login`, `/teams-fallback` against a `Modules\MSTeamsSso\Http\Controllers\TeamsSsoController`
class. Looked like it could explain why the bug never reaches our `handoff()`. **Confirmed dead**:
live `curl` to `/teams-entry` returns a plain 404 — these routes aren't actually registered/
loaded by anything right now. Orphaned leftover, likely from a past MSTeamsSso
install/uninstall — not the cause, but worth a cleanup pass separately sometime.

**Point 5 (registerOnResumeHandler/registerBeforeSuspendOrTerminateHandler for iOS/Desktop) —
not implemented, flagging why rather than shipping something inert**: this requires the
TeamsJS SDK itself to be loaded on the page calling it. Checked directly — **the SDK is never
loaded on actual FreeScout pages at all**, only on the CFS backend's separate `/teams/entry`
page (a different origin entirely). `msteamsfs.js`'s existing `typeof microsoftTeams !== 'undefined'`
check already handles this gracefully (falls through to its `else` branch), but it means the
SDK genuinely isn't present by the time a real FreeScout page loads. Adding a resume handler
to `msteamsfs.js` as asked would silently never fire — worse than not adding it, since it'd
look like coverage that isn't there. Loading the SDK on FreeScout pages too is a bigger,
separate task than "add a handler" — deferring per your own "secondary, non-urgent, don't
block the Android fix" framing, flagging clearly rather than shipping a no-op.

### 1.2.5 (2026-07-23) — ⚠️ TEMPORARY DEBUG BUILD, NOT A FIX (still)
Adds a `user=` field to every existing `[MSTeamsFS-DEBUG]` line from 1.2.4, so a captured
occurrence can be tied to a specific person. The two entries already captured under 1.2.4
(both a clean fallback to `/`) can't explain the 404 Rutger actually saw — this closes off
one of the two live theories: without a user identifier we couldn't tell whether those
captures were even Rutger's device. `$debugUser` tracks the best identifier available at
each point in the flow: `unknown (token not parsed yet)` before the token is decoded (there
is genuinely nothing to identify before that point), the token's `email` once the payload
is parsed, `email (user_id=N)` once the FreeScout account lookup succeeds. Still no page/
conversation-view log exists anywhere accessible to cross-reference "what was I looking at
before backgrounding" — checked directly: `config/activitylog.php` is configured but its
`activity_log` table doesn't exist on this install (package never migrated), so nothing is
being recorded there at all; the closest available proxy is `notifications.read_at`
(updated when a conversation with an unread notification is viewed — `ConversationsController@view`),
which is indirect and only covers conversations that had a notification, not a general view
log. Same discipline as 1.2.4: this is still temporary, still to be fully removed once
root-caused, not layered fix-on-fix indefinitely.

### 1.2.4 (2026-07-23) — ⚠️ TEMPORARY DEBUG BUILD, NOT A FIX
Rutger hit the same mobile 404 symptom again after v1.2.3, meaning the `Conversation::find()`
fix from 1.2.3 either doesn't cover the actual mechanism, or the real cause is something
else in this flow entirely. Since 404s are never written to Laravel's own log (confirmed —
`NotFoundHttpException` is in Laravel's `internalDontReport` list) and there's no access
log on this hosting account, this version adds temporary `\Log::error()` debug lines
throughout `TeamsSsoController::handoff()` — prefixed `[MSTeamsFS-DEBUG]` — logging: whether
the route is even reached and with a token; which bail-out point is hit, if any (license,
backend secret, missing/malformed token, signature mismatch, invalid payload, expired token,
domain not allowed, user not found); the raw `conversationId` value from the token payload;
whether it passed `ctype_digit`; whether `Conversation::find()` returned a result; and the
final redirect URL chosen. Uses `Log::error()`, not `Log::info()`, deliberately — this
install's `APP_LOG_LEVEL` defaults to `error` (no `.env` override) and info-level entries
are silently dropped here, confirmed directly via a live sanity check before shipping this.

**REMOVE ALL OF THIS once the real mechanism is confirmed from the next occurrence's log
output.** Do not let this linger as permanent logging — same discipline as any other
temporary debug instrumentation added mid-investigation.

### 1.2.3 (2026-07-23)
- Fixed: stale/deleted conversation ID in a cached Teams deep link could show FreeScout's generic 404 page instead of falling back to the mailbox dashboard.

### 1.2.2 (2026-07-20)
- Fixed msteamsfs.js click interceptor incorrectly hijacking FreeScout-native modal-trigger links (e.g. Kanban's Add Card), causing a 405 error. Interceptor now skips any element with `[data-trigger="modal"]`, letting FreeScout's own modal JS handle those clicks untouched. Original `target="_blank"` search-form-escape fix from v1.0.2 remains intact and unaffected.

### 1.0.2 (2026-06-11)
- Add form submission interception: `form[target="_blank"]` submissions (e.g. FreeScout search) are
  caught and routed through `handleLink()` so they stay inside the Teams iframe
- Register `msteamsfs.js` via the `javascripts` Eventy filter (was missing since v1.0.0)

### 1.0.1 (2026-06-11)
- Fix: redirect after login now goes to `/` (FreeScout root) instead of `/mailboxes`
- Fix: license gate added at top of `handoff()` — returns 403 if no active license
- Fix: module icon re-copied from MSTeamsSso to ensure it renders on Manage → Modules

### 1.0.0 (2026-06-11)
- Initial build
- Receives ManagedFreeScout HMAC handoff token at GET /teams-sso-handoff
- Verifies HMAC-SHA256 signature and millisecond expiry
- Auth::login() + redirect to / (corrected to / in v1.0.1)
- Settings: Backend Secret + Allowed Domains
- No Azure JWT/JWKS code — backend handles all Azure token validation

---

## Session log

| Date | What was done | What's next |
|---|---|---|
| 2026-06-11 | Initial build (v1.0.0): scaffolded from MSTeamsSso, JWT/JWKS code stripped, handoff() receiver built (HMAC verify + ms expiry + Auth::login()), settings page (Backend Secret + Allowed Domains), CSP hooks, license system, auto-update URLs wired to GitHub; v1.0.0 released to ManagedFreeScout/msteamsfs; manually installed on support.stackpros.io, Backend Secret configured, full end-to-end flow confirmed ✅; v1.0.1: license gate at top of handoff(), redirect to / fix, icon fix; auto-update confirmed working ✅ | Create DLM product on stackpros.io, assign product_id in Config/config.php; build customer registration flow; submit Teams app to Microsoft store; design proper MSTeamsFS icon |
| 2026-07-23 | v1.2.3: defensive fix for a Teams-mobile-reported symptom (FreeScout's generic 404 page appearing after the app resumed from inactivity) — investigation (logs: 404s are never written to storage/logs by Laravel's own exception handler design, confirmed via Handler.php; no access log available on this hosting account either) narrowed to a code-level hypothesis: a cached Teams deep link replaying a stale conversationId on resume, hitting `ConversationsController@view`'s `Conversation::findOrFail($id)`, which Laravel auto-converts to a 404 for a since-deleted/merged conversation. `handoff()`'s redirect now checks `\App\Conversation::find($conversationId)` is non-null (not just numeric) before redirecting to the specific conversation; falls back to `/` otherwise, same as the existing missing/invalid-ID paths. Confirmed no other blind-trust ID pattern exists elsewhere in this controller. Verified `Conversation::find()` returns plain `null` (not an exception) for a nonexistent ID via a live bootstrapped check. Not log-confirmed as the actual mechanism (couldn't be, for the reason above) — applied as a small, safe, defensive fix regardless. | Rutger: manual install cycle on support.stackpros.io; no live way to reproduce/confirm the original symptom is gone, since it was itself intermittent and only reachable via real Teams-mobile resume behavior. |
| 2026-07-23 | v1.2.4 (same day): Rutger hit the same 404 symptom again after installing 1.2.3, so the fix doesn't cover the real mechanism (or it's a different cause entirely). Added temporary `[MSTeamsFS-DEBUG]` logging throughout `handoff()` — every bail-out point, the raw conversationId, ctype_digit result, `Conversation::find()` result, and the final redirect chosen. Deliberately used `Log::error()` instead of the more semantically obvious `Log::info()`: confirmed live via a bootstrapped sanity check that `Log::info()` calls are silently dropped on this install (`APP_LOG_LEVEL` defaults to `error`, no `.env` override — same issue already diagnosed once before on the MFSEssentials side of this project), so `Log::info()` here would have shipped a debug build that captures nothing. `Log::error()` confirmed actually written to `storage/logs/laravel-*.log` before packaging. | Rutger: install 1.2.4, wait for the next occurrence, grep `storage/logs` for `MSTeamsFS-DEBUG`, send the output back. Once root-caused: remove all of this logging in the following version — it must not become permanent. |
| 2026-07-23 | Ran the requested grep on 1.2.4's log: two `handoff()` invocations captured, both clean successful logins with no conversationId at all and a plain `/` redirect — neither shows the 404. Since we had no way to tell whose device those were, added `user=` to all 16 existing debug lines (v1.2.5) — email as soon as the token payload is parsed, `email (user_id=N)` once the FreeScout account lookup succeeds, `unknown (token not parsed yet)` for the handful of earlier bail-out points where nothing is decodable yet. Also checked (per Rutger's ask) whether anything logs page/conversation views to cross-reference against a handoff() capture: no — `activity_log` table doesn't exist despite `config/activitylog.php` being present (package configured, never migrated); closest proxy is `notifications.read_at`, which is indirect and incomplete. | Rutger: install 1.2.5, wait for the next occurrence, check whether the captured `user=` matches Rutger's own account — if not, that alone explains the earlier clean captures and narrows the search to the correct device/session. |
| 2026-07-23 | **Case closed** (v1.2.6–1.2.9 recap + resolution): 1.2.6 tried `pageshow`/`persisted` in `msteamsfs.js` — didn't work, Rutger reproduced the same symptom. 1.2.7 tried a 404-detecting global middleware instead (`InjectTeams404ReloadScript`) — found and fixed two real bugs in it along the way: 1.2.8 fixed the middleware never seeing a 404 at all because `NotFoundHttpException` is thrown, not returned, past `$next()`; 1.2.9 fixed a second silent failure where the Content-Type header check was reading an empty value due to `Response::prepare()` timing. Verified 1.2.9 live via direct `curl` — script genuinely injects on a real 404, confirmed twice on independent URLs, confirmed absent on a normal page. Rutger still reproduced the "Sorry" page after 1.2.9. Checked `[MSTeamsFS-DEBUG]` logs and `msteamsfs_user_links.updated_at` around the exact reproduction window — `handoff()` had not fired even once in over an hour, decisive evidence no fresh request from that reproduction ever reached this module at all. **Root cause found externally**: the pinned Teams-Android app was the old single-tenant MSTeamsSso app pointing at a dead route (retired during Rutger's own single-tenant cleanup), not the new multi-tenant app — confirmed by Rutger directly. Not a module bug; no code fix was possible for this specific symptom. v1.3.0: stripped all `[MSTeamsFS-DEBUG]`/`$debugUser` debug code (root cause found, per this project's own discipline), kept the middleware/pageshow-listener/Conversation::find()/modal-interceptor fixes (all independently real), fixed long-stale README version drift, shipped via the full GitHub release workflow instead of a manual zip. | Rutger: confirmed resolution already applied on his end (old app blocked in Teams admin center) — nothing further needed for the mobile symptom. Separate, future cleanup-pass item: delete the now-confirmed-dead orphaned single-tenant SSO route/view on the FreeScout host. |
