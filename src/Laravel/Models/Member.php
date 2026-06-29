<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Models;

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
