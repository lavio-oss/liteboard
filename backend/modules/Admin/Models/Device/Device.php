<?php

namespace Modules\Admin\Models\Device;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Admin\Enums\Device\DeviceConnectionState;
use Modules\Admin\Enums\Device\DeviceStatus;
use Modules\Admin\Models\Asset\Asset;
use Modules\Admin\Models\Project\Project;

#[Fillable([
    'equipment_asset_id',
    'name',
    'code',
    'description',
    'status',
    'connection_state',
    'last_seen_at',
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
            'connection_state' => DeviceConnectionState::class,
            'last_seen_at' => 'immutable_datetime',
        ];
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

    /** @return HasOne<DeviceCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(DeviceCredential::class);
    }
}
