<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * timestampTz() defaults to precision 0, which rounds device event times to whole seconds and makes
     * latest-value and trace ordering arbitrary for samples within the same second.
     * Raising timestamptz precision is binary-compatible in PostgreSQL, so no table rewrite happens.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = [
            'telemetry_samples' => ['occurred_at', 'received_at'],
            'telemetry_latest_values' => ['occurred_at', 'received_at'],
            'telemetry_ingest_outcomes' => ['received_at'],
            'telemetry_message_identities' => ['created_at'],
            'telemetry_unresolved_messages' => ['received_at'],
        ];

        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                DB::statement("ALTER TABLE $table ALTER COLUMN $column TYPE timestamp(6) with time zone");
            }
        }
    }
};
