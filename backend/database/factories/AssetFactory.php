<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Asset\Asset;

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
}
