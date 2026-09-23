<?php

namespace Modules\Admin\Models\Asset;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\EntityRelation\EntityRelation;
use Modules\Admin\Models\Project\Project;

#[Fillable(['asset_type', 'name', 'code', 'location', 'status'])]
final class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /** @return EloquentFactory<Asset> */
    protected static function newFactory(): EloquentFactory
    {
        return AssetFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'asset_type' => AssetType::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<EntityRelation, $this> */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'target_id')
            ->where('source_type', EntityRelation::ENTITY_TYPE_ASSET)
            ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
            ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS);
    }

    /** @return HasMany<EntityRelation, $this> */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(EntityRelation::class, 'source_id')
            ->where('source_type', EntityRelation::ENTITY_TYPE_ASSET)
            ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
            ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS);
    }
}
