<?php

namespace Modules\Admin\Models\Device;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Admin\Enums\Device\DeviceApprovalStatus;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\DeviceProfile\DeviceProfileVersion;
use Modules\Admin\Models\DeviceProvisioning\DeviceProvisioningAudit;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Telemetry\TelemetryIngestOutcome;
use Modules\Admin\Models\Telemetry\TelemetryLatestValue;
use Modules\Admin\Models\Telemetry\TelemetrySample;

#[Fillable([
    'equipment_asset_id',
    'device_profile_version_id',
    'name',
    'code',
    'description',
    'status',
    'approval_status',
    'connection_state',
    'last_seen_at',
    'allow_plaintext',
    'is_gateway',
    'gateway_device_id',
])]
final class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<Device> */
    protected static function newFactory(): EloquentFactory
    {
        return DeviceFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'approval_status' => DeviceApprovalStatus::class,
            'connection_state' => DeviceConnectionState::class,
            'last_seen_at' => 'immutable_datetime',
            'allow_plaintext' => 'boolean',
            'is_gateway' => 'boolean',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(self::class, 'gateway_device_id');
    }

    /** @return HasMany<Device, $this> */
    public function subDevices(): HasMany
    {
        return $this->hasMany(self::class, 'gateway_device_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function equipmentAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'equipment_asset_id');
    }

    /** @return BelongsTo<DeviceProfileVersion, $this> */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(DeviceProfileVersion::class, 'device_profile_version_id');
    }

    /** @return HasOne<DeviceCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(DeviceCredential::class);
    }

    /** @return HasMany<DeviceProvisioningAudit, $this> */
    public function provisioningAudits(): HasMany
    {
        return $this->hasMany(DeviceProvisioningAudit::class);
    }

    /** @return HasOne<DeviceProvisioningAudit, $this> */
    public function rejectionAudit(): HasOne
    {
        return $this->hasOne(DeviceProvisioningAudit::class)
            ->where('action', 'device_rejected');
    }

    /** @return HasMany<TelemetrySample, $this> */
    public function telemetrySamples(): HasMany
    {
        return $this->hasMany(TelemetrySample::class)->where('project_id', $this->getAttribute('project_id'));
    }

    /** @return HasMany<TelemetryLatestValue, $this> */
    public function latestValues(): HasMany
    {
        return $this->hasMany(TelemetryLatestValue::class)->where('project_id', $this->getAttribute('project_id'));
    }

    /** @return HasMany<TelemetryIngestOutcome, $this> */
    public function ingestOutcomes(): HasMany
    {
        return $this->hasMany(TelemetryIngestOutcome::class)->where('project_id', $this->getAttribute('project_id'));
    }
}
