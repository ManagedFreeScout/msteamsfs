<?php

namespace Modules\MSTeamsFS\Entities;

use Illuminate\Database\Eloquent\Model;

class TeamsUserLink extends Model
{
    protected $table = 'msteamsfs_user_links';

    public $timestamps = false;

    protected $fillable = ['user_id', 'tid', 'oid', 'updated_at'];

    public static function linkUser($userId, $tid, $oid)
    {
        return self::updateOrCreate(
            ['user_id' => $userId],
            ['tid' => $tid, 'oid' => $oid, 'updated_at' => now()]
        );
    }
}
