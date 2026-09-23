<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Actions\Asset\UpdateAssetAction as UpdateAsset;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\UpdateAssetRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class UpdateAssetAction
{
    public function __invoke(
        UpdateAssetRequest $request,
        Tenant $tenant,
        Project $project,
        Asset $asset,
        UpdateAsset $updateAsset,
        Responder $responder,
    ): Response {
        /** @var array{
         *     parent_asset_id?: int|null,
         *     asset_type?: string,
         *     name?: string,
         *     code?: string,
         *     location?: string|null,
         *     status?: string
         * } $attributes
         */
        $attributes = $request->validated();
        $asset = $updateAsset->execute($asset, $attributes);

        return $responder->resource(AssetResource::make($asset));
    }
}
