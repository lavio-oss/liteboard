<?php

use Database\Seeders\DatabaseSeeder;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

it('seeds every demo login with its tenant role and the managers into every demo project', function (): void {
    $this->seed(DatabaseSeeder::class);

    $tenant = Tenant::query()->where('code', 'demo')->firstOrFail();
    $projects = $tenant->projects()->get();
    $expected = [
        'admin@gmail.com' => ['owner', 'admin'],
        'tenant-admin@gmail.com' => ['admin', 'admin'],
        'project-admin@gmail.com' => ['member', 'admin'],
        'engineer@gmail.com' => ['member', 'engineer'],
        'operator@gmail.com' => ['member', 'operator'],
        'viewer@gmail.com' => ['member', 'viewer'],
    ];

    expect($projects)->not->toBeEmpty();

    foreach ($expected as $email => [$tenantRole, $projectRole]) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($tenant->users()->whereKey($user->id)->firstOrFail()->pivot->role_id)->toBe(roleId($tenant, $tenantRole));

        foreach ($projects as $project) {
            expect($project->users()->whereKey($user->id)->firstOrFail()->pivot->role_id)->toBe(roleId($project, $projectRole));
        }
    }
});

it('lets the seeded owner see demo projects on the participating screens', function (): void {
    $this->seed(DatabaseSeeder::class);
    $tenant = Tenant::query()->where('code', 'demo')->firstOrFail();

    $this->actingAs(User::query()->where('email', 'admin@gmail.com')->firstOrFail())
        ->getJson("/api/tenants/$tenant->id/projects?participating=1")
        ->assertOk()
        ->assertJsonCount($tenant->projects()->count(), 'data');
});

it('builds memberships through the user factory role state', function (): void {
    $project = Project::factory()->for(Tenant::factory())->create();
    $engineer = User::factory()->withRole($project, DefaultRole::ProjectEngineer)->create();
    $tenant = $project->tenant()->firstOrFail();

    expect($tenant->users()->whereKey($engineer->id)->firstOrFail()->pivot->role_id)->toBe(roleId($tenant, 'member'))
        ->and($project->users()->whereKey($engineer->id)->firstOrFail()->pivot->role_id)->toBe(roleId($project, 'engineer'));
});
