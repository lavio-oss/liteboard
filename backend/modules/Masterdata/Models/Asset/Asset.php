<?php

namespace Modules\Masterdata\Models\Asset;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Project\Project;

#[Fillable(['parent_asset_id', 'asset_type', 'name', 'code', 'location', 'status'])]
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

    /** @return BelongsTo<Asset, $this> */
    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    /** @return HasMany<Asset, $this> */
    public function childAssets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }
}
