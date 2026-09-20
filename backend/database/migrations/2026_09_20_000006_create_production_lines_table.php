<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('factory_id')
                ->constrained('factories')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->unique(['factory_id', 'code']);
            $table->index('factory_id');
        });
    }
};
