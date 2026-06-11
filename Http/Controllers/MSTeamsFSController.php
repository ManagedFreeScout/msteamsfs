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

    public function index()
    {
        $settings = [
            'tenant_id'      => \Option::get('msteamsfs.tenant_id') ?? '',
            'client_id'      => \Option::get('msteamsfs.client_id') ?? '',
            'allowed_domains' => \Option::get('msteamsfs.allowed_domains') ?? '',
            'license_status' => app(LicenseService::class)->getLicenseStatus(),
        ];
        return view('msteamsfs::settings.msteamsfs', compact('settings'));
    }

    public function saveSettings(Request $request)
    {
        \Option::set('msteamsfs.tenant_id',      $request->input('tenant_id', ''));
        \Option::set('msteamsfs.client_id',      $request->input('client_id', ''));
        \Option::set('msteamsfs.allowed_domains', $request->input('allowed_domains', ''));
        return redirect()->back()->with('status', __('Settings saved.'));
    }

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

        if (empty($licenseKey) && $action !== 'deactivate') {
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
