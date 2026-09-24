<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\DeleteAssetRequest;
use Modules\Admin\Services\Asset\AssetPersistenceService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class DeleteAssetAction
{
    public function __invoke(
        DeleteAssetRequest $request,
        Tenant $tenant,
        Project $project,
        Asset $asset,
        AssetPersistenceService $assetPersistenceService,
        Responder $responder,
    ): Response {
        $assetPersistenceService->delete($asset);

        return $responder->noContent();
    }
}
