<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Makes the user a member holding the given default role; a project role also adds tenant membership as Member.
     */
    public function withRole(Tenant|Project $scope, DefaultRole $role): static
    {
        return $this->afterCreating(static function (User $user) use ($scope, $role): void {
            $tenant = $scope instanceof Tenant ? $scope : $scope->tenant()->firstOrFail();

            if ($scope instanceof Project && ! $tenant->hasMember($user)) {
                $tenant->users()->attach($user, ['role_id' => $tenant->defaultRole(DefaultRole::TenantMember)->getKey()]);
            }

            $scope->users()->attach($user, ['role_id' => $tenant->defaultRole($role)->getKey()]);
        });
    }
}
