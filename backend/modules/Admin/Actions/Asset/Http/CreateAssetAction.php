<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Actions\Asset\CreateAssetAction as CreateAsset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\StoreAssetRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateAssetAction
{
    public function __invoke(
        StoreAssetRequest $request,
        Tenant $tenant,
        Project $project,
        CreateAsset $createAsset,
        Responder $responder,
    ): Response {
        /** @var array{
         *     parent_asset_id?: int|null,
         *     asset_type: string,
         *     name: string,
         *     code: string,
         *     location?: string|null,
         *     status?: string
         * } $attributes
         */
        $attributes = $request->validated();
        $asset = $createAsset->execute($project, $attributes);

        return $responder->resource(AssetResource::make($asset), Response::HTTP_CREATED);
    }
}
