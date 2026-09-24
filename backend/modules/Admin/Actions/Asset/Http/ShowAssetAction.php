<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\ShowAssetRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ShowAssetAction
{
    public function __invoke(
        ShowAssetRequest $request,
        Tenant $tenant,
        Project $project,
        Asset $asset,
        Responder $responder,
    ): Response {
        $asset->load('incomingRelations');

        return $responder->resource(AssetResource::make($asset));
    }
}
