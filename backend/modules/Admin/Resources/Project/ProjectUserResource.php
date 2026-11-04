<?php

namespace Modules\Admin\Resources\Project;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\User\User;

/** @mixin User */
final class ProjectUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $pivot = $user->getRelationValue('pivot');

        if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role_id'))) {
            throw new LogicException('The project user pivot must include a role id.');
        }

        return [
            'id' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role_id' => $pivot->getAttribute('role_id'),
        ];
    }
}
