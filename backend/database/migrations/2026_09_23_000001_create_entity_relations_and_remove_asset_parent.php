<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('relation_type', 32);
            $table->timestamps();

            $table->unique([
                'project_id',
                'target_type',
                'target_id',
                'relation_type',
            ]);
            $table->unique([
                'project_id',
                'source_type',
                'source_id',
                'target_type',
                'target_id',
                'relation_type',
            ], 'entity_relations_source_target_unique');
            $table->index([
                'project_id',
                'source_type',
                'source_id',
                'relation_type',
            ], 'entity_relations_source_index');
            $table->index([
                'project_id',
                'target_type',
                'target_id',
                'relation_type',
            ], 'entity_relations_target_index');
        });

        DB::table('assets')
            ->whereNotNull('parent_asset_id')
            ->orderBy('id')
            ->get(['id', 'project_id', 'parent_asset_id'])
            ->each(static function (object $asset): void {
                DB::table('entity_relations')->insert([
                    'project_id' => $asset->project_id,
                    'source_type' => 'asset',
                    'source_id' => $asset->parent_asset_id,
                    'target_type' => 'asset',
                    'target_id' => $asset->id,
                    'relation_type' => 'contains',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign(['parent_asset_id']);
            $table->dropIndex(['project_id', 'parent_asset_id']);
            $table->dropColumn('parent_asset_id');
        });
    }
};
