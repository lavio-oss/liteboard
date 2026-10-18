<?php

namespace Modules\Admin\Models\Alert;

use Carbon\CarbonImmutable;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Enums\Alert\AlertClearReason;
use Modules\Admin\Enums\AlertRule\AlertSeverity;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

/**
 * Opened, touched and cleared by the telemetry-service evaluator; the control plane acknowledges and archives.
 *
 * @property string $project_id
 * @property string $device_id
 * @property string|null $equipment_asset_id
 * @property string $alert_rule_id
 * @property string $alert_rule_revision_id
 * @property string|null $device_profile_version_id
 * @property AlertSeverity $severity
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $cleared_at
 * @property AlertClearReason|null $clear_reason
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property string|null $open_sample_id
 * @property string|null $open_message_id
 * @property string|null $clear_sample_id
 * @property string|null $clear_message_id
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, HasUuids;

    /** @return EloquentFactory<Alert> */
    protected static function newFactory(): EloquentFactory
    {
        return AlertFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'clear_reason' => AlertClearReason::class,
            'opened_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'evidence' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    /** @return BelongsTo<AlertRuleRevision, $this> */
    public function ruleRevision(): BelongsTo
    {
        return $this->belongsTo(AlertRuleRevision::class, 'alert_rule_revision_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }
}
