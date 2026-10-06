<?php

namespace Modules\Admin\Models\DeviceProvisioning;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

#[Fillable(['profile_id', 'device_id', 'actor_id', 'action', 'reason'])]
final class DeviceProvisioningAudit extends Model
{
    use HasUuids;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectProvisioningProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProjectProvisioningProfile::class, 'profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
