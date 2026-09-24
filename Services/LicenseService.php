<?php

namespace Modules\MSTeamsFS\Services;

use Modules\MSTeamsFS\Models\MSTeamsFSLicense;
use IdeoLogix\DigitalLicenseManagerClient\Service as DLMService;

class LicenseService
{
    protected $dlmClient;
    protected $licenseServerUrl;

    public function __construct()
    {
        // Initialize the client only when needed to prevent issues during route resolution
        $this->dlmClient = null;
        $this->licenseServerUrl = config("msteamsfs.license_server_url", 'https://your-wordpress-site.com');
    }

    // ════════════════════════════════════════════════════════════════════
    // invAIse (current provider) — replaces DLM as of the 2026-07 migration.
    // See PRODUCT.md/README.md "MSTeamsFS DLM -> invAIse migration" for context.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Maps invAIse's status vocabulary to a human-readable message — the
     * direct replacement for mapDLMErrors()/DLM's own error text, since
     * invAIse's status strings are already normalized (no free-text parsing
     * needed, unlike DLM's error codes).
     */
    private function invaiseStatusMessage(string $status): string
    {
        switch ($status) {
            case 'active':
                return __('License is active.');
            case 'expired':
                return __('License has expired.');
            case 'suspended':
                return __('License has been suspended.');
            case 'not_activated_for_domain':
                return __('License is valid but has not been activated for this domain.');
            case 'not_found':
                return __('License key not found.');
            case 'no_activations_left':
                return __('No activations remaining for this license.');
            default:
                return __('License validation failed.');
        }
    }

