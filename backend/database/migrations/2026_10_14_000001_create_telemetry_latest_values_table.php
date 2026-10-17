<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_latest_values', function (Blueprint $table): void {
            $table->uuid('project_id');
            $table->uuid('device_id');
            $table->string('metric', 64);
            $table->jsonb('value');
            // No FK to telemetry_samples: the latest value must survive sample retention.
            $table->uuid('sample_id');
            $table->string('message_id', 128);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');

            $table->primary(['device_id', 'metric']);
            $table->foreign(['project_id', 'device_id'], 'telemetry_latest_values_project_device_foreign')
                ->references(['project_id', 'id'])
                ->on('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                INSERT INTO telemetry_latest_values (
                    project_id, device_id, metric, value, sample_id, message_id, occurred_at, received_at
                )
                SELECT DISTINCT ON (s.device_id, kv.key)
                    s.project_id, s.device_id, kv.key, kv.value, s.id, s.message_id, s.occurred_at, s.received_at
                FROM telemetry_samples s
                CROSS JOIN LATERAL jsonb_each(s."values") AS kv(key, value)
                ORDER BY s.device_id, kv.key, s.occurred_at DESC, s.received_at DESC, s.id DESC
                SQL);
        }
    }
};
