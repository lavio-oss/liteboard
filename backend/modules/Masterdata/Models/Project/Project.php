<?php

namespace Modules\Masterdata\Models\Project;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;

#[Fillable(['name', 'description', 'status'])]
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** @return EloquentFactory<Project> */
    protected static function newFactory(): EloquentFactory
    {
        return ProjectFactory::new();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<FactoryModel, $this> */
    public function factories(): HasMany
    {
        return $this->hasMany(FactoryModel::class);
    }
}
