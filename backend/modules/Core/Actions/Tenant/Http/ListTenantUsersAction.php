<?php

namespace Modules\Core\Actions\Tenant\Http;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Requests\Tenant\TenantUserIndexRequest;
use Modules\Core\Resources\Tenant\TenantUserResource;
use Modules\Core\Shared\Responders\Responder;
use Symfony\Component\HttpFoundation\Response;

final class ListTenantUsersAction
{
    public function __invoke(
        TenantUserIndexRequest $request,
        Tenant $tenant,
        Responder $responder,
    ): Response {
        $search = $request->string('search')->value();

        $users = $tenant->users()
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return $responder->resource(TenantUserResource::collection($users));
    }
}
