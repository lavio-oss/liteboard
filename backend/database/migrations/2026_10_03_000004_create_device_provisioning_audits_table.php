<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provisioning_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignUuid('profile_id')->nullable()->constrained('project_provisioning_profiles')->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }
};
