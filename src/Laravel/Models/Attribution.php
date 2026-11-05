<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which scan made a member's sponsor. One row per member, enforced unique.
 *
 * @property int         $id
 * @property int         $member_id
 * @property int         $sponsor_member_id
 * @property string      $code
 * @property string      $scope
 * @property int|null    $scan_id
 * @property string      $model
 * @property string      $attributed_at  UTC, Y-m-d H:i:s
 */
class Attribution extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('mlm.tables.attributions', 'mlm_attributions');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'sponsor_member_id');
    }
}
