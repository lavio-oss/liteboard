<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Core\Actions\Role\SeedTenantRolesAction;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/** The single tenant, its default roles, its Owner and its tenant Admin. */
final class CoreDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(SeedTenantRolesAction $seedTenantRoles): void
    {
        $tenant = Tenant::query()->updateOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'status' => 'active',
            ],
        );
        $seedTenantRoles->execute($tenant);

        $accounts = [
            ['email' => 'admin@gmail.com', 'name' => 'Administrator', 'role' => DefaultRole::TenantOwner],
            ['email' => 'tenant-admin@gmail.com', 'name' => 'Tenant Admin', 'role' => DefaultRole::TenantAdmin],
        ];

        foreach ($accounts as $account) {
            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => '12345678'],
            );
            $tenant->users()->syncWithoutDetaching([
                $user->getKey() => ['role_id' => $tenant->defaultRole($account['role'])->getKey()],
            ]);
        }
    }
}
