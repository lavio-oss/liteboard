<?php

namespace Modules\Masterdata\Actions\Asset;

use Illuminate\Validation\ValidationException;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\EntityRelation\EntityRelation;

final class SyncAssetParentRelationAction
{
    public function execute(Asset $asset, ?int $parentAssetId): void
    {
        if ($parentAssetId !== null) {
            $this->ensureParentIsValid($asset, $parentAssetId);
        }

        EntityRelation::query()
            ->where('project_id', $asset->project_id)
            ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
            ->where('target_id', $asset->getKey())
            ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS)
            ->delete();

        if ($parentAssetId === null) {
            return;
        }

        EntityRelation::query()->create([
            'project_id' => $asset->project_id,
            'source_type' => EntityRelation::ENTITY_TYPE_ASSET,
            'source_id' => $parentAssetId,
            'target_type' => EntityRelation::ENTITY_TYPE_ASSET,
            'target_id' => $asset->getKey(),
            'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
        ]);
    }

    private function ensureParentIsValid(Asset $asset, int $parentAssetId): void
    {
        $parent = Asset::query()
            ->where('project_id', $asset->project_id)
            ->find($parentAssetId);

        if (! $parent instanceof Asset) {
            throw ValidationException::withMessages([
                'parent_asset_id' => 'The selected parent must belong to the same project.',
            ]);
        }

        $visited = [];
        $currentAssetId = $parent->getKey();

        while (true) {
            if ($currentAssetId === $asset->getKey()) {
                throw ValidationException::withMessages([
                    'parent_asset_id' => 'The selected parent would create an asset cycle.',
                ]);
            }

            if (isset($visited[$currentAssetId])) {
                throw ValidationException::withMessages([
                    'parent_asset_id' => 'The asset hierarchy already contains a cycle.',
                ]);
            }

            $visited[$currentAssetId] = true;
            $ancestorId = EntityRelation::query()
                ->where('project_id', $asset->project_id)
                ->where('source_type', EntityRelation::ENTITY_TYPE_ASSET)
                ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
                ->where('target_id', $currentAssetId)
                ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS)
                ->value('source_id');

            if ($ancestorId === null) {
                return;
            }

            $currentAssetId = (int) $ancestorId;
        }
    }
}
