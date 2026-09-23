<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Asset\AssetType;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\EntityRelation\EntityRelation;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\Tenant\Tenant;

final class AdminDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the Project workspace and its Asset graph.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->where('code', 'demo')->first();

        if ($tenant === null) {
            throw new \RuntimeException('The demo tenant must be seeded before Admin.');
        }

        $projects = [
            [
                'code' => 'assembly-program',
                'name' => 'Hanoi Assembly Operations',
                'description' => 'Smart assembly, machining and final quality operations.',
                'assets' => [
                    ['type' => AssetType::Site, 'code' => 'hanoi-assembly-campus', 'name' => 'Hanoi Assembly Campus', 'location' => 'Hoa Lac, Hanoi', 'parent' => null],
                    ['type' => AssetType::Factory, 'code' => 'mechanical-manufacturing-plant', 'name' => 'Mechanical Manufacturing Plant', 'location' => 'Building A, Hoa Lac', 'parent' => 'hanoi-assembly-campus'],
                    ['type' => AssetType::Area, 'code' => 'machining-fabrication-area', 'name' => 'Machining and Fabrication Area', 'location' => 'Building A, Hoa Lac', 'parent' => 'mechanical-manufacturing-plant'],
                    ['type' => AssetType::ProductionLine, 'code' => 'cnc-precision-line-01', 'name' => 'CNC Precision Line 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'machining-fabrication-area'],
                    ['type' => AssetType::Station, 'code' => 'cnc-loading-station-01', 'name' => 'CNC Loading Station 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'cnc-precision-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'cnc-turning-center-01', 'name' => 'CNC Turning Center 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'cnc-loading-station-01'],
                    ['type' => AssetType::Equipment, 'code' => 'robotic-loading-arm-01', 'name' => 'Robotic Loading Arm 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'cnc-loading-station-01'],
                    ['type' => AssetType::Station, 'code' => 'in-process-inspection-01', 'name' => 'In-process Inspection Station 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'cnc-precision-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'coordinate-measuring-machine-01', 'name' => 'Coordinate Measuring Machine 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'in-process-inspection-01'],
                    ['type' => AssetType::Equipment, 'code' => 'laser-dimension-gauge-01', 'name' => 'Laser Dimension Gauge 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'in-process-inspection-01'],
                    ['type' => AssetType::Area, 'code' => 'plant-utilities-area', 'name' => 'Plant Utilities Area', 'location' => 'Building A, Hoa Lac', 'parent' => 'mechanical-manufacturing-plant'],
                    ['type' => AssetType::ProductionLine, 'code' => 'compressed-air-system', 'name' => 'Compressed Air System', 'location' => 'Building A, Hoa Lac', 'parent' => 'plant-utilities-area'],
                    ['type' => AssetType::Station, 'code' => 'compressor-room', 'name' => 'Compressor Room', 'location' => 'Building A, Hoa Lac', 'parent' => 'compressed-air-system'],
                    ['type' => AssetType::Equipment, 'code' => 'air-compressor-01', 'name' => 'Air Compressor 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'compressor-room'],
                    ['type' => AssetType::Equipment, 'code' => 'refrigerated-air-dryer-01', 'name' => 'Refrigerated Air Dryer 01', 'location' => 'Building A, Hoa Lac', 'parent' => 'compressor-room'],
                    ['type' => AssetType::Factory, 'code' => 'final-assembly-plant', 'name' => 'Final Assembly Plant', 'location' => 'Building B, Hoa Lac', 'parent' => 'hanoi-assembly-campus'],
                    ['type' => AssetType::Area, 'code' => 'vehicle-assembly-area', 'name' => 'Vehicle Assembly Area', 'location' => 'Building B, Hoa Lac', 'parent' => 'final-assembly-plant'],
                    ['type' => AssetType::ProductionLine, 'code' => 'electric-motor-assembly-line', 'name' => 'Electric Motor Assembly Line', 'location' => 'Building B, Hoa Lac', 'parent' => 'vehicle-assembly-area'],
                    ['type' => AssetType::Station, 'code' => 'stator-winding-station-01', 'name' => 'Stator Winding Station 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'electric-motor-assembly-line'],
                    ['type' => AssetType::Equipment, 'code' => 'stator-winding-machine-01', 'name' => 'Stator Winding Machine 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'stator-winding-station-01'],
                    ['type' => AssetType::Equipment, 'code' => 'copper-wire-feeder-01', 'name' => 'Copper Wire Feeder 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'stator-winding-station-01'],
                    ['type' => AssetType::Station, 'code' => 'motor-end-of-line-test-01', 'name' => 'Motor End-of-line Test Station 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'electric-motor-assembly-line'],
                    ['type' => AssetType::Equipment, 'code' => 'motor-performance-tester-01', 'name' => 'Motor Performance Tester 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'motor-end-of-line-test-01'],
                    ['type' => AssetType::Equipment, 'code' => 'assembly-vision-camera-01', 'name' => 'Assembly Vision Camera 01', 'location' => 'Building B, Hoa Lac', 'parent' => 'motor-end-of-line-test-01'],
                ],
            ],
            [
                'code' => 'packaging-program',
                'name' => 'Da Nang Packaging Operations',
                'description' => 'Automated packaging, warehouse and dispatch operations.',
                'assets' => [
                    ['type' => AssetType::Site, 'code' => 'da-nang-logistics-campus', 'name' => 'Da Nang Logistics Campus', 'location' => 'Hoa Khanh, Da Nang', 'parent' => null],
                    ['type' => AssetType::Factory, 'code' => 'primary-packaging-plant', 'name' => 'Primary Packaging Plant', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'da-nang-logistics-campus'],
                    ['type' => AssetType::Area, 'code' => 'cartoning-labelling-area', 'name' => 'Cartoning and Labelling Area', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'primary-packaging-plant'],
                    ['type' => AssetType::ProductionLine, 'code' => 'automated-cartoning-line-01', 'name' => 'Automated Cartoning Line 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'cartoning-labelling-area'],
                    ['type' => AssetType::Station, 'code' => 'carton-forming-station-01', 'name' => 'Carton Forming Station 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'automated-cartoning-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'carton-former-01', 'name' => 'Carton Former 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'carton-forming-station-01'],
                    ['type' => AssetType::Equipment, 'code' => 'hot-melt-glue-system-01', 'name' => 'Hot Melt Glue System 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'carton-forming-station-01'],
                    ['type' => AssetType::Station, 'code' => 'print-apply-labelling-01', 'name' => 'Print and Apply Labelling Station 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'automated-cartoning-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'industrial-label-printer-01', 'name' => 'Industrial Label Printer 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'print-apply-labelling-01'],
                    ['type' => AssetType::Equipment, 'code' => 'checkweigher-01', 'name' => 'Checkweigher 01', 'location' => 'Zone 1, Hoa Khanh', 'parent' => 'print-apply-labelling-01'],
                    ['type' => AssetType::Area, 'code' => 'finished-goods-warehouse', 'name' => 'Finished Goods Warehouse', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'primary-packaging-plant'],
                    ['type' => AssetType::ProductionLine, 'code' => 'palletizing-dispatch-line-01', 'name' => 'Palletizing and Dispatch Line 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'finished-goods-warehouse'],
                    ['type' => AssetType::Station, 'code' => 'robotic-palletizing-01', 'name' => 'Robotic Palletizing Station 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'palletizing-dispatch-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'palletizing-robot-01', 'name' => 'Palletizing Robot 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'robotic-palletizing-01'],
                    ['type' => AssetType::Equipment, 'code' => 'stretch-wrapper-01', 'name' => 'Stretch Wrapper 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'robotic-palletizing-01'],
                    ['type' => AssetType::Station, 'code' => 'dispatch-scanning-01', 'name' => 'Dispatch Scanning Station 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'palletizing-dispatch-line-01'],
                    ['type' => AssetType::Equipment, 'code' => 'rfid-warehouse-gate-01', 'name' => 'RFID Warehouse Gate 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'dispatch-scanning-01'],
                    ['type' => AssetType::Equipment, 'code' => 'parcel-dimension-scanner-01', 'name' => 'Parcel Dimension Scanner 01', 'location' => 'Zone 2, Hoa Khanh', 'parent' => 'dispatch-scanning-01'],
                ],
            ],
        ];

        foreach ($projects as $projectSeed) {
            DB::transaction(function () use ($tenant, $projectSeed): void {
                $project = Project::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $projectSeed['name'],
                    ],
                    [
                        'description' => $projectSeed['description'],
                        'status' => 'active',
                    ],
                );

                /** @var array<string, Asset> $assetsByCode */
                $assetsByCode = [];

                foreach ($projectSeed['assets'] as $assetSeed) {
                    $parent = $assetSeed['parent'] === null
                        ? null
                        : ($assetsByCode[$assetSeed['parent']] ?? null);

                    if ($assetSeed['parent'] !== null && $parent === null) {
                        throw new \RuntimeException('Missing parent asset seed: '.$assetSeed['parent']);
                    }

                    $asset = Asset::query()->updateOrCreate(
                        [
                            'project_id' => $project->getKey(),
                            'asset_type' => $assetSeed['type']->value,
                            'code' => $projectSeed['code'].'-'.$assetSeed['code'],
                        ],
                        [
                            'name' => $assetSeed['name'],
                            'location' => $assetSeed['location'],
                            'status' => 'active',
                        ],
                    );

                    $relation = EntityRelation::query()
                        ->where('project_id', $project->getKey())
                        ->where('target_type', EntityRelation::ENTITY_TYPE_ASSET)
                        ->where('target_id', $asset->getKey())
                        ->where('relation_type', EntityRelation::RELATION_TYPE_CONTAINS);

                    if ($parent === null) {
                        $relation->delete();
                    } else {
                        $relation->updateOrCreate(
                            [],
                            [
                                'project_id' => $project->getKey(),
                                'source_type' => EntityRelation::ENTITY_TYPE_ASSET,
                                'source_id' => $parent->getKey(),
                                'target_type' => EntityRelation::ENTITY_TYPE_ASSET,
                                'target_id' => $asset->getKey(),
                                'relation_type' => EntityRelation::RELATION_TYPE_CONTAINS,
                            ],
                        );
                    }

                    $assetsByCode[$assetSeed['code']] = $asset;
                }
            });
        }
    }
}
