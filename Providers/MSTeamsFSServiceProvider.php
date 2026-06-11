<?php

namespace Modules\MSTeamsFS\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\MSTeamsFS\Services\LicenseService;

defined('MSTEAMSFS_MODULE') || define('MSTEAMSFS_MODULE', 'msteamsfs');

class MSTeamsFSServiceProvider extends ServiceProvider
{
    protected $defer = false;

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->hooks();
    }

    public function register()
    {
        $moduleVendorPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($moduleVendorPath)) {
            require_once $moduleVendorPath;
        }

        $this->app->singleton(LicenseService::class, function ($app) {
            return new LicenseService();
        });
    }

    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('msteamsfs.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'msteamsfs'
        );

        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['msteamsfs'] = [
                'title'       => __('MSTeams FS'),
                'icon'        => 'lock',
                'order'       => 300,
                'description' => __('ManagedFreeScout Teams SSO settings.'),
            ];
            return $sections;
        }, 15);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'msteamsfs') {
                return $settings;
            }
            $settings['msteamsfs.backend_secret']  = (string)(\Option::get('msteamsfs.backend_secret') ?? '');
            $settings['msteamsfs.allowed_domains']  = (string)(\Option::get('msteamsfs.allowed_domains') ?? '');
            $settings['license_status']             = app(LicenseService::class)->getLicenseStatus();
            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section === 'msteamsfs') {
                $params['license_status'] = app(LicenseService::class)->getLicenseStatus();
            }
            return $params;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'msteamsfs') {
                return $view;
            }
            return 'msteamsfs::settings.msteamsfs';
        }, 20, 2);

        \Eventy::addFilter('modules.show_license', function ($show, $module) {
            if (isset($module['alias']) && $module['alias'] === 'msteamsfs') {
                return true;
            }
            return $show;
        }, 20, 2);

        \Eventy::addFilter('modules.license_info', function ($license_info, $module_alias) {
            if ($module_alias === 'msteamsfs') {
                $status = app(LicenseService::class)->getLicenseStatus();
                return [
                    'license'      => $status['license_key'] ?? '',
                    'activated'    => $status['valid'] ?? false,
                    'status'       => $status['status'] ?? 'inactive',
                    'expires_at'   => $status['expires_at'] ?? null,
                    'license_type' => $status['license_type'] ?? null,
                ];
            }
            return $license_info;
        }, 20, 2);

        \Eventy::addFilter('module.requires_license', function ($requires, $module) {
            if (isset($module['alias']) && $module['alias'] === 'msteamsfs') {
                return true;
            }
            return $requires;
        }, 20, 2);
    }

    public function registerViews()
    {
        $viewPath   = resource_path('views/modules/msteamsfs');
        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([$sourcePath => $viewPath], 'views');

        $this->loadViewsFrom(array_merge(
            array_map(function ($path) { return $path . '/modules/msteamsfs'; }, \Config::get('view.paths')),
            [$sourcePath]
        ), 'msteamsfs');
    }

    public function hooks()
    {
        // Re-add CSP to .htaccess after every FreeScout auto-update
        \Eventy::addAction('command.after_app_update', function () {
            $this->updateHtaccessFile();
        });

        // FreeScout 1.8.219+ native CSP frame-ancestors filter
        \Eventy::addFilter('app.csp_frame_ancestors', function ($ancestors) {
            $extra = [
                'https://teams.microsoft.com',
                'https://*.teams.microsoft.com',
                'https://*.skype.com',
                'https://*.cloud.microsoft',
            ];
            return array_unique(array_merge((array) $ancestors, $extra));
        });

        // Allow TeamsJS v2 SDK from Microsoft CDN
        \Eventy::addFilter('csp.script_src', function ($extra) {
            return trim($extra . ' https://res.cdn.office.net');
        });

        // Inject link + form interception JS on every FreeScout page
        \Eventy::addFilter('javascripts', function ($scripts) {
            $scripts[] = asset('modules/msteamsfs/js/msteamsfs.js');
            return $scripts;
        }, 20, 1);

        // Weekly license re-validation
        \Eventy::addAction('schedule', function ($schedule) {
            $schedule->call(function () {
                $licenseService = app(LicenseService::class);
                $status = $licenseService->getLicenseStatus();
                if (!empty($status['license_key']) && $status['status'] !== 'no_table') {
                    $licenseService->validateLicense($status['license_key']);
                }
            })->weekly();
        }, 20, 1);
    }

    public function provides()
    {
        return [];
    }

    protected function updateHtaccessFile()
    {
        $htaccessPath = base_path('.htaccess');
        if (!file_exists($htaccessPath)) {
            return;
        }

        $currentContent = file_get_contents($htaccessPath);
        $cspLine = 'Header always set Content-Security-Policy "frame-ancestors \'self\' https://teams.microsoft.com https://*.teams.microsoft.com https://*.skype.com https://*.cloud.microsoft;"';

        if (strpos($currentContent, $cspLine) !== false) {
            return;
        }

        $timestamp  = date('Y-m-d_H-i-s');
        $backupPath = base_path(".htaccess.{$timestamp}");
        copy($htaccessPath, $backupPath);

        $newContent = "\n\n<IfModule mod_headers.c>\n    {$cspLine}\n</IfModule>\n";
        file_put_contents($htaccessPath, $newContent, FILE_APPEND);

        \Log::info("MSTeamsFS: Updated .htaccess with CSP headers. Backup at {$backupPath}");
    }
}
