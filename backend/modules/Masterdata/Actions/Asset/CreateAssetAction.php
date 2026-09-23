<?php

namespace Modules\Masterdata\Actions\Asset;

use Illuminate\Support\Facades\DB;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\Project\Project;

final class CreateAssetAction
{
    /**
     * @param  array{
     *     parent_asset_id?: int|null,
     *     asset_type: string,
     *     name: string,
     *     code: string,
     *     location?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function execute(Project $project, array $attributes): Asset
    {
        return DB::transaction(function () use ($project, $attributes): Asset {
            $parentAssetId = array_key_exists('parent_asset_id', $attributes)
                ? $attributes['parent_asset_id']
                : null;
            unset($attributes['parent_asset_id']);

            $asset = new Asset;
            $asset->project()->associate($project);
            $asset->fill($attributes);
            $asset->save();

            app(SyncAssetParentRelationAction::class)->execute($asset, $parentAssetId);

            return $asset->refresh();
        });
    }
}
