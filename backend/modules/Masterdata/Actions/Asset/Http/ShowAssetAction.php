<?php

namespace Modules\Masterdata\Actions\Asset\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Asset\AuthenticatedRequest;
use Modules\Masterdata\Resources\Asset\AssetResource;
use Symfony\Component\HttpFoundation\Response;

final class ShowAssetAction
{
    public function __invoke(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Project $project,
        Asset $asset,
        Responder $responder,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('view', $asset);
        $asset->load('incomingRelations');

        return $responder->resource(AssetResource::make($asset));
    }
}
