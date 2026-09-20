<?php

namespace Modules\Masterdata\Actions\ProductionLine;

use Modules\Masterdata\Models\Factory\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

final class CreateProductionLineAction
{
    /**
     * @param  array{name: string, code: string, status?: string}  $attributes
     */
    public function execute(Factory $factory, array $attributes): ProductionLine
    {
        $productionLine = new ProductionLine;
        $productionLine->productionFactory()->associate($factory);
        $productionLine->fill($attributes);
        $productionLine->save();

        return $productionLine->refresh();
    }
}
