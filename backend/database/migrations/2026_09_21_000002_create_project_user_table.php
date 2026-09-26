<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        DB::table('projects')
            ->orderBy('id')
            ->get(['id', 'tenant_id'])
            ->each(static function (object $project): void {
                $memberIds = DB::table('tenant_user')
                    ->where('tenant_id', $project->tenant_id)
                    ->where('role', 'member')
                    ->pluck('user_id');

                foreach ($memberIds as $userId) {
                    DB::table('project_user')->insertOrIgnore([
                        'project_id' => $project->id,
                        'user_id' => $userId,
                        'role' => 'viewer',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }
};
