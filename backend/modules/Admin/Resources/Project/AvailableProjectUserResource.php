<?php

namespace Modules\Admin\Resources\Project;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Core\Models\User\User;

/** @mixin User */
final class AvailableProjectUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $pivot = $user->getRelationValue('pivot');

        if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role'))) {
            throw new LogicException('The available project user pivot must include a string tenant role.');
        }

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'tenant_role' => $pivot->getAttribute('role'),
        ];
    }
}
