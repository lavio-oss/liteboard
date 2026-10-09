<?php

namespace Modules\Admin\Models\DeviceProfile;

use Database\Factories\DeviceProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;

/**
 * @property int $version
 * @property string $schema_code
 * @property array{
 *     strict_mode: bool,
 *     fields: array<string, array{type: string, unit: string, required: bool, min?: int|float, max?: int|float, allowed_values?: list<string>}>
 * } $definition
 * @property string|null $compatibility_note
 */
#[Fillable(['version', 'schema_code', 'definition', 'compatibility_note'])]
final class DeviceProfileVersion extends Model
{
    /** @use HasFactory<DeviceProfileVersionFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<DeviceProfileVersion> */
    protected static function newFactory(): EloquentFactory
    {
        return DeviceProfileVersionFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(static function (DeviceProfileVersion $profileVersion): void {
            $version = $profileVersion->getAttribute('version');

            if (! is_int($version) || $version < 1) {
                throw new InvalidArgumentException('Device profile versions must be positive integers.');
            }
        });

        self::updating(static function (DeviceProfileVersion $profileVersion): void {
            throw new LogicException('Device profile versions are immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'definition' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<DeviceProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DeviceProfile::class, 'device_profile_id');
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'device_profile_version_id');
    }
}
