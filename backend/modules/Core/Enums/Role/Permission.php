<?php

namespace Modules\Core\Enums\Role;

/**
 * Tenant-scoped permissions can only be held by tenant roles. Project-scoped permissions can be
 * held by project roles, or by tenant roles, in which case they apply to every project of the tenant.
 */
enum Permission: string
{
    case TenantUpdate = 'tenant.update';
    case UserManage = 'user.manage';
    case RoleManage = 'role.manage';
    case ProjectCreate = 'project.create';
    case SystemDiagnostics = 'system.diagnostics';

    case ProjectView = 'project.view';
    case ProjectUpdate = 'project.update';
    case ProjectDelete = 'project.delete';
    case ProjectMemberManage = 'project.member.manage';
    case ProjectQuotaManage = 'project.quota.manage';
    case ProjectProvisioningManage = 'project.provisioning.manage';
    case DeviceView = 'device.view';
    case DeviceWrite = 'device.write';
    case DeviceDelete = 'device.delete';
    case DeviceCredentialManage = 'device.credential.manage';
    case AssetView = 'asset.view';
    case AssetWrite = 'asset.write';
    case AssetDelete = 'asset.delete';
    case DeviceProfileView = 'device_profile.view';
    case DeviceProfileWrite = 'device_profile.write';
    case DeviceProfileDelete = 'device_profile.delete';
    case AlertRuleView = 'alert_rule.view';
    case AlertRuleWrite = 'alert_rule.write';
    case AlertView = 'alert.view';
    case AlertAcknowledge = 'alert.acknowledge';
    case WebhookView = 'webhook.view';
    case WebhookManage = 'webhook.manage';

    public function scope(): RoleScope
    {
        return match ($this) {
            self::TenantUpdate,
            self::UserManage,
            self::RoleManage,
            self::ProjectCreate,
            self::SystemDiagnostics => RoleScope::Tenant,
            default => RoleScope::Project,
        };
    }

    /** @return list<self> */
    public static function assignableTo(RoleScope $scope): array
    {
        return $scope === RoleScope::Tenant
            ? self::cases()
            : array_values(array_filter(
                self::cases(),
                static fn (self $permission): bool => $permission->scope() === RoleScope::Project,
            ));
    }
}
