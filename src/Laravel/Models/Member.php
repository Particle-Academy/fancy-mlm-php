<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Models;

use FancyMlm\Attribution\Exceptions\SelfReferral;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent record for a network member. `sponsor_id` references another row in
 * this table (the enroller tree); `user_id` links to the host's user model.
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property int|null    $sponsor_id
 * @property int|null    $placement_id
 * @property string      $tier
 * @property bool        $active
 * @property array|null  $meta
 */
class Member extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'meta' => 'array',
    ];

    /**
     * `sponsor_id` is permanent: it may go from null to a member once, and is
     * never changed or cleared afterwards. This guard covers Eloquent; the
     * database trigger covers everything else. For the full rules (including
     * cycles) set it through `Mlm::assignSponsor()`.
     */
    protected static function booted(): void
    {
        static::updating(static function (Member $member): void {
            if (! $member->isDirty('sponsor_id')) {
                return;
            }
            $original = $member->getOriginal('sponsor_id');
            $next = $member->sponsor_id;

            if ($original !== null) {
                throw SponsorImmutable::for((string) $member->getKey(), (string) $original, $next === null ? null : (string) $next);
            }
            if ($next !== null && (string) $next === (string) $member->getKey()) {
                throw SelfReferral::for((string) $member->getKey());
            }
        });
    }

    public function getTable(): string
    {
        return (string) config('mlm.tables.members', 'mlm_members');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(static::class, 'sponsor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo((string) config('mlm.user_model', 'App\\Models\\User'), 'user_id');
    }
}
