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
            // Auto-create users on first Teams sign-in (card #247, 1.6.0)
            $settings['msteamsfs.auto_create_users']     = (bool) \Option::get('msteamsfs.auto_create_users');
            $mailboxIds = \Option::get('msteamsfs.auto_create_mailboxes');
            $settings['msteamsfs.auto_create_mailboxes'] = is_array($mailboxIds) ? array_map('intval', $mailboxIds) : [];
            // Extra sites allowed to show FreeScout in a frame (card #249, 1.6.2)
            $settings['msteamsfs.extra_frame_ancestors'] = (string)(\Option::get('msteamsfs.extra_frame_ancestors') ?? '');
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
        // Keep the managed CSP block in .htaccess in sync (card #232 F10, #249).
        // A fresh install has no other path to get it, so this still runs from
        // boot(), but behind a cache gate: the file is only checked when the
        // wanted block changed or the last check is over a day old.
        $this->maybeUpdateHtaccessFile();

        // A core update can reset .htaccess to FreeScout's default; put the
        // block back right away instead of waiting for the daily check.
        \Eventy::addAction('command.after_app_update', function () {
            $this->updateHtaccessFile();
        });

        // Normalise "Additional allowed embedders" before it is stored (card #249).
        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section !== 'msteamsfs' || !is_array($request->settings)) {
                return $request;
            }
            $values = $request->settings;
            if (array_key_exists('msteamsfs.extra_frame_ancestors', $values)) {
                list($valid, $invalid) = self::parseFrameAncestors((string) $values['msteamsfs.extra_frame_ancestors']);
                $values['msteamsfs.extra_frame_ancestors'] = implode("\n", $valid);
                $request->merge(['settings' => $values]);
                if ($invalid) {
                    $request->session()->flash('flash_error_floating', __('Ignored invalid embedders (use https://host or https://*.host): :list', ['list' => implode(', ', $invalid)]));
                }
            }
            return $request;
        }, 20, 3);

        // Apply the saved embedders to .htaccess immediately.
        \Eventy::addFilter('settings.after_save', function ($response, $request, $section, $settings) {
            if ($section === 'msteamsfs' && !$this->updateHtaccessFile()) {
                $request->session()->flash('flash_error_floating', __('Could not update .htaccess (not writable). Allowed embedders are not active yet.'));
            }
            return $response;
        }, 20, 4);

        // Not called by FreeScout core (checked up to 1.8.245: core has no
        // frame-ancestors filter). Harmless; the .htaccess block above is what
        // lets Teams embed the helpdesk (card #271).
        \Eventy::addFilter('app.csp_frame_ancestors', function ($ancestors) {
            return array_values(array_unique(array_merge((array) $ancestors, self::frameAncestors())));
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

        // Hub URL for msteamsfs.js: attachments clicked in the Teams tab open
        // in the browser via <hub>/teams/open-attachment (card #260, v1.6.6).
        // A meta tag rather than an inline script, so it needs no CSP nonce.
        \Eventy::addAction('layout.head', function () {
            $backendUrl = rtrim((string) config('msteamsfs.backend_url', ''), '/');
            if ($backendUrl) {
                echo '<meta name="msteamsfs-backend-url" content="'.e($backendUrl).'">';
            }
        }, 20, 0);

        // License re-validation, every 6 hours (card #232, F5 -- was weekly).
        // Deliberately NOT a live check at sign-in: the Teams resign-in flow
        // already re-runs on every tab switch (desktop/browser, confirmed
        // 2026-08-xx), so adding a network call there would slow down
        // something that already happens constantly. This periodic check is
        // the only thing that keeps the locally-cached status (what sign-in
        // actually reads) from drifting indefinitely out of date -- shrinking
        // it from weekly to 6-hourly is the whole fix for the common case.
        // cron() used directly since this Laravel version has no
        // everySixHours()/everyNHours() helper (checked before using it).
        \Eventy::addAction('schedule', function ($schedule) {
            $schedule->call(function () {
                $licenseService = app(LicenseService::class);
                $status = $licenseService->getLicenseStatus();
                if (!empty($status['license_key']) && $status['status'] !== 'no_table') {
                    $licenseService->validateLicense($status['license_key']);
                }
            })->cron('0 */6 * * *');
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

    // Sites that may always show FreeScout in a frame: Microsoft Teams in all its hosts.
    const TEAMS_FRAME_ANCESTORS = [
        'https://teams.microsoft.com',
        'https://*.teams.microsoft.com',
        'https://*.skype.com',
        'https://*.cloud.microsoft',
    ];

    const HTACCESS_BEGIN = '# BEGIN MSTeamsFS';
    const HTACCESS_END   = '# END MSTeamsFS';

    /**
     * Split admin input (commas, spaces or new lines) into valid CSP origins
     * and rejected entries. A bare host gets https:// in front; only https
     * origins are accepted, optionally with a leading "*." wildcard and a port.
     * The strict pattern also keeps quotes and new lines out of .htaccess.
     */
    public static function parseFrameAncestors($raw)
    {
        $valid   = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            $origin = strtolower(rtrim($entry, '/'));
            if (strpos($origin, '://') === false) {
                $origin = 'https://' . $origin;
            }
            if (preg_match('#^https://(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(:\d{1,5})?$#', $origin)) {
                if (!in_array($origin, self::TEAMS_FRAME_ANCESTORS, true)) {
                    $valid[] = $origin;
                }
            } else {
                $invalid[] = $entry;
            }
        }
        return [array_values(array_unique($valid)), $invalid];
    }

    // Teams hosts plus the admin's "Additional allowed embedders". Read without
    // Option's per-process cache: Option::set() does not refresh it, and this
    // runs right after a settings save or the one-time carry-over below.
    public static function frameAncestors()
    {
        list($extra) = self::parseFrameAncestors((string) \Option::get('msteamsfs.extra_frame_ancestors', '', true, false));
        return array_merge(self::TEAMS_FRAME_ANCESTORS, $extra);
    }

    protected static function htaccessBlock()
    {
        return self::HTACCESS_BEGIN . "\n"
            . "# Managed by the MSTeamsFS module: manual changes here are overwritten.\n"
            . "# Add sites under Settings > MSTeams FS > Additional allowed embedders.\n"
            . "<IfModule mod_headers.c>\n"
            . "    Header always set Content-Security-Policy \"frame-ancestors 'self' " . implode(' ', self::frameAncestors()) . ";\"\n"
            . "</IfModule>\n"
            . self::HTACCESS_END;
    }

    /**
     * Run updateHtaccessFile() only when the wanted block changed or the last
     * check is more than a day old, so a normal page view costs one cache read.
     */
    protected function maybeUpdateHtaccessFile()
    {
        try {
            $cacheKey = 'msteamsfs.htaccess_synced';
            if (\Cache::get($cacheKey) === sha1(self::htaccessBlock())) {
                return;
            }
            $this->updateHtaccessFile();
            \Cache::put($cacheKey, sha1(self::htaccessBlock()), 60 * 24);
        } catch (\Throwable $e) {
            \Log::error('MSTeamsFS: .htaccess check failed: ' . $e->getMessage());
        }
    }

    /**
     * Write the CSP frame-ancestors header into .htaccess as one marked block
     * that is replaced in place (card #249). Up to 1.6.1 the module appended a
     * new unmarked block whenever its exact line was missing, so every manual
     * edit got a fresh block after it that silently overrode the edit. Those
     * old blocks are removed here; hosts that were added to them by hand are
     * carried over into the setting once, so they stay allowed.
     *
     * Returns false only when .htaccess exists but cannot be written.
     */
    protected function updateHtaccessFile()
    {
        $htaccessPath = base_path('.htaccess');
        if (!file_exists($htaccessPath)) {
            return true;
        }

        $current = file_get_contents($htaccessPath);
        if ($current === false) {
            return false;
        }

        // Old unmarked blocks written by 1.6.1 and earlier (or hand-edited copies).
        $legacyPattern = '#\n*<IfModule mod_headers\.c>\s*Header always set Content-Security-Policy "frame-ancestors ([^"\n]*teams\.microsoft\.com[^"\n]*)"\s*</IfModule>[ \t]*#';
        if (preg_match_all($legacyPattern, $current, $matches) && \Option::get('msteamsfs.extra_frame_ancestors', null, true, false) === null) {
            $hosts = preg_replace("#'self'|;#", ' ', implode(' ', $matches[1]));
            list($carried) = self::parseFrameAncestors($hosts);
            \Option::set('msteamsfs.extra_frame_ancestors', implode("\n", $carried));
            if ($carried) {
                \Log::info('MSTeamsFS: carried hand-added embedders over from .htaccess: ' . implode(', ', $carried));
            }
        }

        $content = preg_replace($legacyPattern, '', $current);
        $content = preg_replace('#\n*' . preg_quote(self::HTACCESS_BEGIN, '#') . '.*?' . preg_quote(self::HTACCESS_END, '#') . '[ \t]*#s', '', $content);
        $content = rtrim($content) . "\n\n" . self::htaccessBlock() . "\n";

        if ($content === $current) {
            return true;
        }
        if (!is_writable($htaccessPath)) {
            \Log::error('MSTeamsFS: .htaccess is not writable, CSP frame-ancestors not updated');
            return false;
        }

        // One rolling backup instead of a new timestamped copy per change.
        $backupPath = base_path('.htaccess.msteamsfs-backup');
        @copy($htaccessPath, $backupPath);
        if (file_put_contents($htaccessPath, $content, LOCK_EX) === false) {
            return false;
        }

        \Log::info("MSTeamsFS: Updated the CSP block in .htaccess. Previous version at {$backupPath}");
        return true;
    }
}
