<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        $createUsersView = <<<'SQL'
SELECT
    dc.token_hash,
    dc.mqtt_password_hash,
    d.id AS device_id,
    d.project_id
FROM device_credentials dc
JOIN devices d ON d.id = dc.device_id
JOIN projects p ON p.id = d.project_id
JOIN tenants t ON t.id = p.tenant_id
WHERE dc.status = 'active'
  AND d.status = 'active'
  AND p.status = 'active'
  AND t.status = 'active'
SQL;

        $createAclsView = <<<'SQL'
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/telemetry' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/heartbeat' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands/ack' AS topic,
    2 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic,
    1 AS rw
FROM v_mqtt_users u
UNION ALL
SELECT
    u.token_hash,
    'liteboard/' || u.project_id || '/devices/' || u.device_id || '/commands' AS topic,
    4 AS rw
FROM v_mqtt_users u
SQL;

        if ($driver === 'pgsql') {
            DB::statement("CREATE OR REPLACE VIEW v_mqtt_users AS {$createUsersView}");
            DB::statement("CREATE OR REPLACE VIEW v_mqtt_acls AS {$createAclsView}");
        } else {
            DB::statement('DROP VIEW IF EXISTS v_mqtt_acls');
            DB::statement('DROP VIEW IF EXISTS v_mqtt_users');
            DB::statement("CREATE VIEW v_mqtt_users AS {$createUsersView}");
            DB::statement("CREATE VIEW v_mqtt_acls AS {$createAclsView}");
        }
    }
};
