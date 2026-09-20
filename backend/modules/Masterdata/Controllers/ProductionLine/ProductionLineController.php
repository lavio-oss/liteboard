<?php

namespace Modules\Masterdata\Controllers\ProductionLine;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Controllers\Controller;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Core\Requests\Auth\AuthenticatedRequest;
use Modules\Masterdata\Actions\ProductionLine\CreateProductionLineAction;
use Modules\Masterdata\Actions\ProductionLine\UpdateProductionLineAction;
use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;
use Modules\Masterdata\Models\Project\Project;
use Modules\Masterdata\Requests\ProductionLine\ProductionLineIndexRequest;
use Modules\Masterdata\Requests\ProductionLine\StoreProductionLineRequest;
use Modules\Masterdata\Requests\ProductionLine\UpdateProductionLineRequest;
use Modules\Masterdata\Resources\ProductionLine\ProductionLineResource;
use Symfony\Component\HttpFoundation\Response;

final class ProductionLineController extends Controller
{
    public function index(
        ProductionLineIndexRequest $request,
        Tenant $tenant,
        Project $project,
        Factory $factory,
    ): AnonymousResourceCollection {
        $search = $request->string('search')->value();

        $productionLines = $factory->productionLines()
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $query->where(static function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%$search%")
                        ->orWhere('code', 'like', "%$search%");
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return ProductionLineResource::collection($productionLines);
    }

    public function store(
        StoreProductionLineRequest $request,
        Tenant $tenant,
        Project $project,
        Factory $factory,
        CreateProductionLineAction $createProductionLine,
    ): JsonResponse {
        /** @var array{name: string, code: string, status?: string} $attributes */
        $attributes = $request->validated();
        $productionLine = $createProductionLine->execute($factory, $attributes);

        return ProductionLineResource::make($productionLine)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Project $project,
        Factory $factory,
        ProductionLine $productionLine,
    ): ProductionLineResource {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('view', $productionLine);

        return ProductionLineResource::make($productionLine);
    }

    public function update(
        UpdateProductionLineRequest $request,
        Tenant $tenant,
        Project $project,
        Factory $factory,
        ProductionLine $productionLine,
        UpdateProductionLineAction $updateProductionLine,
    ): ProductionLineResource {
        /** @var array{name?: string, code?: string, status?: string} $attributes */
        $attributes = $request->validated();
        $productionLine = $updateProductionLine->execute($productionLine, $attributes);

        return ProductionLineResource::make($productionLine);
    }

    public function destroy(
        AuthenticatedRequest $request,
        Tenant $tenant,
        Project $project,
        Factory $factory,
        ProductionLine $productionLine,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        Gate::forUser($user)->authorize('delete', $productionLine);
        $productionLine->delete();

        return response()->noContent();
    }
}
