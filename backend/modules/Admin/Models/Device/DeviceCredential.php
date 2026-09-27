<?php

namespace Modules\Admin\Models\Device;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Enums\Device\DeviceCredentialType;

#[Fillable([
    'credential_type',
    'token_hash',
    'mqtt_password_hash',
    'encrypted_token',
    'status',
    'rotated_at',
    'revoked_at',
])]
#[Hidden([
    'token_hash',
    'mqtt_password_hash',
    'encrypted_token',
])]
final class DeviceCredential extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credential_type' => DeviceCredentialType::class,
            'status' => DeviceCredentialStatus::class,
            'encrypted_token' => 'encrypted',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
