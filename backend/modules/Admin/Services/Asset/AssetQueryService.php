<?php

namespace Modules\Admin\Services\Asset;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

final class AssetQueryService
{
    /**
     * @return LengthAwarePaginator<int, Asset>
     */
    public function paginateForProject(
        Project $project,
        string $search,
        ?string $assetType,
        ?string $parentAssetId,
        bool $rootOnly,
        int $perPage,
    ): LengthAwarePaginator {
        $query = $project->assets()->getQuery();

        if ($parentAssetId !== null) {
            $query->whereHas('incomingRelations', static function (Builder $query) use ($parentAssetId): void {
                $query->where('source_id', $parentAssetId);
            });
        } elseif ($rootOnly) {
            $query->whereDoesntHave('incomingRelations');
        }

        return $this->paginate($query, $search, $assetType, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Asset>
     */
    public function paginateForTenant(
        Tenant $tenant,
        string $search,
        ?string $assetType,
        ?string $projectId,
        int $perPage,
    ): LengthAwarePaginator {
        $query = Asset::query()
            ->whereIn('project_id', $tenant->projects()->select('projects.id'));

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        return $this->paginate($query, $search, $assetType, $perPage);
    }

    /**
     * @param  Builder<Asset>  $query
     * @return LengthAwarePaginator<int, Asset>
     */
    private function paginate(
        Builder $query,
        string $search,
        ?string $assetType,
        int $perPage,
    ): LengthAwarePaginator {
        return $query
            ->with('incomingRelations')
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('code', 'like', "%$search%");
                });
            })
            ->when($assetType !== null, static function (Builder $query) use ($assetType): void {
                $query->where('asset_type', $assetType);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
