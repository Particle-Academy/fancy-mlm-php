<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A recorded scan. No IP, no user agent, no session id: see the migration.
 *
 * @property int         $id
 * @property string      $code
 * @property int         $member_id
 * @property string      $scope
 * @property string      $session_hash
 * @property string|null $context      JSON, host-supplied only
 * @property string      $scanned_at   UTC, Y-m-d H:i:s
 */
class ReferralScan extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('mlm.tables.referral_scans', 'mlm_referral_scans');
    }
}
