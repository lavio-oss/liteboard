<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Masterdata\Models\Project\Project;

/** @extends Factory<Project> */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /** @return array{name: string, description: string|null, status: string} */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'status' => 'active',
        ];
    }
}
