<?php

namespace Modules\Admin\Resources\Asset;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Asset\Asset;

/** @mixin Asset */
final class AssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;
        $assetType = $asset->getAttribute('asset_type');
        $createdAt = $asset->getAttribute('created_at');
        $updatedAt = $asset->getAttribute('updated_at');

        if (! $assetType instanceof AssetType) {
            throw new \UnexpectedValueException('The asset_type attribute must be an AssetType.');
        }

        $parentRelation = $asset->relationLoaded('incomingRelations')
            ? $asset->incomingRelations->first()
            : $asset->incomingRelations()->first();

        return [
            'id' => (string) $asset->getKey(),
            'project_id' => (string) $asset->getAttribute('project_id'),
            'parent_asset_id' => $parentRelation === null
                ? null
                : (string) $parentRelation->source_id,
            'asset_type' => $assetType->value,
            'name' => (string) $asset->name,
            'code' => (string) $asset->code,
            'location' => $asset->location,
            'status' => (string) $asset->status,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
