<?php

namespace Modules\Masterdata\Actions\Asset\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Shared\Responders\Responder;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\EntityRelation\EntityRelation;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\Asset\AuthenticatedRequest;
use Symfony\Component\HttpFoundation\Response;

final class DeleteAssetAction
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

        Gate::forUser($user)->authorize('delete', $asset);
        DB::transaction(function () use ($asset): void {
            EntityRelation::query()
                ->where('project_id', $asset->project_id)
                ->where(function (Builder $query) use ($asset): void {
                    $query
                        ->where(function (Builder $query) use ($asset): void {
                            $query
                                ->where('source_type', EntityRelation::ENTITY_TYPE_ASSET)
                                ->where('source_id', $asset->getKey());
                        })
                        ->orWhere(function (Builder $query) use ($asset): void {
                            $query
                                ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
                                ->where('target_id', $asset->getKey());
                        });
                })
                ->delete();

            $asset->delete();
        });

        return $responder->noContent();
    }
}
