<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignUuid('parent_asset_id')->nullable();
            $table->string('asset_type', 32);
            $table->string('name');
            $table->string('code', 64);
            $table->string('location')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['project_id', 'asset_type', 'code']);
            $table->index(['project_id', 'parent_asset_id']);
            $table->index(['project_id', 'asset_type']);
        });

        Schema::table('assets', function (Blueprint $table): void {
            $table->foreign('parent_asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::dropIfExists('production_lines');
        Schema::dropIfExists('factories');
    }
};
