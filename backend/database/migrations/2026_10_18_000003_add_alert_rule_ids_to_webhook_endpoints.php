<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null keeps the existing behaviour (alerts of every rule in the project); a list limits delivery
     * to alerts raised by those rules. Existing endpoints therefore keep receiving everything.
     */
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->jsonb('alert_rule_ids')->nullable();
        });
    }
};
