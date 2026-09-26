<?php

namespace Modules\Admin\Resources\Project;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\Project\Project;

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

        return [
            'id' => (string) $project->getKey(),
            'tenant_id' => (string) $project->getAttribute('tenant_id'),
            'name' => (string) $project->name,
            'description' => $project->description,
            'status' => (string) $project->status,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
