<?php

namespace Modules\Core\Models\Role;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Enums\Role\DefaultRole;
use Modules\Core\Enums\Role\Permission;
use Modules\Core\Enums\Role\RoleScope;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

/**
 * @property RoleScope $scope
 * @property string|null $key
 * @property string $name
 * @property bool $is_system
 * @property list<string> $permissions
 */
#[Fillable(['scope', 'key', 'name', 'is_system', 'permissions', 'created_by'])]
final class Role extends Model
{
    use HasUuids;

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function defaultRole(): ?DefaultRole
    {
        return $this->key === null ? null : DefaultRole::fromScopeAndKey($this->scope, $this->key);
    }

    public function isOwner(): bool
    {
        return $this->defaultRole() === DefaultRole::TenantOwner;
    }

    /** @return list<Permission> */
    public function grantedPermissions(): array
    {
        return $this->isOwner()
            ? Permission::cases()
            : array_map(static fn (string $permission): Permission => Permission::from($permission), $this->permissions);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'is_system' => 'boolean',
            'permissions' => 'array',
        ];
    }
}
