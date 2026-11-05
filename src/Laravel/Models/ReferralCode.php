<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property string      $code
 * @property int         $member_id
 * @property string      $scope
 * @property string      $status
 * @property string|null $expires_at  UTC, Y-m-d H:i:s
 * @property string|null $revoked_at  UTC, Y-m-d H:i:s
 */
class ReferralCode extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('mlm.tables.referral_codes', 'mlm_referral_codes');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
