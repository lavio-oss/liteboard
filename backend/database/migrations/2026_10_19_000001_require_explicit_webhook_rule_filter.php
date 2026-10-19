<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the implicit "all rules" mode (null): an endpoint now only receives the rules it lists, so a new
     * rule is not delivered anywhere until it is connected. Existing null endpoints keep today's deliveries
     * by listing the project's active rules.
     */
    public function up(): void
    {
        $endpoints = DB::table('webhook_endpoints')->whereNull('alert_rule_ids')->pluck('project_id', 'id');

        foreach ($endpoints as $endpointId => $projectId) {
            $ruleIds = DB::table('alert_rules')
                ->where('project_id', $projectId)
                ->where('status', 'active')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            DB::table('webhook_endpoints')->where('id', $endpointId)->update(['alert_rule_ids' => json_encode($ruleIds)]);
        }

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->jsonb('alert_rule_ids')->default('[]')->nullable(false)->change();
        });
    }
};
