<?php

namespace Modules\Admin\Models\AlertRule;

use Carbon\CarbonImmutable;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Enums\AlertRule\AlertRuleStatus;
use Modules\Admin\Models\DeviceProfile\DeviceProfile;
use Modules\Admin\Models\Project\Project;

/**
 * @property string $project_id
 * @property string $device_profile_id
 * @property string $code
 * @property string $name
 * @property AlertRuleStatus $status
 * @property string|null $current_revision_id
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['code', 'name'])]
final class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<AlertRule> */
    protected static function newFactory(): EloquentFactory
    {
        return AlertRuleFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AlertRuleStatus::class,
            'archived_at' => 'immutable_datetime',
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
    public function deviceProfile(): BelongsTo
    {
        return $this->belongsTo(DeviceProfile::class);
    }

    /** @return HasMany<AlertRuleRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(AlertRuleRevision::class);
    }

    /** @return BelongsTo<AlertRuleRevision, $this> */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(AlertRuleRevision::class, 'current_revision_id');
    }
}