    /**
     * Performs the actual signed HTTP call to invAIse's license API. Uses
     * GuzzleHttp\Client directly (not the Http:: facade) — same pattern
     * already established in MSTeamsFSServiceProvider's Teams notify call,
     * relying on FreeScout core's bundled Guzzle rather than adding a
     * module-level dependency.
     *
     * Returns ['status' => int, 'data' => array] on a completed HTTP
     * round-trip (regardless of 2xx/4xx — invAIse returns a normal JSON body
     * even for 404 "not_found"), or null if the request could not be made at
     * all (credentials missing, network/connection failure).
     */
    protected function invaiseRequest(string $endpoint, array $body): ?array
    {
        $baseUrl   = rtrim(config('msteamsfs.invaise_base_url', 'https://acc.invaise.com'), '/');
        $apiKey    = config('msteamsfs.invaise_api_key', '');
        $apiSecret = config('msteamsfs.invaise_api_secret', '');

        if (empty($apiKey) || empty($apiSecret)) {
            \Log::error(__('invAIse API credentials not configured (msteamsfs.invaise_api_key / msteamsfs.invaise_api_secret) — cannot reach license server.'));
            return null;
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->request('POST', $baseUrl . $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey . ':' . $apiSecret,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => $body,
                'http_errors' => false, // 404/4xx still carry a real JSON body from invAIse — decode it ourselves
            ]);

            $status = $response->getStatusCode();
            $data   = json_decode((string) $response->getBody(), true);
            if (!is_array($data)) {
                \Log::error(__('invAIse API returned a non-JSON response (status :status)', ['status' => $status]));
                return null;
            }

            return ['status' => $status, 'data' => $data];
        } catch (\Exception $e) {
            \Log::error(__('invAIse API request failed: ') . $e->getMessage());
            return null;
        }
    }

    /**
     * Pure mapping from invAIse's raw response body to this module's internal
     * result shape — deliberately has NO side effects (no DB writes, no HTTP
     * calls) so it can be tested in isolation with mocked payloads covering
     * every status value, product mismatch, and seats present/absent.
     *
     * $rawResult is the ['status'=>, 'data'=>] shape from invaiseRequest(),
     * or null if the request itself couldn't be made.
     */
    private function mapInvaiseResponse(?array $rawResult): array
    {
        if ($rawResult === null) {
            return [
                'success' => false,
                'valid'   => false,
                'status'  => 'error',
                'message' => __('Could not reach invAIse license server.'),
            ];
        }

        $data    = $rawResult['data'];
        $valid   = (bool) ($data['valid'] ?? false);
        $status  = $data['status'] ?? 'error';

        // Product-name comparison removed (card #232, follow-up to F9). It
        // compared invAIse's response against a hardcoded product NAME -- a
        // marketing label meant to change freely (renames, new tiers, replaced
        // plans) -- and would have silently rejected every real customer's
        // valid license the moment the actual priced product's name ever
        // differed from whatever string happened to be baked into this file.
        // invAIse's /validate and /activate are already scoped correctly for
        // what actually matters here, verified directly against invAIse's own
        // source rather than assumed: license lookups are keyed to this
        // tenant's own license_api_key/secret; activation count is enforced
        // with a row lock (SELECT ... FOR UPDATE OF lk), closing the obvious
        // race; and a cancelled Stripe subscription cascades to
        // license_keys.status='suspended' via the real, wired-up
        // customer.subscription.updated/.deleted webhook handler. Whether a
        // given key was issued for "the right" product is invAIse's own
        // checkout/admin concern, not something this module should
        // re-litigate by comparing a renamable string.

        $result = [
            'success'           => $valid,
            'valid'             => $valid,
            'status'            => $status,
            'message'           => $this->invaiseStatusMessage($status),
            'data'              => $data,
            'activations_used'  => $data['activations_used']  ?? null,
            'activations_limit' => $data['activations_limit'] ?? null,
            'expires_at'        => $data['expires_at'] ?? null,
        ];

        // Deliberately omit seats_purchased/seats_occupied entirely when
        // absent (rather than nulling them in) — matches invAIse's own
        // validate() contract of only including these fields when a seats
        // entitlement actually exists for the license. A future
        // settings-page seats display (not built in this task, per the
        // original handoff's explicit sequencing) can check
        // array_key_exists() to know whether seats apply at all.
        if (array_key_exists('seats_purchased', $data)) {
            $result['seats_purchased'] = $data['seats_purchased'];
        }
        if (array_key_exists('seats_occupied', $data)) {
            $result['seats_occupied'] = $data['seats_occupied'];
        }

        return $result;
    }

    /**
     * Persists a validate/activate result to the local modules_licenses
     * cache — same table/shape getLicenseStatus() reads, unchanged.
     */
    private function persistInvaiseResult(string $licenseKey, string $domain, array $mapped): void
    {
        if (!isset($mapped['data'])) {
            return; // error/unreachable case — nothing meaningful to cache
        }

        $expiresAt = null;
        if (!empty($mapped['expires_at'])) {
            try {
                $expiresAt = \Carbon\Carbon::parse($mapped['expires_at']);
            } catch (\Exception $e) {
                $expiresAt = null;
            }
        }

        $updateData = [
            'license_key'   => $licenseKey,
            'is_valid'      => $mapped['valid'],
            'status'        => $mapped['status'],
            'domain'        => $domain,
            'response_data' => $mapped['data'],
        ];
        if ($expiresAt) {
            $updateData['expires_at'] = $expiresAt;
        }

        $license = MSTeamsFSLicense::firstOrCreate([], ['license_key' => $licenseKey]);
        $license->update($updateData);
    }

    protected function activateLicenseViaInvaise($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        // Same FreeScout-level guard the DLM path had — unrelated to which
        // license backend is in use, so preserved here too.
        $existing = \DB::table('modules_licenses')
            ->where('license_key', $licenseKey)
            ->where('module_alias', '!=', 'msteamsfs')
            ->first();
        if ($existing) {
            return [
                'success' => false,
                'valid'   => false,
                'message' => __("This license key is already in use by the ':module' module.", ['module' => $existing->module_alias]),
            ];
        }

        $raw    = $this->invaiseRequest('/api/v1/license/activate', [
            'license_key' => $licenseKey,
            'domain'      => $domain,
        ]);
        $mapped = $this->mapInvaiseResponse($raw);

        if ($mapped['valid']) {
            $this->persistInvaiseResult($licenseKey, $domain, $mapped);
        }

        return $mapped;
    }

    protected function validateLicenseViaInvaise($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        $raw    = $this->invaiseRequest('/api/v1/license/validate', [
            'license_key' => $licenseKey,
            'domain'      => $domain,
        ]);
        $mapped = $this->mapInvaiseResponse($raw);

        // Persist regardless of valid/invalid here (unlike activate) — a
        // license that expired since last check needs its local cache
        // updated to reflect that, same as the old DLM validateLicense() did.
        if (isset($mapped['data'])) {
            $this->persistInvaiseResult($licenseKey, $domain, $mapped);
        }

        return $mapped;
    }

    protected function deactivateLicenseViaInvaise($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        $raw  = $this->invaiseRequest('/api/v1/license/deactivate', [
            'license_key' => $licenseKey,
            'domain'      => $domain,
        ]);

        if ($raw === null) {
            return [
                'success' => false,
                'message' => __('Could not reach invAIse license server.'),
            ];
        }

        $data    = $raw['data'];
        $success = (bool) ($data['success'] ?? false);

        if ($success) {
            $license = MSTeamsFSLicense::where('license_key', $licenseKey)->first();
            if ($license) {
                $license->update([
                    'is_valid'      => false,
                    'status'        => 'inactive',
                    'response_data' => $data,
                ]);
            }
        }

        return [
            'success'           => $success,
            'message'           => $success ? __('License deactivated successfully') : __('License deactivation failed'),
            'activations_used'  => $data['activations_used']  ?? null,
            'activations_limit' => $data['activations_limit'] ?? null,
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // DLM (legacy) — kept callable for rollback only. Not called unless
    // msteamsfs.license_provider is explicitly set to 'dlm'. Bodies below
    // are unchanged from before the migration, just renamed with a
    // ViaDLM suffix.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Get or initialize the DLM client with proper authentication
     */
    protected function getDLMClient()
    {
        if ($this->dlmClient === null) {
            $serverUrl = $this->licenseServerUrl;
            $consumerKey = config("msteamsfs.consumer_key", '');
            $consumerSecret = config("msteamsfs.consumer_secret", '');

            if (empty($consumerKey) || empty($consumerSecret)) {
                \Log::error(__("DLM Consumer credentials not configured properly"));
                return null;
            }

            try {
                $this->dlmClient = new DLMService($serverUrl, $consumerKey, $consumerSecret);
            } catch (\Exception $e) {
                \Log::error(__("Failed to initialize DLM Client: ") . $e->getMessage());
                return null;
            }
        }

        return $this->dlmClient;
    }

    /**
     * Validate the license key with the license server using DLM-PHP package
     */
    protected function validateLicenseViaDLM($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        try {
            // Use the DLMPRO client to validate the license
            $client = $this->getDLMClient();
            if (!$client) {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("DLM Client not initialized properly")
                ];
            }

            // Fetch local license to get the activation token
            $localLicense = MSTeamsFSLicense::where('license_key', $licenseKey)->first();
            $token = null;

            if ($localLicense && !empty($localLicense->response_data)) {
                $responseData = $localLicense->response_data;
                // Check for token in different possible locations
                if (isset($responseData['token'])) {
                    $token = $responseData['token'];
                } elseif (isset($responseData['data']['token'])) {
                    $token = $responseData['data']['token'];
                }
            }

            if (!$token) {
                 return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("Activation token not found locally. Cannot validate.")
                ];
            }

            $response = $client->licenses()->validate($token);

            // Check if response is an error
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Error) {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("License validation error: ") . $response->get_message()
                ];
            }

            // Check if response is successful
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Result && $response->is_success()) {
                $data = $response->get_data();

                // Check if the license is valid according to DLMPRO
                $isValid = false;
                $status = "invalid";
                $message = __("License validation failed");

                // DLMPRO typically returns 'token' field in validation response for success
                if (isset($data['token']) && !empty($data['token'])) {
                    $isValid = true;
                    $status = "active";
                    $message = __("License is valid");

                    // Extract license information if present
                    if (isset($data['license'])) {
                         $licenseInfo = $data['license'];

                         // Validate Product ID if present in validation response
                         $configProductId = config('msteamsfs.product_id');
                         if (isset($licenseInfo['product_id']) && !empty($configProductId)) {
                             if ((string)$licenseInfo['product_id'] !== (string)$configProductId) {
                                 // We have detailed license info and it mismatches
                                 // We treat this as a validation failure
                                  return [
                                    "success" => false,
                                    "valid" => false,
                                    "message" => __("License validation failed: Product ID mismatch. Expected: :expected, Got: :got", ['expected' => $configProductId, 'got' => $licenseInfo['product_id']])
                                ];
                             }
                         }
                    }

                    // Validation response usually just confirms the token is valid, possibly returning just the token object.
                    // But we should check whatever data comes back.
                } elseif (isset($data['code']) && isset($data['message'])) {
                    // Handle error response format
                    $status = $this->mapDLMErrors($data['code']);
                    $message = $data['message'];
                } elseif (isset($data['license']) && in_array($data['license'], ['valid', 'active', 'activated'])) {
                    $isValid = true;
                    $status = $data['license'];
                    $message = __("License is ") . $data['license'];
                }

                // Update or create license record
                // Note: validated response might not contain full license info like expiry if it's just a token validation

                $updateData = [
                    "license_key" => $licenseKey,
                    "is_valid" => $isValid,
                    "status" => $status,
                    "domain" => $domain,
                    "response_data" => $data
                ];

                // Update expires_at if available
                $expires_at = $this->parseExpiryDate($data);
                if ($expires_at) {
                    $updateData['expires_at'] = $expires_at;
                }

                $license = MSTeamsFSLicense::firstOrCreate([], [
                    "license_key" => $licenseKey,
                ]);

                $license->update($updateData);

                return [
                    "success" => $isValid,
                    "valid" => $isValid,
                    "status" => $status,
                    "message" => $message,
                    "data" => $data
                ];
            } else {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("License server returned invalid response")
                ];
            }
        } catch (\Exception $e) {
            return [
                "success" => false,
                "valid" => false,
                "message" => __("Error validating license: ") . $e->getMessage()
            ];
        }
    }

    /**
     * Activate the license with the license server using DLM-PHP package
     */
    protected function activateLicenseViaDLM($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        // Check if license is already used by another module
        $existing = \DB::table('modules_licenses')
            ->where('license_key', $licenseKey)
            ->where('module_alias', '!=', 'msteamsfs')
            ->first();

        if ($existing) {
            return [
                "success" => false,
                "valid" => false,
                "message" => __("This license key is already in use by the ':module' module.", ['module' => $existing->module_alias])
            ];
        }

        try {
            // Use the DLMPRO client to activate the license
            $client = $this->getDLMClient();
            if (!$client) {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("DLM Client not initialized properly")
                ];
            }

            $response = $client->licenses()->activate($licenseKey, [
                'domain' => $domain,
                'item_url' => $domain,
                'url' => $domain,
                'software' => config('msteamsfs.software'),
                'product_id' => config('msteamsfs.product_id')
            ]);

            // Check if response is an error
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Error) {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("License activation error: ") . $response->get_message()
                ];
            }

            // Check if response is successful
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Result && $response->is_success()) {
                $data = $response->get_data();

                // Handle successful activation response format
                if (isset($data['token']) && !empty($data['token'])) {
                    $isValid = true;
                    $status = "active";
                    $message = __("License activated successfully");

                    // Extract license information from the response
                    $licenseInfo = $data['license'] ?? [];
                    $expires_at = null;

                    if (!empty($licenseInfo)) {
                        // Validate Product ID
                        $configProductId = config('msteamsfs.product_id');
                        if (isset($licenseInfo['product_id']) && !empty($configProductId)) {
                             // Some implementations might return product_id as string or int
                             if ((string)$licenseInfo['product_id'] !== (string)$configProductId) {
                                  return [
                                     "success" => false,
                                     "valid" => false,
                                     "message" => __("License is not for this product. Expected: :expected, Got: :got", ['expected' => $configProductId, 'got' => $licenseInfo['product_id']])
                                 ];
                             }
                        }

                        // Set expiration date if available
                        if (isset($licenseInfo['expires_at'])) {
                            try {
                                $expires_at = \Carbon\Carbon::parse($licenseInfo['expires_at']);
                            } catch (\Exception $e) {
                                // If parsing fails, keep as null
                                $expires_at = null;
                            }
                        }

                        // Check explicit expiration flag
                        if (isset($licenseInfo['is_expired']) && $licenseInfo['is_expired']) {
                            $status = 'expired';
                            $isValid = false;
                        }
                    }
                } elseif (isset($data['code']) && isset($data['message'])) {
                    // Handle error response format
                    $isValid = false;
                    $status = $this->mapDLMErrors($data['code']);
                    $message = $data['message'];
                } else {
                    // Handle other response formats
                    $isValid = false;
                    $status = "inactive";
                    $message = __("Unexpected response format from license server: ") . json_encode($data);
                }

                // Update or create license record
                $license = MSTeamsFSLicense::firstOrCreate([], [
                    "license_key" => $licenseKey,
                ]);

                $res = $license->update([
                    "license_key" => $licenseKey,
                    "is_valid" => $isValid,
                    "status" => $status,
                    //"expires_at" => $expires_at,
                    "domain" => $domain,
                    "response_data" => $data
                ]);

                return [
                    "success" => $isValid,
                    "valid" => $isValid,
                    "status" => $status,
                    "message" => $message
                ];
            } else {
                return [
                    "success" => false,
                    "valid" => false,
                    "message" => __("License server returned invalid response for activation")
                ];
            }
        } catch (\Exception $e) {
            return [
                "success" => false,
                "valid" => false,
                "message" => __("Error activating license: ") . $e->getMessage()
            ];
        }
    }

    /**
     * Deactivate the license with the license server
     */
    protected function deactivateLicenseViaDLM($licenseKey, $domain = null)
    {
        if (!$domain) {
            $domain = request()->getHttpHost();
        }

        try {
            // Use the DLMPRO client to deactivate the license
            $client = $this->getDLMClient();
            if (!$client) {
                return [
                    "success" => false,
                    "message" => __("DLM Client not initialized properly")
                ];
            }

            // Fetch local license to get the activation token
            $localLicense = MSTeamsFSLicense::where('license_key', $licenseKey)->first();
            $token = null;

            if ($localLicense && !empty($localLicense->response_data)) {
                $responseData = $localLicense->response_data;
                // Check for token in different possible locations
                if (isset($responseData['token'])) {
                    $token = $responseData['token'];
                } elseif (isset($responseData['data']['token'])) {
                    $token = $responseData['data']['token'];
                }
            }

            if (!$token) {
                 return [
                    "success" => false,
                    "message" => __("Activation token not found locally. Cannot deactivate.")
                ];
            }

            $response = $client->licenses()->deactivate($token);

            // Check if response is an error
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Error) {
                return [
                    "success" => false,
                    "message" => __("License deactivation error: ") . $response->get_message()
                ];
            }

            // Check if response is successful
            if ($response instanceof \IdeoLogix\DigitalLicenseManagerClient\Http\Responses\Result && $response->is_success()) {
                $data = $response->get_data();

                $success = false;
                $message = __("License deactivation failed");

                if (isset($data['deactivated_at']) || (isset($data['success']) && $data['success'] === true)) {
                    $success = true;
                    $message = __("License deactivated successfully");

                    // Update license record
                    $license = MSTeamsFSLicense::where("license_key", $licenseKey)->first();
                    if ($license) {
                        $license->update([
                            "is_valid" => false,
                            "status" => "inactive",
                            "response_data" => $data
                        ]);
                    }
                } elseif (isset($data['code']) && isset($data['message'])) {
                    $message = $data['message'];
                } else {
                    $message = __("Unexpected response format from license server: ") . json_encode($data);
                }

                return [
                    "success" => $success,
                    "message" => $message
                ];
            } else {
                return [
                    "success" => false,
                    "message" => __("License server returned invalid response for deactivation")
                ];
            }
        } catch (\Exception $e) {
            return [
                "success" => false,
                "message" => __("Error deactivating license: ") . $e->getMessage()
            ];
        }
    }

    /**
     * Map Digital License Manager error codes to internal status
     */
    private function mapDLMErrors($error) {
        $error = strtolower($error);

        if (strpos($error, 'expired') !== false) {
            return 'expired';
        } elseif (strpos($error, 'invalid') !== false || strpos($error, 'not found') !== false) {
            return 'invalid';
        } elseif (strpos($error, 'depleted') !== false || strpos($error, 'no activations') !== false) {
            return 'no_activations_left';
        } elseif (strpos($error, 'disabled') !== false || strpos($error, 'revoked') !== false) {
            return 'disabled';
        } elseif (strpos($error, 'site_inactive') !== false) {
            return 'site_inactive';
        } else {
            return 'error';
        }
    }

    /**
     * Parse expiry date from DLMPRO response
     */
    private function parseExpiryDate($data) {
        // Different DLMPRO implementations may return expiry in different fields
        $expiryFieldNames = ['expires_at', 'expires', 'expiration', 'exp_date', 'expire_date', 'expiry'];

        foreach ($expiryFieldNames as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                try {
                    return \Carbon\Carbon::parse($data[$field]);
                } catch (\Exception $e) {
                    // If parsing fails, continue to next field
                    continue;
                }
            }
        }

        return null;
    }

    // ════════════════════════════════════════════════════════════════════
    // Public entry points — dispatch to invAIse or DLM based on
    // msteamsfs.license_provider ('invaise' default, 'dlm' for rollback).
    // Callers (MSTeamsFSController, ServiceProvider's weekly schedule) are
    // unchanged — they still call these same three method names.
    // ════════════════════════════════════════════════════════════════════

    public function activateLicense($licenseKey, $domain = null)
    {
        if (config('msteamsfs.license_provider', 'invaise') === 'dlm') {
            return $this->activateLicenseViaDLM($licenseKey, $domain);
        }
        return $this->activateLicenseViaInvaise($licenseKey, $domain);
    }

    public function validateLicense($licenseKey, $domain = null)
    {
        if (config('msteamsfs.license_provider', 'invaise') === 'dlm') {
            return $this->validateLicenseViaDLM($licenseKey, $domain);
        }
        return $this->validateLicenseViaInvaise($licenseKey, $domain);
    }

    public function deactivateLicense($licenseKey, $domain = null)
    {
        if (config('msteamsfs.license_provider', 'invaise') === 'dlm') {
            return $this->deactivateLicenseViaDLM($licenseKey, $domain);
        }
        return $this->deactivateLicenseViaInvaise($licenseKey, $domain);
    }

    /**
     * Perform an action (activate, deactivate, validate) on the license
     */
    public function performAction($action, $licenseKey) {
        switch ($action) {
            case 'activate':
                return $this->activateLicense($licenseKey);
            case 'deactivate':
                return $this->deactivateLicense($licenseKey);
            case 'validate':
                return $this->validateLicense($licenseKey);
            default:
                return [
                    'success' => false,
                    'message' => __('Invalid action')
                ];
        }
    }

    /**
     * Quick static check — true if a valid license record exists in the DB.
     */
    public static function isLicensed(): bool
    {
        try {
            return (bool) ((new self())->getLicenseStatus()['valid'] ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the current license status — PURE LOCAL READ, no remote call.
     * Unchanged by the DLM -> invAIse migration (per explicit instruction:
     * this method doesn't touch DLM or invAIse, leave it alone).
     */
    public function getLicenseStatus()
    {
        // Check if table exists first
        if (!$this->tableExists('modules_licenses')) {
            return [
                "valid" => false,
                "status" => "no_table",
                "message" => __("License table does not exist yet. Run migrations first.")
            ];
        }

        try {
            $license = MSTeamsFSLicense::first();

            if (!$license) {
                return [
                    "valid" => false,
                    "status" => "no_license",
                    "message" => __("No license key entered")
                ];
            }

            $status = [
                "valid" => $license->isValid(),
                "status" => $license->status,
                "license_key" => $license->license_key,
                "expires_at" => $license->expires_at,
                "is_expired" => $license->isExpired(),
                "license_type" => $license->license_type
            ];

            // Card #34 (invAIse board): response_data already carries invAIse's raw
            // validate/activate payload verbatim (persistInvaiseResult saves $mapped['data']
            // unchanged) -- seats_purchased/seats_occupied were already flowing into it, just
            // never read back out for display. Same array_key_exists discipline as
            // mapInvaiseResponse() above: omit entirely rather than null, so the view can tell
            // "no seats entitlement" apart from "seats data not loaded yet".
            $responseData = is_array($license->response_data) ? $license->response_data : [];
            if (array_key_exists('seats_purchased', $responseData)) {
                $status['seats_purchased'] = $responseData['seats_purchased'];
            }
            if (array_key_exists('seats_occupied', $responseData)) {
                $status['seats_occupied'] = $responseData['seats_occupied'];
            }

            return $status;
        } catch (\Exception $e) {
            return [
                "valid" => false,
                "status" => "error",
                "message" => __("Error accessing license data: ") . $e->getMessage()
            ];
        }
    }

    /**
     * Check if the table exists
     */
    private function tableExists($table)
    {
        try {
            return \Schema::hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}
