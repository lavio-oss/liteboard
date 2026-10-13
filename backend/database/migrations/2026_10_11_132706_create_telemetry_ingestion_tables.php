<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->unique(['project_id', 'id'], 'devices_project_id_id_unique');
        });

        Schema::create('telemetry_samples', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->uuid('device_profile_version_id');
            $table->string('message_id', 128);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->jsonb('values');
            $table->string('status', 16)->default('persisted');

            $table->unique(['device_id', 'message_id'], 'telemetry_samples_device_message_unique');
            $table->unique(
                ['project_id', 'device_id', 'message_id'],
                'telemetry_samples_project_device_message_unique',
            );
            $table->index(
                ['project_id', 'device_id', 'occurred_at'],
                'telemetry_samples_project_device_occurred_index',
            );
            $table->foreign(['project_id', 'device_id'], 'telemetry_samples_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'device_profile_version_id'],
                'telemetry_samples_project_profile_version_foreign',
            )
                ->references(['project_id', 'id'])
                ->on('device_profile_versions')
                ->cascadeOnUpdate();
        });

        Schema::create('telemetry_message_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->string('message_id', 128);
            $table->char('content_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['device_id', 'message_id'], 'telemetry_message_identities_device_message_unique');
            $table->foreign(['project_id', 'device_id'], 'telemetry_message_identities_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(
                ['project_id', 'device_id', 'message_id'],
                'telemetry_message_identities_sample_foreign',
            )
                ->references(['project_id', 'device_id', 'message_id'])
                ->on('telemetry_samples')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('telemetry_ingest_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->string('message_id', 128)->nullable();
            $table->string('status', 32);
            $table->jsonb('reason_codes');
            $table->timestampTz('received_at')->useCurrent();

            $table->index(
                ['project_id', 'device_id', 'received_at'],
                'telemetry_ingest_outcomes_project_device_received_index',
            );
            $table->foreign(['project_id', 'device_id'], 'telemetry_ingest_outcomes_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE telemetry_samples ADD CONSTRAINT telemetry_samples_status_check CHECK (status = 'persisted')",
            );
            DB::statement(
                "ALTER TABLE telemetry_ingest_outcomes ADD CONSTRAINT telemetry_ingest_outcomes_status_check CHECK (status IN ('persisted', 'rejected', 'duplicate', 'idempotency_conflict'))",
            );
        }
    }
};
