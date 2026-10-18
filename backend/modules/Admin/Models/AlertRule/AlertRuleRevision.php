<?php

namespace Modules\Admin\Models\AlertRule;

use Carbon\CarbonImmutable;
use Database\Factories\AlertRuleRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * @phpstan-type RuleCondition array{operator: string, value: int|float, duration_seconds: int}
 * @phpstan-type RuleDefinition array{field: string, trigger: RuleCondition, clear: RuleCondition, max_sample_gap_seconds: int, severity: string}
 *
 * @property string $project_id
 * @property string $alert_rule_id
 * @property int $revision
 * @property RuleDefinition $definition
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['revision', 'definition'])]
final class AlertRuleRevision extends Model
{
    /** @use HasFactory<AlertRuleRevisionFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /** @return EloquentFactory<AlertRuleRevision> */
    protected static function newFactory(): EloquentFactory
    {
        return AlertRuleRevisionFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(static function (AlertRuleRevision $revision): void {
            $number = $revision->getAttribute('revision');

            if (! is_int($number) || $number < 1) {
                throw new InvalidArgumentException('Alert rule revisions must be positive integers.');
            }
        });

        self::updating(static function (): void {
            throw new LogicException('Alert rule revisions are immutable.');
        });

        self::deleting(static function (): void {
            throw new LogicException('Alert rule revisions cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'definition' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }
}
