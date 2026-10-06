<?php

namespace Modules\Admin\Models\Project;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Admin\Enums\Project\ProjectRole;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\DeviceProvisioning\DeviceProvisioningAudit;
use Modules\Admin\Models\DeviceProvisioning\ProjectProvisioningProfile;
use Modules\Core\Models\Tenant\Tenant;
use Modules\Core\Models\User\User;

#[Fillable(['name', 'description', 'status', 'max_devices_quota'])]
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<Project> */
    protected static function newFactory(): EloquentFactory
    {
        return ProjectFactory::new();
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasOne<ProjectProvisioningProfile, $this> */
    public function provisioningProfile(): HasOne
    {
        return $this->hasOne(ProjectProvisioningProfile::class);
    }

    /** @return HasMany<DeviceProvisioningAudit, $this> */
    public function provisioningAudits(): HasMany
    {
        return $this->hasMany(DeviceProvisioningAudit::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function hasMember(User $user): bool
    {
        return $user->isPlatformAdmin()
            || $this->users()->whereKey($user->getKey())->exists();
    }

    public function hasRole(User $user, ProjectRole ...$roles): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $this->users()
            ->whereKey($user->getKey())
            ->wherePivotIn('role', array_map(
                static fn (ProjectRole $role): string => $role->value,
                $roles,
            ))
            ->exists();
    }
}
