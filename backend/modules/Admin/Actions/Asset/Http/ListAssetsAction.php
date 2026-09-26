<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\AssetIndexRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Admin\Services\Asset\AssetQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListAssetsAction
{
    public function __invoke(
        AssetIndexRequest $request,
        Tenant $tenant,
        Project $project,
        AssetQueryService $assetQueryService,
        Responder $responder,
    ): Response {
        $assets = $assetQueryService->paginateForProject(
            $project,
            $request->string('search')->value(),
            $request->filled('asset_type') ? $request->string('asset_type')->value() : null,
            $request->filled('parent_asset_id') ? $request->string('parent_asset_id')->value() : null,
            $request->boolean('root_only'),
            $request->integer('per_page', 15),
        );

        return $responder->resource(AssetResource::collection($assets));
    }
}
