<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'role']);
            $table->dropColumn('role');
        });

        Schema::table('project_user', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'role']);
            $table->dropColumn('role');
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->uuid('role_id')->nullable(false)->change();
            $table->index(['user_id', 'role_id']);
        });

        Schema::table('project_user', function (Blueprint $table): void {
            $table->uuid('role_id')->nullable(false)->change();
            $table->index(['user_id', 'role_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['system_role']);
            $table->dropColumn('system_role');
        });
    }
};
