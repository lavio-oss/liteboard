<?php

namespace Modules\Admin\Resources\Project;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

/** @mixin Project */
final class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Project $project */
        $project = $this->resource;

        $createdAt = $project->getAttribute('created_at');
        $updatedAt = $project->getAttribute('updated_at');

        $user = $request->user();
        $role = null;

        if ($user instanceof User) {
            if ($user->isPlatformAdmin()) {
                $role = 'admin';
            } else {
                $member = $project->relationLoaded('users')
                    ? $project->users->firstWhere('id', $user->getKey())
                    : $project->users()->whereKey($user->getKey())->first();

                $pivot = $member?->getRelationValue('pivot');
                $pivotRole = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;

                if ($pivotRole !== null && ! is_string($pivotRole)) {
                    throw new LogicException('The project membership pivot role must be a string when present.');
                }

                $role = $pivotRole;
            }
        }

        return [
            'id' => (string) $project->getKey(),
            'tenant_id' => (string) $project->getAttribute('tenant_id'),
            'name' => (string) $project->name,
            'description' => $project->description,
            'status' => (string) $project->status,
            'role' => $role,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
