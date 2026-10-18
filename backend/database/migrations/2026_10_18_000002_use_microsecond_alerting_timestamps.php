<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * timestampTz() defaults to precision 0. For alerting that rounds the evaluator's job order and window
     * cursor to whole seconds (samples in one second are ordered by random UUID and can be dropped as
     * "late") and makes alert history of the same second ambiguous. Raising timestamptz precision is
     * binary-compatible in PostgreSQL, so no table rewrite happens.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = [
            'alert_rules' => ['archived_at'],
            'alert_rule_revisions' => ['created_at'],
            'telemetry_evaluation_jobs' => ['received_at', 'evaluated_at'],
            'rule_evaluation_states' => ['trigger_since', 'clear_since', 'cursor_received_at', 'updated_at'],
            'alerts' => ['opened_at', 'last_seen_at', 'cleared_at', 'acknowledged_at'],
            'alert_events' => ['occurred_at'],
            'outbox_events' => ['created_at', 'fanned_out_at'],
            'webhook_endpoints' => ['secret_rotated_at'],
            'webhook_deliveries' => ['next_attempt_at', 'lease_expires_at', 'delivered_at'],
            'webhook_delivery_attempts' => ['started_at', 'finished_at'],
        ];

        foreach ($columns as $table => $tableColumns) {
            foreach ($tableColumns as $column) {
                DB::statement("ALTER TABLE $table ALTER COLUMN $column TYPE timestamp(6) with time zone");
            }
        }
    }
};
