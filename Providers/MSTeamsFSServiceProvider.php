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
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->hooks();
        $this->notificationHooks();
        $this->registerTeams404ReloadMiddleware();
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

    /**
     * Teams activity-feed notification triggers. Same Eventy events, same
     * priority/arg-count, as ApiWebhooks' hooks() — the two modules' events
     * are guaranteed to line up with what FreeScout's own "Browser" notification
     * channel fires on, since ApiWebhooks was built by FreeScout's own authors
     * against those same trigger points.
     */
    public function notificationHooks()
    {
        // Conversation assigned. $by_user is the agent who performed the
        // assignment (core dispatches conversation.user_changed($conversation,
        // $user=auth user, $prev_user_id) — confirmed via core source), not the
        // new assignee. The new assignee is $conversation->user.
        \Eventy::addAction('conversation.user_changed', function ($conversation, $by_user) {
            if (!$conversation->user_id) {
                return;
            }
            $actor = $by_user ? $by_user->getFullName() : '';
            self::maybeNotifyTeams('assigned', $conversation, $actor);
        }, 20, 2);

        // Customer replied. Actor is the customer, recipient is the assignee.
        \Eventy::addAction('conversation.customer_replied', function ($conversation, $thread) {
            $actor = $conversation->customer ? $conversation->customer->getFullName(true) : __('A customer');
            self::maybeNotifyTeams('newReply', $conversation, $actor);
        }, 20, 2);

        // Colleague replied. $thread->created_by_user_id identifies who wrote
        // it (confirmed against core's App\Thread model -- created_by_user_id
        // field + created_by_user() relation to App\User). Self-notification
        // guard: never notify the assignee about their own reply/note.
        \Eventy::addAction('conversation.user_replied', function ($conversation, $thread) {
            if (!$conversation->user_id || $thread->created_by_user_id == $conversation->user_id) {
                return;
            }
            $actor = $thread->created_by_user ? $thread->created_by_user->getFullName() : __('A colleague');
            self::maybeNotifyTeams('userReplied', $conversation, $actor);
        }, 20, 2);

        // Colleague added a note. Same self-notification guard as above.
        \Eventy::addAction('conversation.note_added', function ($conversation, $thread) {
            if (!$conversation->user_id || $thread->created_by_user_id == $conversation->user_id) {
                return;
            }
            $actor = $thread->created_by_user ? $thread->created_by_user->getFullName() : __('A colleague');
            self::maybeNotifyTeams('noteAdded', $conversation, $actor);
        }, 20, 2);

        // Deferred: the actual outbound POST to the ManagedFreeScout backend,
        // off the request cycle — same pattern as ApiWebhooks' 'webhook.run'.
        \Eventy::addAction('msteamsfs.notify_teams', function ($eventType, $conversation, $actor) {
            self::sendTeamsNotification($eventType, $conversation, $actor);
        }, 20, 3);
    }

    public static function maybeNotifyTeams($eventType, $conversation, $actor)
    {
        if (!$conversation->user_id) {
            // Nobody assigned — nobody to notify (no @mention concept exists yet;
            // see report on the FreeScout core grep before adding one).
            return;
        }
        \Helper::backgroundAction('msteamsfs.notify_teams', [$eventType, $conversation, $actor]);
    }

    public static function sendTeamsNotification($eventType, $conversation, $actor)
    {
        $link = \Modules\MSTeamsFS\Entities\TeamsUserLink::where('user_id', $conversation->user_id)->first();
        if (!$link) {
            // This FreeScout user has never signed in via the Teams tab — we have
            // no oid/tid to target, so there's nothing Graph could deliver to.
            return;
        }

        $backendSecret = (string) (\Option::get('msteamsfs.backend_secret') ?? '');
        $backendUrl    = config('msteamsfs.backend_url');
        if (empty($backendSecret) || empty($backendUrl)) {
            return;
        }

        $payload = [
            'event'          => $eventType,
            'conversationId' => $conversation->id,
            'subject'        => $conversation->subject,
            'actor'          => $actor,
            'tenantId'       => $link->tid,
            'users'          => [
                ['userId' => $conversation->user_id, 'oid' => $link->oid],
            ],
        ];

        $body      = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $backendSecret);

        $options = \Helper::setGuzzleDefaultOptions(['timeout' => 15]);
        $options['headers'] = [
            'Content-Type'           => 'application/json',
            'X-MSTeamsFS-Signature'  => $signature,
        ];
        $options['body'] = $body;

        try {
            (new \GuzzleHttp\Client())->request('POST', rtrim($backendUrl, '/') . '/teams/notify', $options);
        } catch (\Exception $e) {
            \Log::error('MSTeamsFS: Teams notification POST failed — ' . $e->getMessage());
        }
    }

    public function provides()
    {
        return [];
    }

    /**
     * EXPERIMENTAL (v1.2.7) — see InjectTeams404ReloadScript for full context.
     * Registered as GLOBAL middleware (Kernel::prependMiddleware), not scoped
     * to the 'web' middleware group, since a genuinely unmatched route never
     * runs group middleware at all — only global middleware sees every
     * response regardless of whether a route matched. prependMiddleware (not
     * push) makes this the outermost layer, so its after-$next() logic runs
     * last and sees the truly final response.
     */
    protected function registerTeams404ReloadMiddleware()
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $kernel->prependMiddleware(\Modules\MSTeamsFS\Http\Middleware\InjectTeams404ReloadScript::class);
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
