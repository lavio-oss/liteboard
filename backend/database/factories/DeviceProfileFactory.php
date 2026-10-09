<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;

/** @extends Factory<DeviceProfile> */
final class DeviceProfileFactory extends Factory
{
    protected $model = DeviceProfile::class;

    /** @return array{code: string, name: string, description: string|null} */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
