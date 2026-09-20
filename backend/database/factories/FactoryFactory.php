<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;

/** @extends Factory<FactoryModel> */
final class FactoryFactory extends Factory
{
    protected $model = FactoryModel::class;

    /** @return array{name: string, code: string, location: string|null, status: string} */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => fake()->unique()->slug(2),
            'location' => fake()->optional()->city(),
            'status' => 'active',
        ];
    }
}
