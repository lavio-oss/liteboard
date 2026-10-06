<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_provisioning_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->unique()->constrained('projects')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('provision_key', 64)->unique();
            $table->string('provision_secret_hash', 255);
            $table->timestampTz('secret_rotated_at')->nullable();
            $table->timestamps();
        });
    }
};
