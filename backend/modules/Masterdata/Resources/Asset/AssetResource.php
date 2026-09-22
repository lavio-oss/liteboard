<?php

namespace Modules\Masterdata\Resources\Asset;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Asset\Asset;

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

        return [
            'id' => (int) $asset->getKey(),
            'project_id' => (int) $asset->getAttribute('project_id'),
            'parent_asset_id' => $asset->getAttribute('parent_asset_id') === null
                ? null
                : (int) $asset->getAttribute('parent_asset_id'),
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
