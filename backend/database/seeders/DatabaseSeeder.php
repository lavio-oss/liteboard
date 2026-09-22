<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Enums\Asset\AssetType;
use Modules\Masterdata\Models\Asset\Asset;
use Modules\Masterdata\Models\Project\Project;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'system_role' => 'platform_admin',
            ],
        );
        $user->forceFill(['system_role' => 'platform_admin'])->save();

        $tenant = Tenant::query()->firstOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'status' => 'active',
            ],
        );

        if ($user->isPlatformAdmin()) {
            $tenant->users()->detach($user->getKey());
        } else {
            $tenant->users()->syncWithoutDetaching([
                $user->getKey() => ['role' => TenantRole::Owner->value],
            ]);
        }

        $projects = [
            [
                'code' => 'assembly-program',
                'name' => 'Assembly Program',
                'description' => 'IIoT assembly operations.',
            ],
            [
                'code' => 'packaging-program',
                'name' => 'Packaging Program',
                'description' => 'IIoT packaging operations.',
            ],
        ];

        foreach ($projects as $projectSeed) {
            $project = Project::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'name' => $projectSeed['name'],
                ],
                [
                    'description' => $projectSeed['description'],
                    'status' => 'active',
                ],
            );

            $factorySeeds = [
                [
                    'code' => $projectSeed['code'].'-plant-a',
                    'name' => $projectSeed['name'].' Plant A',
                    'location' => 'Hanoi',
                ],
                [
                    'code' => $projectSeed['code'].'-plant-b',
                    'name' => $projectSeed['name'].' Plant B',
                    'location' => 'Da Nang',
                ],
            ];

            foreach ($factorySeeds as $factorySeed) {
                $factory = Asset::query()->updateOrCreate(
                    [
                        'project_id' => $project->getKey(),
                        'asset_type' => AssetType::Factory->value,
                        'code' => $factorySeed['code'],
                    ],
                    [
                        'name' => $factorySeed['name'],
                        'location' => $factorySeed['location'],
                        'status' => 'active',
                        'parent_asset_id' => null,
                    ],
                );

                foreach ([1, 2] as $lineNumber) {
                    Asset::query()->updateOrCreate(
                        [
                            'project_id' => $project->getKey(),
                            'asset_type' => AssetType::ProductionLine->value,
                            'code' => $factorySeed['code'].'-line-'.$lineNumber,
                        ],
                        [
                            'name' => $factorySeed['name'].' Line '.$lineNumber,
                            'status' => 'active',
                            'parent_asset_id' => $factory->getKey(),
                        ],
                    );
                }
            }
        }
    }
}
