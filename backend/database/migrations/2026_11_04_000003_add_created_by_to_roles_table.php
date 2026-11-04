<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            // Null for the default roles the system seeds, and for roles whose creator was deleted.
            $table->foreignUuid('created_by')->nullable()->after('is_system')->constrained('users')->nullOnDelete();
        });
    }
};
