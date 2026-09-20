<?php

namespace Modules\Masterdata\Actions\ProductionLine;

use Modules\Masterdata\Models\ProductionLine\ProductionLine;

final class UpdateProductionLineAction
{
    /**
     * @param  array{name?: string, code?: string, status?: string}  $attributes
     */
    public function execute(ProductionLine $productionLine, array $attributes): ProductionLine
    {
        $productionLine->fill($attributes);
        $productionLine->save();

        return $productionLine->refresh();
    }
}
