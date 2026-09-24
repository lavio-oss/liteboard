<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\StoreAssetRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Admin\Services\Asset\AssetPersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class CreateAssetAction
{
    public function __invoke(
        StoreAssetRequest $request,
        Tenant $tenant,
        Project $project,
        AssetPersistenceService $assetPersistenceService,
        Responder $responder,
    ): Response {
        $assetInput = $request->assetInput();
        $asset = $assetPersistenceService->create(
            $project,
            $assetInput['attributes'],
            $assetInput['parent_asset_id'],
        );

        return $responder->resource(AssetResource::make($asset), Response::HTTP_CREATED);
    }
}
