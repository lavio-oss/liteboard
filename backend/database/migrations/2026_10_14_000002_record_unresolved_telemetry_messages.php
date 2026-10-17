<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central: topic IDs here are unverified strings and are never joined to tenant data.
        Schema::create('telemetry_unresolved_messages', function (Blueprint $table): void {
            $table->uuid('processing_id')->primary();
            $table->string('topic', 255);
            $table->string('reason', 32);
            $table->unsignedInteger('payload_size');
            $table->jsonb('stages')->default('[]');
            $table->timestampTz('received_at');

            $table->index('received_at', 'telemetry_unresolved_messages_received_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE telemetry_ingest_outcomes DROP CONSTRAINT telemetry_ingest_outcomes_status_check');
            DB::statement(
                "ALTER TABLE telemetry_ingest_outcomes ADD CONSTRAINT telemetry_ingest_outcomes_status_check CHECK (status IN ('persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved'))",
            );
            DB::statement(
                "ALTER TABLE telemetry_unresolved_messages ADD CONSTRAINT telemetry_unresolved_messages_reason_check CHECK (reason IN ('invalid_topic', 'device_not_found', 'project_mismatch'))",
            );
        }
    }
};
