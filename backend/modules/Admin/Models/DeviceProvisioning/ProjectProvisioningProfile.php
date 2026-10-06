<?php

namespace Modules\Admin\Models\DeviceProvisioning;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Project\Project;

#[Fillable(['enabled', 'provision_key', 'provision_secret_hash', 'secret_rotated_at'])]
#[Hidden(['provision_secret_hash'])]
final class ProjectProvisioningProfile extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'secret_rotated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<DeviceProvisioningReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(DeviceProvisioningReceipt::class, 'profile_id');
    }
}
