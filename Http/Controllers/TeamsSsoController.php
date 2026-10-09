<?php

namespace Modules\MSTeamsFS\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class TeamsSsoController extends Controller
{
    public function handoff(Request $request)
    {
        // License gate — checked before any token processing
        $isLicensed = \Modules\MSTeamsFS\Services\LicenseService::isLicensed();
        if (!$isLicensed) {
            return response('MFS Connect license not active.', 403);
        }

        $backendSecret = (string)(\Option::get('msteamsfs.backend_secret') ?? '');
        if (empty($backendSecret)) {
            return $this->errorResponse('Module not configured. Please enter the Backend Secret in Settings → MSTeams FS.', 403);
        }

        $tokenEncoded = $request->query('token', '');
        if (empty($tokenEncoded)) {
            return $this->errorResponse('Missing token.', 401);
        }

        // Preserve the EXACT string as received, before any mutation -- this is
        // what the hub hashed at issuance (Node's base64url encoding never pads),
        // and it's what must be sent to /teams/consume-handoff later. Hotfix,
        // card #232: v1.5.4 padded $tokenEncoded in place below and then sent the
        // now-PADDED string to consume-handoff, which never matched the hub's
        // stored (unpadded) hash -- every login failed as "already used/expired."
        $rawTokenForHub = $tokenEncoded;

        // Decode the base64url outer envelope.
        // Token format: base64url(JSON.stringify({ payload: payloadString, sig: hmacHex }))
        $remainder = strlen($tokenEncoded) % 4;
        if ($remainder) {
            $tokenEncoded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($tokenEncoded, '-_', '+/'));

        if ($decoded === false || $decoded === '') {
            return $this->errorResponse('Invalid token.', 401);
        }

        $outer = json_decode($decoded, true);
        if (!is_array($outer) || !isset($outer['payload'], $outer['sig'])) {
            return $this->errorResponse('Invalid token.', 401);
        }

        $payloadString = $outer['payload'];
        $sig           = $outer['sig'];

        // Verify HMAC-SHA256 signature
        $expected = hash_hmac('sha256', $payloadString, $backendSecret);
        if (!hash_equals($expected, $sig)) {
            return $this->errorResponse('Invalid token.', 401);
        }

        // Parse inner payload
        $payload = json_decode($payloadString, true);
        if (!is_array($payload) || !isset($payload['email'], $payload['exp'])) {
            return $this->errorResponse('Invalid token.', 401);
        }

        $email = $payload['email'];
        $exp   = (int) $payload['exp']; // milliseconds since epoch
        // tid/oid are additive (added 2026-07-15) — optional so a token issued by an
        // old backend build during a rolling deploy still logs the agent in; they're
        // only needed for the notification-linking feature, not for auth itself.
        $tid   = $payload['tid'] ?? null;
        $oid   = $payload['oid'] ?? null;
        // conversationId is additive too (added 2026-07-17) — only present when
        // the Teams tab was opened via an Activity Feed deep link. Validated as
        // a positive integer before ever touching the redirect URL.
        $conversationId = $payload['conversationId'] ?? null;
        if ($conversationId !== null && !ctype_digit((string) $conversationId)) {
            $conversationId = null;
        }

        // Check expiry (exp is in ms, microtime(true) gives seconds as float)
        if ($exp <= (int) (microtime(true) * 1000)) {
            return $this->errorResponse('Token expired. Please reload the Teams tab to sign in again.', 401);
        }

        // Allowed domains whitelist
        $allowedDomains = trim((string)(\Option::get('msteamsfs.allowed_domains') ?? ''));
        if (!empty($allowedDomains)) {
            $atPos = strpos($email, '@');
            $emailDomain = $atPos !== false ? strtolower(substr($email, $atPos + 1)) : '';
            $allowed = array_filter(array_map('trim', explode(',', strtolower($allowedDomains))));
            if (!empty($allowed) && !in_array($emailDomain, $allowed, true)) {
                return $this->errorResponse('Access denied.', 403);
            }
        }

        // Look up FreeScout user by email
        $user = \App\User::where('email', $email)->first();

        // A user an admin disabled or deleted must never get in via Teams -- and is never
        // re-created automatically either (card #247). Previously this was not checked.
        if ($user && !$user->isActive()) {
            \Log::warning("MSTeamsFS: Teams sign-in refused for inactive FreeScout user {$user->id} ({$email}), status={$user->status}");
            return $this->errorResponse('Access denied. This FreeScout account is disabled. Please contact your administrator.', 403);
        }

        // No user yet: either refuse (default) or create it after all other checks
        // below have passed, including the single-use hub check (card #247, 1.6.0).
        $createUser = false;
        if (!$user) {
            if (!\Modules\MSTeamsFS\Services\UserProvisioner::isEnabled()) {
                return $this->errorResponse(
                    'Access denied. No FreeScout account found for ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.',
                    403
                );
            }
            if (\App\User::mailboxEmailExists($email)) {
                return $this->errorResponse('Access denied. This email address is used by a mailbox and cannot be a user.', 403);
            }
            $createUser = true;
        }

        // Identity pinning (card #232, F8). Without this, sign-in is ultimately
        // "whichever Microsoft account currently has this email" -- if an email
        // address is ever reassigned, or a mailbox compromised, a different real
        // person's Microsoft account would silently take over access to this
        // FreeScout user on their very next Teams sign-in, with nothing here to
        // notice or flag it. Once a user has signed in via Teams before, their
        // account is pinned to that specific Microsoft identity (oid) going
        // forward. Skipped entirely when this token has no oid (an
        // already-accepted "not fatal" gap from the original tid/oid rollout,
        // 2026-07-15) -- pinning can't be checked or established without one.
        if ($oid && $user) {
            $existingLink = \Modules\MSTeamsFS\Entities\TeamsUserLink::where('user_id', $user->id)->first();
            if ($existingLink && $existingLink->oid && $existingLink->oid !== $oid) {
                \Log::warning("MSTeamsFS: identity mismatch — FreeScout user {$user->id} ({$email}) previously signed in as oid={$existingLink->oid}, now presenting oid={$oid}. Rejecting.");
                return $this->errorResponse('Access denied: this FreeScout account is linked to a different Microsoft identity. Please contact your administrator.', 403);
            }
        }

        // Consume the one-time handoff nonce on the hub (card #232, F3). This is
        // the actual single-use enforcement -- everything checked above (HMAC
        // signature, expiry) stays individually valid for the whole 60-second
        // window, so a captured handoff URL was previously replayable that whole
        // time. Placed as the LAST check, right before granting a session, so a
        // token that would be rejected for any other reason (unknown user, etc.)
        // isn't burned for nothing.
        $backendUrl = rtrim((string) config('msteamsfs.backend_url', ''), '/');
        if (empty($backendUrl)) {
            \Log::error('MSTeamsFS: msteamsfs.backend_url not configured — cannot verify handoff token, failing closed');
            return $this->errorResponse('Module misconfigured. Please contact support.', 500);
        }
        try {
            $consumeResponse = (new \GuzzleHttp\Client())->request(
                'POST',
                $backendUrl . '/teams/consume-handoff',
                array_merge(\Helper::setGuzzleDefaultOptions(['timeout' => 5]), [
                    'headers'     => ['Content-Type' => 'application/json'],
                    'json'        => ['token' => $rawTokenForHub],
                    'http_errors' => false,
                ])
            );
            $consumeBody = json_decode((string) $consumeResponse->getBody(), true);
            // Card #248 (1.6.1): the hub now occupies the license seat HERE (only for people this
            // module is about to log in), so a full license is reported by consume-handoff.
            if ($consumeResponse->getStatusCode() === 403 && ($consumeBody['error'] ?? '') === 'no_seats_available') {
                \Log::warning('MSTeamsFS: Teams sign-in refused, no license seats available — email=' . $email);
                return $this->errorResponse('No more license seats are available. Ask your administrator to free up a seat or buy an additional one, then reload the Teams tab.', 403);
            }
            // Card #259: the hub refuses a tenant whose license invAIse reports inactive or
            // unknown, or that has no license linked at all.
            if ($consumeResponse->getStatusCode() === 403 && ($consumeBody['error'] ?? '') === 'license_inactive') {
                \Log::warning('MSTeamsFS: Teams sign-in refused, license not active on the hub — email=' . $email);
                return $this->errorResponse('Your organization\'s MFS Connect license is not active. Ask your administrator to renew it, then reload the Teams tab.', 403);
            }
            if ($consumeResponse->getStatusCode() !== 200 || empty($consumeBody['consumed'])) {
                \Log::warning('MSTeamsFS: handoff token rejected by hub (already used, expired, or unknown) — possible replay, email=' . $email);
                return $this->errorResponse('This sign-in link has already been used or has expired. Please reload the Teams tab to sign in again.', 401);
            }
        } catch (\Exception $e) {
            // Deliberately FAILS CLOSED, unlike most other remote checks in this
            // module (license/seats fail open for availability). This one IS the
            // security control being added -- silently skipping it on a network
            // hiccup would defeat the point. If the hub is genuinely unreachable,
            // sign-in is unavailable until it's back, same as any other outage.
            \Log::error('MSTeamsFS: consume-handoff request failed — ' . $e->getMessage());
            return $this->errorResponse('Could not verify sign-in with the ManagedFreeScout backend. Please try again in a moment.', 503);
        }

        // Auto-create the missing user now that the token is verified AND consumed (card #247).
        if ($createUser) {
            try {
                $user = \Modules\MSTeamsFS\Services\UserProvisioner::create($email, $payload['name'] ?? null);
            } catch (\Exception $e) {
                \Log::error('MSTeamsFS: auto-creating FreeScout user failed for ' . $email . ' — ' . $e->getMessage());
                return $this->errorResponse('Your FreeScout account could not be created automatically. Please contact your administrator.', 500);
            }
        }

        // Capture the AAD identity for this login so conversation-event notifications
        // can later be targeted at the right Teams user via Graph's activity feed API.
        if ($tid && $oid) {
            \Modules\MSTeamsFS\Entities\TeamsUserLink::linkUser($user->id, $tid, $oid);
        }

        // Log the agent in and redirect to FreeScout home — or the specific
        // conversation, if this login came from an Activity Feed deep link.
        Auth::login($user, true);

        // A cached Teams deep link (e.g. mobile resuming a suspended tab) can replay
        // a conversationId that's since been deleted or merged. find() (not
        // findOrFail()) + a null check here means that only ever degrades to the
        // safe default below, instead of FreeScout's own confusing generic 404 page.
        if ($conversationId && \App\Conversation::find($conversationId)) {
            return redirect('/conversation/' . $conversationId);
        }

        return redirect('/');
    }

    private function errorResponse(string $message, int $status)
    {
        return response()->view('msteamsfs::handoff-error', ['message' => $message], $status);
    }
}
