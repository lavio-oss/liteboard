<?php

namespace Modules\Core\Actions\Role\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Role\RoleIndexRequest;
use Modules\Core\Resources\Role\RoleResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListRolesAction
{
    public function __invoke(RoleIndexRequest $request, Tenant $tenant, Responder $responder): Response
    {
        $scope = $request->enum('scope', RoleScope::class);

        $roles = $tenant->roles()
            ->with('creator')
            ->when($scope !== null, static function (Builder $query) use ($scope): void {
                $query->where('scope', $scope?->value);
            })
            ->orderBy('scope')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return $responder->resource(RoleResource::collection($roles));
    }
}
