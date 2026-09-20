<?php

namespace Modules\Masterdata\Models\ProductionLine;

use Database\Factories\ProductionLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Masterdata\Models\Factory\Factory;

#[Fillable(['name', 'code', 'status'])]
final class ProductionLine extends Model
{
    /** @use HasFactory<ProductionLineFactory> */
    use HasFactory;

    /** @return EloquentFactory<ProductionLine> */
    protected static function newFactory(): EloquentFactory
    {
        return ProductionLineFactory::new();
    }

    /** @return BelongsTo<Factory, $this> */
    public function productionFactory(): BelongsTo
    {
        return $this->belongsTo(Factory::class, 'factory_id');
    }
}
