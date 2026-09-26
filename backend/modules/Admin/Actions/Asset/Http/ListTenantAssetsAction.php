<?php

namespace Modules\Admin\Actions\Asset\Http;

use Modules\Admin\Requests\Asset\TenantAssetIndexRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Admin\Services\Asset\AssetQueryService;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTenantAssetsAction
{
    public function __invoke(
        TenantAssetIndexRequest $request,
        Tenant $tenant,
        AssetQueryService $assetQueryService,
        Responder $responder,
    ): Response {
        $assets = $assetQueryService->paginateForTenant(
            $tenant,
            $request->string('search')->value(),
            $request->filled('asset_type') ? $request->string('asset_type')->value() : null,
            $request->filled('project_id') ? $request->string('project_id')->value() : null,
            $request->integer('per_page', 15),
        );

        return $responder->resource(AssetResource::collection($assets));
    }
}
