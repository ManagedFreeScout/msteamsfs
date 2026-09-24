<?php

namespace Modules\MSTeamsFS\Models;

use Illuminate\Database\Eloquent\Model;

class MSTeamsFSLicense extends Model
{
    protected $table = 'modules_licenses';

    // Staleness backstop (card #232, F5). The cached is_valid/status below is
    // only ever refreshed by the periodic background check (every 6 hours) or
    // the manual Refresh button -- never by sign-in itself, deliberately (see
    // MSTeamsFSServiceProvider). If that periodic check hasn't actually heard
    // back from invAIse in a long time -- invAIse down, credentials broken,
    // or FreeScout's own cron stopped running entirely -- this cached "valid"
    // status could otherwise be trusted forever, per persistInvaiseResult()'s
    // own documented behavior of saving nothing on an unreachable call. This
    // caps how long a stale answer is honored. 14 days chosen deliberately:
    // long enough that a brief invAIse blip or a missed cron run never
    // wrongly locks out a paying customer, short enough that a genuinely
    // cancelled subscription doesn't keep working indefinitely.
    const MAX_STALE_DAYS = 14;
    
    protected $fillable = [
        "module_alias",
        "license_key",
        "is_valid",
        "status",
        "license_type",
        "expires_at",
        "domain",
        "response_data"
    ];

    protected $casts = [
        "is_valid" => "boolean",
        "expires_at" => "datetime",
        "response_data" => "array",
    ];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('msteamsfs', function ($builder) {
            $builder->where('module_alias', 'msteamsfs');
        });

        static::creating(function ($model) {
            $model->module_alias = 'msteamsfs';
        });
    }

    /**
     * Check if the license is currently valid
     */
    public function isValid()
    {
        if (!$this->is_valid || $this->status !== "active") {
            return false;
        }

        // Check if license has expired
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        // Staleness backstop -- see MAX_STALE_DAYS above.
        if ($this->updated_at && $this->updated_at->lt(now()->subDays(self::MAX_STALE_DAYS))) {
            return false;
        }

        return true;
    }

    /**
     * Check if the license is expired
     */
    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
