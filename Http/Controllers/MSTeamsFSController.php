<?php

namespace Modules\MSTeamsFS\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\MSTeamsFS\Services\LicenseService;

class MSTeamsFSController extends Controller
{
    protected $licenseService;

    public function __construct()
    {
        $this->licenseService = null;
    }

    protected function getLicenseService()
    {
        if ($this->licenseService === null) {
            $this->licenseService = app(LicenseService::class);
        }
        return $this->licenseService;
    }

    // index()/saveSettings() removed (card #232, F9) -- dead code, never routed
    // (see Http/routes.php: only manageLicense and handleModuleLicenseAction
    // are registered). Wrote to msteamsfs.tenant_id/client_id, options the
    // real settings page (wired via the settings.* Eventy filters in
    // MSTeamsFSServiceProvider, using msteamsfs.backend_secret and
    // msteamsfs.allowed_domains instead) never reads.

    public function manageLicense(Request $request)
    {
        $licenseService = $this->getLicenseService();
        $licenseStatus = $licenseService->getLicenseStatus();

        if ($licenseStatus['status'] === 'no_table') {
            return response()->json([
                'status' => 'error',
                'message' => __('License table does not exist. Please run migrations first.')
            ]);
        }

        $action = $request->input('action');
        $licenseKey = $request->input('license_key');

        // 'validate' added 2026-09-22 (invaise#227 follow-up): the Seats line only ever
        // reflects the last activate/validate snapshot, and nothing previously called
        // validate from anywhere in this settings page's own UI -- only activate, which
        // never surfaced a way to refresh seat counts on demand. Doesn't need a posted
        // license_key any more than deactivate does -- both operate on whatever key is
        // already stored, not a value typed into the (possibly-empty) input field.
        if (empty($licenseKey) && !in_array($action, ['deactivate', 'validate'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => __('License key is required.')
            ]);
        }

        switch ($action) {
            case 'activate':
                $result = $licenseService->activateLicense($licenseKey);
                break;
            case 'deactivate':
                $licenseStatus = $licenseService->getLicenseStatus();
                $licenseKey = $licenseStatus['license_key'] ?? $licenseKey;
                $result = $licenseService->deactivateLicense($licenseKey);
                break;
            case 'validate':
                $licenseKey = $licenseStatus['license_key'] ?? $licenseKey;
                $result = $licenseService->validateLicense($licenseKey);
                break;
            default:
                return response()->json([
                    'status' => 'error',
                    'message' => __('Invalid action.')
                ]);
        }

        return response()->json([
            'status' => $result['success'] ? 'success' : 'error',
            'message' => __($result['message'])
        ]);
    }

    public function handleModuleLicenseAction(Request $request)
    {
        $action = $request->input('action');
        $moduleAlias = $request->input('module_alias');
        $licenseKey = $request->input('license_key');

        if ($moduleAlias !== 'msteamsfs') {
            return response()->json([
                'success' => false,
                'message' => __('Invalid module')
            ]);
        }

        if (empty($licenseKey) && $action !== 'deactivate') {
            return response()->json([
                'success' => false,
                'message' => __('License key is required')
            ]);
        }

        $result = $this->getLicenseService()->performAction($action, $licenseKey);

        return response()->json($result);
    }
}
