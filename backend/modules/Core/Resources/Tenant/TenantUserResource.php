<?php

namespace Modules\Core\Resources\Tenant;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

/** @mixin User */
final class TenantUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $pivot = $user->getRelationValue('pivot');

        if (! $pivot instanceof Pivot || ! is_string($pivot->getAttribute('role_id'))) {
            throw new LogicException('The tenant user pivot must include a role id.');
        }

        return [
            'id' => (string) $user->getKey(),
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role_id' => $pivot->getAttribute('role_id'),
            'created_at' => $user->created_at?->toISOString(),
            'projects' => $this->whenLoaded('projects', fn (): array => $user->projects
                ->map(static fn (Project $project): array => ['id' => (string) $project->getKey(), 'name' => (string) $project->name])
                ->all()),
        ];
    }
}
