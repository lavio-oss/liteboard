<?php

namespace Modules\Masterdata\Resources\Factory;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;

/** @mixin FactoryModel */
final class FactoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FactoryModel $factory */
        $factory = $this->resource;

        $createdAt = $factory->getAttribute('created_at');
        $updatedAt = $factory->getAttribute('updated_at');

        return [
            'id' => (int) $factory->getKey(),
            'project_id' => (int) $factory->getAttribute('project_id'),
            'name' => (string) $factory->name,
            'code' => (string) $factory->code,
            'location' => $factory->location,
            'status' => (string) $factory->status,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
