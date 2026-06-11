<?php

namespace Modules\MSTeamsFS\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class TeamsSsoController extends Controller
{
    public function handoff(Request $request)
    {
        $backendSecret = (string)(\Option::get('msteamsfs.backend_secret') ?? '');
        if (empty($backendSecret)) {
            return $this->errorResponse('Module not configured. Please enter the Backend Secret in Settings → MSTeams FS.', 403);
        }

        $tokenEncoded = $request->query('token', '');
        if (empty($tokenEncoded)) {
            return $this->errorResponse('Missing token.', 401);
        }

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
        if (!$user) {
            return $this->errorResponse(
                'Access denied. No FreeScout account found for ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.',
                403
            );
        }

        // Log the agent in and redirect to mailboxes
        Auth::login($user, true);

        return redirect('/mailboxes');
    }

    private function errorResponse(string $message, int $status)
    {
        return response()->view('msteamsfs::handoff-error', ['message' => $message], $status);
    }
}
