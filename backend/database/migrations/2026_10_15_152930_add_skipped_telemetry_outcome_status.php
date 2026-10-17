<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE telemetry_ingest_outcomes DROP CONSTRAINT telemetry_ingest_outcomes_status_check');
            DB::statement(
                "ALTER TABLE telemetry_ingest_outcomes ADD CONSTRAINT telemetry_ingest_outcomes_status_check CHECK (status IN ('persisted', 'rejected', 'duplicate', 'idempotency_conflict', 'unresolved', 'skipped'))",
            );
        }
    }
};
