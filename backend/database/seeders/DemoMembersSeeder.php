<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** One login per default project role, so permissions on the Workflow screen can be tried. */
final class DemoMembersSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'demo')->firstOrFail();
        $projects = Project::query()->where('tenant_id', $tenant->getKey())->get();
        $memberRoleId = $tenant->defaultRole(DefaultRole::TenantMember)->getKey();
        $projectAdminRoleId = $tenant->defaultRole(DefaultRole::ProjectAdmin)->getKey();

        // The working screens list participating projects only, so tenant managers join every demo project as its Admin.
        foreach (['admin@gmail.com', 'tenant-admin@gmail.com'] as $email) {
            $manager = User::query()->where('email', $email)->firstOrFail();

            foreach ($projects as $project) {
                $project->users()->syncWithoutDetaching([$manager->getKey() => ['role_id' => $projectAdminRoleId]]);
            }
        }

        $accounts = [
            'project-admin' => DefaultRole::ProjectAdmin,
            'engineer' => DefaultRole::ProjectEngineer,
            'operator' => DefaultRole::ProjectOperator,
            'viewer' => DefaultRole::ProjectViewer,
        ];

        foreach ($accounts as $login => $role) {
            $user = User::query()->updateOrCreate(
                ['email' => "$login@gmail.com"],
                ['name' => ucwords(str_replace('-', ' ', $login)), 'password' => '12345678'],
            );
            $tenant->users()->syncWithoutDetaching([$user->getKey() => ['role_id' => $memberRoleId]]);
            $projectRoleId = $tenant->defaultRole($role)->getKey();

            foreach ($projects as $project) {
                $project->users()->syncWithoutDetaching([$user->getKey() => ['role_id' => $projectRoleId]]);
            }
        }
    }
}
