<?php

namespace Modules\Masterdata\Actions\Asset;

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
        $asset = new Asset;
        $asset->project()->associate($project);
        $asset->fill($attributes);
        $asset->save();

        return $asset->refresh();
    }
}
