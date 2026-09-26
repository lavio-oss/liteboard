<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('location')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index('project_id');
        });
    }
};
