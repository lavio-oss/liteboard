<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\System\SystemRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

final class CoreDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Administrator',
                'password' => '12345678',
                'system_role' => SystemRole::PlatformAdmin->value,
            ],
        );

        Tenant::query()->updateOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'status' => 'active',
            ],
        );
    }
}
