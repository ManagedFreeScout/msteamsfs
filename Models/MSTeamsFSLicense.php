<?php

namespace Modules\MSTeamsFS\Models;

use Illuminate\Database\Eloquent\Model;

class MSTeamsFSLicense extends Model
{
    protected $table = 'modules_licenses';
    
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
