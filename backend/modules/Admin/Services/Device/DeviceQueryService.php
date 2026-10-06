<?php

namespace Modules\Admin\Services\Device;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

final class DeviceQueryService
{
    /**
     * @return LengthAwarePaginator<int, Device>
     */
    public function paginateForProject(
        Project $project,
        string $search,
        ?string $status,
        ?string $connectionState,
        ?string $equipmentAssetId,
        int $perPage,
    ): LengthAwarePaginator {
        $query = $project->devices()->getQuery();

        if ($equipmentAssetId !== null) {
            $query->where('equipment_asset_id', $equipmentAssetId);
        }

        return $this->paginate($query, $search, $status, $connectionState, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Device>
     */
    public function paginateForTenant(
        Tenant $tenant,
        string $search,
        ?string $status,
        ?string $connectionState,
        ?string $projectId,
        ?string $equipmentAssetId,
        int $perPage,
    ): LengthAwarePaginator {
        $query = Device::query()
            ->whereIn('project_id', $tenant->projects()->select('projects.id'));

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        if ($equipmentAssetId !== null) {
            $query->where('equipment_asset_id', $equipmentAssetId);
        }

        return $this->paginate($query, $search, $status, $connectionState, $perPage);
    }

    /**
     * @param  Builder<Device>  $query
     * @return LengthAwarePaginator<int, Device>
     */
    private function paginate(
        Builder $query,
        string $search,
        ?string $status,
        ?string $connectionState,
        int $perPage,
    ): LengthAwarePaginator {
        return $query
            ->with(['credential', 'rejectionAudit'])
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('code', 'like', "%$search%");
                });
            })
            ->when($status !== null, static function (Builder $query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($connectionState !== null, static function (Builder $query) use ($connectionState): void {
                $query->where('connection_state', $connectionState);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
