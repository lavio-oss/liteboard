<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('mqtt_plaintext_enabled')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE platform_settings ADD CONSTRAINT platform_settings_single_row CHECK (id = 1)');

        DB::table('platform_settings')->insert([
            'id' => 1,
            'mqtt_plaintext_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
