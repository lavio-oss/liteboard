<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_samples', function (Blueprint $table): void {
            $table->uuid('device_profile_version_id')->nullable()->change();
        });
    }
};
