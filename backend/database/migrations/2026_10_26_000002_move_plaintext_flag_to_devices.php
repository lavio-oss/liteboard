<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('allow_plaintext')->default(false);
        });

        // Devices that exist today keep the plaintext access they already had.
        DB::table('devices')->update(['allow_plaintext' => true]);

        Schema::dropIfExists('platform_settings');
    }
};
