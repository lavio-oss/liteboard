<?php

namespace Modules\Admin\Actions\Asset\Http;

use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\AuthenticatedRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
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
