<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Enums\Role\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $tenantCount = DB::table('tenants')->count();

        if ($tenantCount > 1) {
            throw new RuntimeException("LiteBoard now runs a single tenant, but $tenantCount tenants exist. Merge them before migrating.");
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('key', 32)->nullable();
            $table->string('name', 64);
            $table->boolean('is_system')->default(false);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['tenant_id', 'scope', 'key']);
            $table->unique(['tenant_id', 'scope', 'name']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->nullable()->after('user_id')->constrained('roles')->restrictOnDelete();
        });

        Schema::table('project_user', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->nullable()->after('user_id')->constrained('roles')->restrictOnDelete();
        });

        $platformAdminIds = DB::table('users')->where('system_role', 'platform_admin')->pluck('id');

        if ($tenantCount === 0 && $platformAdminIds->isNotEmpty()) {
            DB::table('tenants')->insert([
                'id' => (string) Str::uuid7(),
                'name' => 'Default Organization',
                'code' => 'default',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tenantId = DB::table('tenants')->value('id');

        if ($tenantId === null) {
            return;
        }

        $roleIds = [];

        foreach (DefaultRole::cases() as $role) {
            $roleIds[$role->name] = (string) Str::uuid7();

            DB::table('roles')->insert([
                'id' => $roleIds[$role->name],
                'tenant_id' => $tenantId,
                'scope' => $role->scope()->value,
                'key' => $role->key(),
                'name' => $role->label(),
                'is_system' => $role->isSystem(),
                'permissions' => json_encode(array_map(
                    static fn (Permission $permission): string => $permission->value,
                    $role->permissions(),
                ), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([DefaultRole::TenantOwner, DefaultRole::TenantAdmin, DefaultRole::TenantMember] as $role) {
            DB::table('tenant_user')->where('role', $role->key())->update(['role_id' => $roleIds[$role->name]]);
        }

        foreach ([DefaultRole::ProjectAdmin, DefaultRole::ProjectEngineer, DefaultRole::ProjectOperator, DefaultRole::ProjectViewer] as $role) {
            DB::table('project_user')->where('role', $role->key())->update(['role_id' => $roleIds[$role->name]]);
        }

        foreach ($platformAdminIds as $userId) {
            $membership = ['tenant_id' => $tenantId, 'user_id' => $userId];
            $ownerRole = ['role' => 'owner', 'role_id' => $roleIds[DefaultRole::TenantOwner->name], 'updated_at' => now()];

            if (DB::table('tenant_user')->where($membership)->exists()) {
                DB::table('tenant_user')->where($membership)->update($ownerRole);
            } else {
                DB::table('tenant_user')->insert([...$membership, ...$ownerRole, 'created_at' => now()]);
            }
        }

        if (DB::table('tenant_user')->whereNull('role_id')->exists() || DB::table('project_user')->whereNull('role_id')->exists()) {
            throw new RuntimeException('Some memberships carry a role that has no default role to map to.');
        }
    }
};
