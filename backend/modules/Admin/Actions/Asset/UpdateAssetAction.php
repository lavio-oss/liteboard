<?php

namespace Modules\Admin\Actions\Asset;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Asset\Asset;

final class UpdateAssetAction
{
    /**
     * @param  array{
     *     parent_asset_id?: int|null,
     *     asset_type?: string,
     *     name?: string,
     *     code?: string,
     *     location?: string|null,
     *     status?: string
     * }  $attributes
     */
    public function execute(Asset $asset, array $attributes): Asset
    {
        return DB::transaction(function () use ($asset, $attributes): Asset {
            $hasParentUpdate = array_key_exists('parent_asset_id', $attributes);
            $parentAssetId = $hasParentUpdate ? $attributes['parent_asset_id'] : null;
            unset($attributes['parent_asset_id']);

            $asset->fill($attributes);
            $asset->save();

            if ($hasParentUpdate) {
                app(SyncAssetParentRelationAction::class)->execute($asset, $parentAssetId);
            }

            return $asset->refresh();
        });
    }
}
