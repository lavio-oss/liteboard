<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')
                ->unique()
                ->constrained('devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('credential_type', 32)->default('access_token');
            $table->char('token_hash', 64)->unique();
            $table->string('mqtt_password_hash', 255);
            $table->text('encrypted_token');
            $table->string('status', 32)->default('active');
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
        });
    }
};
