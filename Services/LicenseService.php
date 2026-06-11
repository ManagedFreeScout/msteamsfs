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
    public function validateLicense($licenseKey, $domain = null)
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
    public function activateLicense($licenseKey, $domain = null)
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
    public function deactivateLicense($licenseKey, $domain = null)
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
     * Get the current license status
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

            return [
                "valid" => $license->isValid(),
                "status" => $license->status,
                "license_key" => $license->license_key,
                "expires_at" => $license->expires_at,
                "is_expired" => $license->isExpired(),
                "license_type" => $license->license_type
            ];
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
