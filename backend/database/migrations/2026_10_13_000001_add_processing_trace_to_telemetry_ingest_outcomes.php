<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_ingest_outcomes', function (Blueprint $table): void {
            $table->uuid('processing_id')->nullable();
            $table->jsonb('stages')->default('[]');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE telemetry_ingest_outcomes SET processing_id = gen_random_uuid() WHERE processing_id IS NULL');
        } else {
            DB::table('telemetry_ingest_outcomes')->whereNull('processing_id')->orderBy('id')->chunkById(
                500,
                static function ($outcomes): void {
                    foreach ($outcomes as $outcome) {
                        DB::table('telemetry_ingest_outcomes')
                            ->where('id', $outcome->id)
                            ->update(['processing_id' => (string) Str::uuid()]);
                    }
                },
            );
        }

        Schema::table('telemetry_ingest_outcomes', function (Blueprint $table): void {
            $table->uuid('processing_id')->nullable(false)->change();
            $table->unique('processing_id', 'telemetry_ingest_outcomes_processing_id_unique');
            $table->index(['device_id', 'message_id'], 'telemetry_ingest_outcomes_device_message_index');
        });
    }
};
