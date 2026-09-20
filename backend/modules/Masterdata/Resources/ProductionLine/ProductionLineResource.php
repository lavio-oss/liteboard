<?php

namespace Modules\Masterdata\Resources\ProductionLine;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

/** @mixin ProductionLine */
final class ProductionLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProductionLine $productionLine */
        $productionLine = $this->resource;

        $createdAt = $productionLine->getAttribute('created_at');
        $updatedAt = $productionLine->getAttribute('updated_at');

        return [
            'id' => (int) $productionLine->getKey(),
            'factory_id' => (int) $productionLine->getAttribute('factory_id'),
            'name' => (string) $productionLine->name,
            'code' => (string) $productionLine->code,
            'status' => (string) $productionLine->status,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toISOString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toISOString() : null,
        ];
    }
}
