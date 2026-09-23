<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\EntityRelation\EntityRelation;

/** @extends Factory<Asset> */
final class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /** @return array{name: string, code: string, asset_type: string, location: string|null, status: string} */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => fake()->unique()->slug(2),
            'asset_type' => AssetType::Factory->value,
            'location' => fake()->optional()->city(),
            'status' => 'active',
        ];
    }

    public function ofType(AssetType $assetType): static
    {
        return $this->state([
            'asset_type' => $assetType->value,
        ]);
    }

    public function withParent(Asset $parent): static
    {
        return $this->afterCreating(static function (Asset $asset) use ($parent): void {
            EntityRelation::query()->create([
                'project_id' => $asset->project_id,
                'source_type' => EntityRelation::ENTITY_TYPE_ASSET,
                'source_id' => $parent->getKey(),
                'target_type' => EntityRelation::ENTITY_TYPE_ASSET,
                'target_id' => $asset->getKey(),
                'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
            ]);
        });
    }
}
