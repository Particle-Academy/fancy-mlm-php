<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Member;

/**
 * Eloquent-backed {@see MemberRepository}. Maps the {@see MemberModel} record
 * onto the framework-agnostic {@see Member} value object the engine consumes,
 * plus a {@see findByUserId} the fun-lab listener uses to resolve an acting
 * user to their member.
 */
class EloquentMemberRepository implements MemberRepository
{
    public function find(string $id): ?Member
    {
        $model = MemberModel::query()->find($id);

        return $model ? $this->toValueObject($model) : null;
    }

    public function findByUserId(string $userId): ?Member
    {
        $model = MemberModel::query()->where('user_id', $userId)->first();

        return $model ? $this->toValueObject($model) : null;
    }

    private function toValueObject(MemberModel $model): Member
    {
        return new Member(
            id: (string) $model->getKey(),
            sponsorId: $model->sponsor_id !== null ? (string) $model->sponsor_id : null,
            tier: (string) ($model->tier ?? 'default'),
            active: (bool) $model->active,
            placementId: $model->placement_id !== null ? (string) $model->placement_id : null,
            meta: (array) ($model->meta ?? []),
        );
    }
}
