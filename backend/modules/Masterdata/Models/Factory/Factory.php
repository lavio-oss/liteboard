<?php

namespace Modules\Masterdata\Models\Factory;

use Database\Factories\FactoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;
use Modules\Masterdata\Models\Project\Project;

#[Fillable(['name', 'code', 'location', 'status'])]
final class Factory extends Model
{
    /** @use HasFactory<FactoryFactory> */
    use HasFactory;

    /** @return EloquentFactory<Factory> */
    protected static function newFactory(): EloquentFactory
    {
        return FactoryFactory::new();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<ProductionLine, $this> */
    public function productionLines(): HasMany
    {
        return $this->hasMany(ProductionLine::class);
    }
}
