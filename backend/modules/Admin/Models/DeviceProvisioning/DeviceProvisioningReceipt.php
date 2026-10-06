<?php

namespace Modules\Admin\Models\DeviceProvisioning;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Models\Device\Device;

/** @property CarbonImmutable $expires_at */
#[Fillable(['device_id', 'request_id', 'device_code', 'firmware_version', 'payload_fingerprint', 'token_hash', 'expires_at'])]
final class DeviceProvisioningReceipt extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ProjectProvisioningProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProjectProvisioningProfile::class, 'profile_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
