<?php

namespace Modules\Masterdata\Actions\Asset\Http;

use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Actions\Asset\CreateAssetAction as CreateAsset;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Asset\StoreAssetRequest;
use Modules\Masterdata\Resources\Asset\AssetResource;
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
