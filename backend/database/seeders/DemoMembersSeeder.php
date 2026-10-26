<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** One login per project role, so permissions on the Workflow screen can be tried. */
final class DemoMembersSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'demo')->firstOrFail();
        $projects = Project::query()->where('tenant_id', $tenant->getKey())->get();

        foreach (['engineer', 'operator', 'viewer'] as $role) {
            $user = User::query()->updateOrCreate(
                ['email' => "$role@gmail.com"],
                ['name' => ucfirst($role), 'password' => '12345678'],
            );
            $tenant->users()->syncWithoutDetaching([$user->getKey() => ['role' => 'member']]);

            foreach ($projects as $project) {
                $project->users()->syncWithoutDetaching([$user->getKey() => ['role' => $role]]);
            }
        }
    }
}
