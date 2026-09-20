<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Core\Enums\Tenant\TenantRole;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;
use Modules\Masterdata\Models\Factory\Factory as FactoryModel;
use Modules\Masterdata\Models\ProductionLine\ProductionLine;
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
            ],
        );

        $tenant = Tenant::query()->firstOrCreate(
            ['code' => 'demo'],
            [
                'name' => 'Demo Tenant',
                'status' => 'active',
            ],
        );

        $tenant->users()->syncWithoutDetaching([
            $user->getKey() => ['role' => TenantRole::Owner->value],
        ]);

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

            $factories = [
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

            foreach ($factories as $factorySeed) {
                $factory = FactoryModel::query()->updateOrCreate(
                    [
                        'project_id' => $project->getKey(),
                        'code' => $factorySeed['code'],
                    ],
                    [
                        'name' => $factorySeed['name'],
                        'location' => $factorySeed['location'],
                        'status' => 'active',
                    ],
                );

                foreach ([1, 2] as $lineNumber) {
                    ProductionLine::query()->updateOrCreate(
                        [
                            'factory_id' => $factory->getKey(),
                            'code' => $factorySeed['code'].'-line-'.$lineNumber,
                        ],
                        [
                            'name' => $factorySeed['name'].' Line '.$lineNumber,
                            'status' => 'active',
                        ],
                    );
                }
            }
        }
    }
}
