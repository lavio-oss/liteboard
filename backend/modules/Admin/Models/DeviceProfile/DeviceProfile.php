<?php

namespace Modules\Admin\Models\DeviceProfile;

use Database\Factories\DeviceProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Admin\Models\Project\Project;

#[Fillable(['code', 'name', 'description'])]
final class DeviceProfile extends Model
{
    /** @use HasFactory<DeviceProfileFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<DeviceProfile> */
    protected static function newFactory(): EloquentFactory
    {
        return DeviceProfileFactory::new();
    }

    protected static function booted(): void
    {
        self::deleting(static function (DeviceProfile $deviceProfile): void {
            if ($deviceProfile->versions()->exists()) {
                throw new LogicException('Device profiles with published versions cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<DeviceProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DeviceProfileVersion::class);
    }
}
