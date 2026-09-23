<?php

namespace Modules\Admin\Actions\Asset\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Requests\Asset\AssetIndexRequest;
use Modules\Admin\Resources\Asset\AssetResource;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListAssetsAction
{
    public function __invoke(
        AssetIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Responder $responder,
    ): Response {
        $search = $request->string('search')->value();
        $assets = $project->assets()
            ->with('incomingRelations')
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('code', 'like', "%$search%");
                });
            })
            ->when($request->filled('asset_type'), function (Builder $query) use ($request): void {
                $query->where('asset_type', $request->string('asset_type')->value());
            })
            ->when($request->filled('parent_asset_id'), function (Builder $query) use ($request): void {
                $query->whereHas('incomingRelations', function (Builder $query) use ($request): void {
                    $query->where('source_id', $request->integer('parent_asset_id'));
                });
            })
            ->when(
                ! $request->filled('parent_asset_id') && $request->boolean('root_only'),
                static function (Builder $query): void {
                    $query->whereDoesntHave('incomingRelations');
                },
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return $responder->resource(AssetResource::collection($assets));
    }
}
