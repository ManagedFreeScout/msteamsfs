<?php

namespace Modules\MSTeamsFS\Services;

use App\Mailbox;
use App\User;

/**
 * Auto-creates a FreeScout user on their first Microsoft Teams sign-in
 * (MSTeamsFS 1.6.0, board card #247).
 *
 * Off by default. Only active when an admin switched it on AND filled in
 * "Allowed Domains": a Microsoft tenant can contain guest accounts from other
 * domains, and those must never get a FreeScout account automatically.
 * Created users always get role User (never admin), FreeScout's dummy password
 * (they sign in via Teams / Microsoft, and can still set a real password later),
 * and access to the mailboxes ticked under "New users get access to".
 */
class UserProvisioner
{
    public static function isEnabled(): bool
    {
        return (bool) \Option::get('msteamsfs.auto_create_users')
            && trim((string) (\Option::get('msteamsfs.allowed_domains') ?? '')) !== '';
    }

    /** Ticked default mailboxes that still exist (a stale setting never breaks sign-in). */
    public static function defaultMailboxIds(): array
    {
        $ids = \Option::get('msteamsfs.auto_create_mailboxes');
        if (!is_array($ids) || !$ids) {
            return [];
        }

        return Mailbox::whereIn('id', array_map('intval', $ids))->pluck('id')->map('intval')->all();
    }

    /** "Liliyom van Dijk" -> ['Liliyom', 'van Dijk']; no name -> ['Liliyom', ''] from the email. */
    public static function splitName(?string $name, string $email): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $name)));
        if ($name === '') {
            $name = ucfirst((string) strstr($email, '@', true)) ?: 'Teams';
        }
        $parts = explode(' ', $name, 2);

        // Same limits as FreeScout's own "New User" form.
        return [mb_substr($parts[0], 0, 20), mb_substr(trim($parts[1] ?? ''), 0, 30)];
    }

    /**
     * Creates the user (or returns the one a concurrent first sign-in just created).
     * Throws on failure; the caller shows an error page instead of logging in.
     */
    public static function create(string $email, ?string $name): User
    {
        $created = false;
        $user = \DB::transaction(function () use ($email, $name, &$created) {
            $existing = User::where('email', $email)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            [$first, $last] = self::splitName($name, $email);
            $user = User::create([
                'email'               => $email,
                'first_name'          => $first,
                'last_name'           => $last,
                'password'            => User::getDummyPassword(),
                'no_password_hashing' => true,
            ]);
            if (!$user) {
                throw new \RuntimeException('User::create() returned no user');
            }

            $user->role         = User::ROLE_USER;
            $user->status       = User::STATUS_ACTIVE;
            $user->invite_state = User::INVITE_STATE_ACTIVATED;
            $user->timezone     = config('app.timezone') ?: User::DEFAULT_TIMEZONE;
            $user->save();

            // Exactly what FreeScout's own "New User" form does for mailbox access.
            $mailboxIds = self::defaultMailboxIds();
            $user->mailboxes()->sync($mailboxIds);
            $user->syncPersonalFolders($mailboxIds);

            $created = true;

            return $user;
        });

        if ($created) {
            self::notifyAdmins($user);
        }

        return $user;
    }

    /** Uses FreeScout's built-in alert mail (all active admins + "Alert recipients"). */
    protected static function notifyAdmins(User $user): void
    {
        $mailboxes = $user->mailboxes()->pluck('name')->all();
        \Log::info("MSTeamsFS: auto-created FreeScout user {$user->id} ({$user->email}) on first Teams sign-in");

        try {
            \MailHelper::sendAlertMail(
                'A new FreeScout user was created automatically on their first Microsoft Teams sign-in (MFS Connect).'
                ."\n\nName: ".trim($user->first_name.' '.$user->last_name)
                ."\nEmail: ".$user->email
                ."\nRole: User"
                ."\nMailboxes: ".($mailboxes ? implode(', ', $mailboxes) : 'none (assign them in the user profile)')
                ."\n\nReview or adjust this user: ".route('users.profile', ['id' => $user->id]),
                'New user created via Microsoft Teams: '.$user->email
            );
        } catch (\Exception $e) {
            \Log::error('MSTeamsFS: could not queue the new-user alert mail: '.$e->getMessage());
        }
    }
}
