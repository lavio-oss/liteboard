<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;

/** @extends Factory<ProductionLine> */
final class ProductionLineFactory extends Factory
{
    protected $model = ProductionLine::class;

    /** @return array{name: string, code: string, status: string} */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'code' => fake()->unique()->slug(2),
            'status' => 'active',
        ];
    }
}
