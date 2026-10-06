<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provisioning_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('project_provisioning_profiles')->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('device_code', 64);
            $table->string('firmware_version', 64)->nullable();
            $table->char('payload_fingerprint', 64);
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestamps();
            $table->unique(['profile_id', 'request_id']);
            $table->index(['profile_id', 'device_code']);
        });
    }
};
