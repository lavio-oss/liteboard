<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $viewDefinitions = [];

        if (DB::getDriverName() === 'sqlite') {
            foreach (DB::select(
                'SELECT name, sql FROM sqlite_master WHERE type = ? AND name IN (?, ?)',
                ['view', 'v_mqtt_users', 'v_mqtt_acls'],
            ) as $view) {
                if (is_string($view->name) && is_string($view->sql)) {
                    $viewDefinitions[$view->name] = $view->sql;
                }
            }

            DB::statement('DROP VIEW IF EXISTS v_mqtt_acls');
            DB::statement('DROP VIEW IF EXISTS v_mqtt_users');
        }

        try {
            Schema::table('devices', function (Blueprint $table): void {
                $table->uuid('device_profile_version_id')->nullable();
                $table->index(
                    ['project_id', 'device_profile_version_id'],
                    'devices_project_profile_version_index',
                );
                $table->foreign(
                    ['project_id', 'device_profile_version_id'],
                    'devices_project_profile_version_foreign',
                )
                    ->references(['project_id', 'id'])
                    ->on('device_profile_versions')
                    ->cascadeOnUpdate();
            });
        } finally {
            foreach (['v_mqtt_users', 'v_mqtt_acls'] as $viewName) {
                if (isset($viewDefinitions[$viewName])) {
                    DB::statement($viewDefinitions[$viewName]);
                }
            }
        }
    }
};
