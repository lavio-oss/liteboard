<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\UpdateAssetRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Admin\Services\Asset\AssetPersistenceService;
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
        AssetPersistenceService $assetPersistenceService,
        Responder $responder,
    ): Response {
        $assetInput = $request->assetInput();
        $asset = $assetPersistenceService->update(
            $asset,
            $assetInput['attributes'],
            $assetInput['parent_update'],
        );

        return $responder->resource(AssetResource::make($asset));
    }
}
