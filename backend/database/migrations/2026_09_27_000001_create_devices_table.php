<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('equipment_asset_id')
                ->nullable()
                ->constrained('assets')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('name', 255);
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->string('connection_state', 32)->default('offline');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'equipment_asset_id']);
        });
    }
};
