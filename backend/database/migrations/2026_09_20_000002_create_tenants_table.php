<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->string('code', 64)->unique();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreign('parent_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();
        });
    }
};
