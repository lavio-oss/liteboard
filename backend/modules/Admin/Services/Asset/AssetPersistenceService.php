<?php

namespace Modules\Admin\Services\Asset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\EntityRelation\EntityRelation;
use Modules\Admin\Models\Project\Project;
use RuntimeException;

final class AssetPersistenceService
{
    public function __construct(private readonly AssetHierarchyService $assetHierarchyService) {}

    /**
     * @param  array{
     *     asset_type: string,
     *     name: string,
     *     code: string,
     *     location?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function create(Project $project, array $attributes, ?int $parentAssetId): Asset
    {
        return DB::transaction(function () use ($project, $attributes, $parentAssetId): Asset {
            $asset = $project->assets()->create($attributes);
            $this->assetHierarchyService->synchronizeParent($asset, $parentAssetId);

            return $asset->refresh();
        });
    }

    /**
     * @param  array{
     *     name?: string,
     *     code?: string,
     *     asset_type?: string,
     *     location?: string|null,
     *     status?: string
     * }  $attributes
     * @param  array{provided: bool, parent_asset_id: int|null}  $parentUpdate
     */
    public function update(Asset $asset, array $attributes, array $parentUpdate): Asset
    {
        return DB::transaction(function () use ($asset, $attributes, $parentUpdate): Asset {
            $asset->fill($attributes);
            $asset->save();

            if ($parentUpdate['provided']) {
                $this->assetHierarchyService->synchronizeParent($asset, $parentUpdate['parent_asset_id']);
            }

            return $asset->refresh();
        });
    }

    public function delete(Asset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            EntityRelation::query()
                ->where('project_id', $asset->project_id)
                ->where(function (Builder $query) use ($asset): void {
                    $query
                        ->where(function (Builder $query) use ($asset): void {
                            $query
                                ->where('source_type', EntityRelation::ENTITY_TYPE_ASSET)
                                ->where('source_id', $asset->getKey());
                        })
                        ->orWhere(function (Builder $query) use ($asset): void {
                            $query
                                ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
                                ->where('target_id', $asset->getKey());
                        });
                })
                ->delete();

            if (! $asset->delete()) {
                throw new RuntimeException('Asset deletion was cancelled.');
            }
        });
    }
}
