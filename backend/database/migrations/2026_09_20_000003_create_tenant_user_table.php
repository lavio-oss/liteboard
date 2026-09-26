<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }
};
