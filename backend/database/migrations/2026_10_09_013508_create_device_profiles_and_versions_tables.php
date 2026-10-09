<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'code'], 'device_profiles_project_code_unique');
            $table->unique(['project_id', 'id'], 'device_profiles_project_id_id_unique');
        });

        Schema::create('device_profile_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('device_profile_id');
            $table->integer('version');
            $table->string('schema_code', 64);
            $table->jsonb('definition');
            $table->text('compatibility_note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'device_profile_versions_project_id_id_unique');
            $table->unique(
                ['device_profile_id', 'version'],
                'device_profile_versions_profile_version_unique',
            );
            $table->unique(
                ['project_id', 'schema_code'],
                'device_profile_versions_project_schema_code_unique',
            );
            $table->foreign(['project_id', 'device_profile_id'], 'device_profile_versions_project_profile_foreign')
                ->references(['project_id', 'id'])
                ->on('device_profiles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE device_profile_versions '
                .'ADD CONSTRAINT device_profile_versions_version_positive_check CHECK (version > 0)',
            );
        }
    }
};
