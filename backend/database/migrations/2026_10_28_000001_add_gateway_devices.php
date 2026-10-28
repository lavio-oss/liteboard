<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_mqtt_acls');
        DB::statement('DROP VIEW IF EXISTS v_mqtt_users');

        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('is_gateway')->default(false);
            // Sub-devices always live in their gateway's project; only DevicePersistenceService sets this column.
            $table->foreignUuid('gateway_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->index(['project_id', 'gateway_device_id'], 'devices_project_gateway_index');
        });

        DB::statement(<<<'SQL'
CREATE VIEW v_mqtt_users AS
SELECT
    dc.token_hash,
    dc.mqtt_password_hash,
    d.id AS device_id,
    d.project_id,
    d.is_gateway
FROM device_credentials dc
JOIN devices d ON d.id = dc.device_id
JOIN projects p ON p.id = d.project_id
JOIN tenants t ON t.id = p.tenant_id
WHERE dc.status = 'active'
  AND d.status = 'active'
  AND d.approval_status = 'approved'
  AND p.status = 'active'
  AND t.status = 'active'
SQL);

        DB::statement(<<<'SQL'
CREATE VIEW v_mqtt_acls AS
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/telemetry' AS topic, 2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/heartbeat' AS topic, 2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands/ack' AS topic, 2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic, 1 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic, 4 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/gateway/connect' AS topic, 2 AS rw
FROM v_mqtt_users u WHERE u.is_gateway
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/gateway/telemetry' AS topic, 2 AS rw
FROM v_mqtt_users u WHERE u.is_gateway
UNION ALL
SELECT u.token_hash, 'liteboard/' || u.project_id || '/devices/' || u.device_id || '/gateway/disconnect' AS topic, 2 AS rw
FROM v_mqtt_users u WHERE u.is_gateway
SQL);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE telemetry_unresolved_messages DROP CONSTRAINT telemetry_unresolved_messages_reason_check');
            DB::statement(
                "ALTER TABLE telemetry_unresolved_messages ADD CONSTRAINT telemetry_unresolved_messages_reason_check CHECK (reason IN ('invalid_topic', 'device_not_found', 'project_mismatch', 'gateway_not_found', 'gateway_payload_invalid', 'gateway_mismatch', 'sub_device_rejected'))",
            );
        }
    }
};
