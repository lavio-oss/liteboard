<?php

namespace Modules\Core\Enums\Role;

/**
 * Roles seeded into every tenant. Owner, tenant Admin and project Admin are system roles:
 * they cannot be renamed or deleted, and Owner always holds every permission.
 */
enum DefaultRole
{
    case TenantOwner;
    case TenantAdmin;
    case TenantMember;
    case ProjectAdmin;
    case ProjectEngineer;
    case ProjectOperator;
    case ProjectViewer;

    public function key(): string
    {
        return match ($this) {
            self::TenantOwner => 'owner',
            self::TenantAdmin, self::ProjectAdmin => 'admin',
            self::TenantMember => 'member',
            self::ProjectEngineer => 'engineer',
            self::ProjectOperator => 'operator',
            self::ProjectViewer => 'viewer',
        };
    }

    public function label(): string
    {
        return ucfirst($this->key());
    }

    public function scope(): RoleScope
    {
        return match ($this) {
            self::TenantOwner, self::TenantAdmin, self::TenantMember => RoleScope::Tenant,
            default => RoleScope::Project,
        };
    }

    public function isSystem(): bool
    {
        return in_array($this, [self::TenantOwner, self::TenantAdmin, self::ProjectAdmin], true);
    }

    /**
     * Permissions a system role must keep so the tenant can never lock itself out.
     *
     * @return list<Permission>
     */
    public function lockedPermissions(): array
    {
        return match ($this) {
            self::TenantOwner => Permission::cases(),
            self::TenantAdmin => [Permission::UserManage, Permission::RoleManage],
            self::ProjectAdmin => [Permission::ProjectMemberManage],
            default => [],
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::TenantOwner, self::TenantAdmin => Permission::cases(),
            self::TenantMember => [],
            self::ProjectAdmin => array_values(array_filter(
                Permission::assignableTo(RoleScope::Project),
                static fn (Permission $permission): bool => $permission !== Permission::ProjectQuotaManage,
            )),
            self::ProjectEngineer => [
                Permission::ProjectView,
                Permission::ProjectUpdate,
                Permission::ProjectProvisioningManage,
                Permission::DeviceView,
                Permission::DeviceWrite,
                Permission::DeviceCredentialManage,
                Permission::AssetView,
                Permission::AssetWrite,
                Permission::DeviceProfileView,
                Permission::DeviceProfileWrite,
                Permission::AlertRuleView,
                Permission::AlertRuleWrite,
                Permission::AlertView,
                Permission::AlertAcknowledge,
                Permission::WebhookView,
            ],
            self::ProjectOperator => [
                ...self::ProjectViewer->permissions(),
                Permission::AlertAcknowledge,
            ],
            self::ProjectViewer => [
                Permission::ProjectView,
                Permission::DeviceView,
                Permission::AssetView,
                Permission::DeviceProfileView,
                Permission::AlertRuleView,
                Permission::AlertView,
            ],
        };
    }

    public static function fromScopeAndKey(RoleScope $scope, string $key): ?self
    {
        foreach (self::cases() as $role) {
            if ($role->scope() === $scope && $role->key() === $key) {
                return $role;
            }
        }

        return null;
    }
}
