<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->uuid('device_profile_id');
            $table->string('code', 64);
            $table->string('name', 255);
            $table->string('status', 16)->default('active');
            $table->uuid('current_revision_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'code'], 'alert_rules_project_code_unique');
            $table->unique(['project_id', 'id'], 'alert_rules_project_id_id_unique');
            $table->index(['device_profile_id', 'status'], 'alert_rules_profile_status_index');
            $table->foreign(['project_id', 'device_profile_id'], 'alert_rules_project_profile_foreign')
                ->references(['project_id', 'id'])
                ->on('device_profiles')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('alert_rule_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('alert_rule_id');
            $table->integer('revision');
            $table->jsonb('definition');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['alert_rule_id', 'revision'], 'alert_rule_revisions_rule_revision_unique');
            $table->foreign(['project_id', 'alert_rule_id'], 'alert_rule_revisions_project_rule_foreign')
                ->references(['project_id', 'id'])
                ->on('alert_rules')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::table('alert_rules', function (Blueprint $table): void {
            $table->foreign('current_revision_id', 'alert_rules_current_revision_foreign')
                ->references('id')
                ->on('alert_rule_revisions');
        });

        Schema::create('telemetry_evaluation_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('sample_id')->unique('telemetry_evaluation_jobs_sample_unique');
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->uuid('device_profile_version_id');
            $table->uuid('equipment_asset_id')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('evaluated_at')->nullable();

            $table->index(['device_id', 'received_at'], 'telemetry_evaluation_jobs_device_received_index');
            $table->foreign('sample_id', 'telemetry_evaluation_jobs_sample_foreign')
                ->references('id')
                ->on('telemetry_samples')
                ->cascadeOnDelete();
            $table->foreign(['project_id', 'device_id'], 'telemetry_evaluation_jobs_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('rule_evaluation_states', function (Blueprint $table): void {
            $table->uuid('device_id');
            $table->uuid('alert_rule_id');
            $table->uuid('project_id');
            $table->uuid('alert_rule_revision_id');
            $table->timestampTz('trigger_since')->nullable();
            $table->timestampTz('clear_since')->nullable();
            $table->timestampTz('cursor_received_at')->nullable();
            $table->uuid('cursor_sample_id')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['device_id', 'alert_rule_id']);
            $table->foreign(['project_id', 'device_id'], 'rule_evaluation_states_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(['project_id', 'alert_rule_id'], 'rule_evaluation_states_project_rule_foreign')
                ->references(['project_id', 'id'])
                ->on('alert_rules')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->uuid('equipment_asset_id')->nullable();
            $table->uuid('alert_rule_id');
            $table->uuid('alert_rule_revision_id');
            $table->uuid('device_profile_version_id')->nullable();
            $table->string('severity', 16);
            $table->timestampTz('opened_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('cleared_at')->nullable();
            $table->string('clear_reason', 32)->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            // Sample ids are kept without FK so alerts survive future sample retention.
            $table->uuid('open_sample_id')->nullable();
            $table->string('open_message_id', 128)->nullable();
            $table->uuid('clear_sample_id')->nullable();
            $table->string('clear_message_id', 128)->nullable();
            $table->jsonb('evidence');
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'alerts_project_id_id_unique');
            $table->index(['project_id', 'opened_at'], 'alerts_project_opened_index');
            $table->index(['device_id', 'alert_rule_id'], 'alerts_device_rule_index');
            $table->foreign(['project_id', 'device_id'], 'alerts_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign(['project_id', 'alert_rule_id'], 'alerts_project_rule_foreign')
                ->references(['project_id', 'id'])
                ->on('alert_rules')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreign('alert_rule_revision_id', 'alerts_rule_revision_foreign')
                ->references('id')
                ->on('alert_rule_revisions');
            $table->foreign('equipment_asset_id', 'alerts_equipment_asset_foreign')
                ->references('id')
                ->on('assets')
                ->nullOnDelete();
        });

        Schema::create('alert_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('alert_id');
            $table->string('type', 32);
            $table->timestampTz('occurred_at');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('alert_rule_revision_id');
            $table->uuid('sample_id')->nullable();
            $table->string('message_id', 128)->nullable();
            $table->jsonb('data');

            $table->index(['alert_id', 'occurred_at'], 'alert_events_alert_occurred_index');
            $table->foreign(['project_id', 'alert_id'], 'alert_events_project_alert_foreign')
                ->references(['project_id', 'id'])
                ->on('alerts')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->uuid('aggregate_id');
            $table->string('idempotency_key', 128)->unique('outbox_events_idempotency_key_unique');
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('fanned_out_at')->nullable();

            $table->index(['fanned_out_at', 'created_at'], 'outbox_events_fan_out_index');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('url', 2048);
            $table->text('secret');
            $table->jsonb('event_types');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'id'], 'webhook_endpoints_project_id_id_unique');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('outbox_event_id');
            $table->uuid('webhook_endpoint_id');
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('next_attempt_at');
            $table->timestampTz('lease_expires_at')->nullable();
            $table->integer('last_status_code')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['outbox_event_id', 'webhook_endpoint_id'], 'webhook_deliveries_event_endpoint_unique');
            $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_status_next_attempt_index');
            $table->index(['webhook_endpoint_id', 'created_at'], 'webhook_deliveries_endpoint_created_index');
            $table->foreign('outbox_event_id', 'webhook_deliveries_outbox_event_foreign')
                ->references('id')
                ->on('outbox_events')
                ->cascadeOnDelete();
            $table->foreign(['project_id', 'webhook_endpoint_id'], 'webhook_deliveries_project_endpoint_foreign')
                ->references(['project_id', 'id'])
                ->on('webhook_endpoints')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_delivery_id')
                ->constrained('webhook_deliveries')
                ->cascadeOnDelete();
            $table->integer('attempt_no');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->integer('status_code')->nullable();
            $table->string('error', 500)->nullable();
            $table->integer('duration_ms');

            $table->unique(['webhook_delivery_id', 'attempt_no'], 'webhook_delivery_attempts_delivery_attempt_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_status_check CHECK (status IN ('active', 'archived'))",
            );
            DB::statement(
                'ALTER TABLE alert_rule_revisions ADD CONSTRAINT alert_rule_revisions_revision_positive_check CHECK (revision > 0)',
            );
            DB::statement(
                "ALTER TABLE alerts ADD CONSTRAINT alerts_clear_reason_check CHECK (clear_reason IN ('condition_cleared', 'device_offline', 'rule_archived'))",
            );
            DB::statement(
                'ALTER TABLE alerts ADD CONSTRAINT alerts_cleared_consistency_check CHECK ((cleared_at IS NULL) = (clear_reason IS NULL))',
            );
            DB::statement(
                'CREATE UNIQUE INDEX alerts_one_active_per_device_rule ON alerts (device_id, alert_rule_id) WHERE cleared_at IS NULL',
            );
            DB::statement(
                'CREATE INDEX telemetry_evaluation_jobs_pending_index ON telemetry_evaluation_jobs (id) WHERE evaluated_at IS NULL',
            );
            DB::statement(
                "ALTER TABLE alert_events ADD CONSTRAINT alert_events_type_check CHECK (type IN ('opened', 'severity_changed', 'acknowledged', 'cleared'))",
            );
            DB::statement(
                "ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'in_flight', 'succeeded', 'failed', 'exhausted'))",
            );
        }
    }
};
