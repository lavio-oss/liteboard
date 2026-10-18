<?php

namespace Modules\Admin\Services\AlertRule;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Enums\Alert\AlertClearReason;
use Modules\Admin\Enums\Alert\AlertEventType;
use Modules\Admin\Enums\AlertRule\AlertRuleStatus;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\Alert\AlertOutboxWriter;
use Modules\Core\Models\User\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @phpstan-import-type RuleDefinition from AlertRuleRevision
 */
final class AlertRulePersistenceService
{
    public function __construct(private readonly AlertOutboxWriter $outboxWriter) {}

    /**
     * @param  array{code: string, name: string, device_profile_id: string}  $attributes
     * @param  RuleDefinition  $definition
     */
    public function create(Project $project, array $attributes, array $definition, User $actor): AlertRule
    {
        return DB::transaction(function () use ($project, $attributes, $definition, $actor): AlertRule {
            $rule = new AlertRule(['code' => $attributes['code'], 'name' => $attributes['name']]);
            $rule->setAttribute('project_id', $project->getKey());
            $rule->setAttribute('device_profile_id', $attributes['device_profile_id']);
            $rule->setAttribute('status', AlertRuleStatus::Active);
            $rule->save();

            $this->appendRevision($rule, 1, $definition, $actor);

            return $rule;
        });
    }

    /** @param  RuleDefinition  $definition */
    public function createRevision(AlertRule $rule, array $definition, User $actor): AlertRule
    {
        return DB::transaction(function () use ($rule, $definition, $actor): AlertRule {
            $locked = AlertRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === AlertRuleStatus::Archived) {
                throw new ConflictHttpException('ALERT_RULE_ARCHIVED');
            }

            $this->appendRevision($locked, (int) $locked->revisions()->max('revision') + 1, $definition, $actor);

            return $locked;
        });
    }

    /**
     * Clears active alerts as rule_archived and drops evaluation state in one transaction;
     * the evaluator holds FOR SHARE on the rule, so it cannot interleave.
     */
    public function archive(AlertRule $rule): AlertRule
    {
        return DB::transaction(function () use ($rule): AlertRule {
            $locked = AlertRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === AlertRuleStatus::Archived) {
                return $locked;
            }

            $now = now()->toImmutable();
            $locked->setAttribute('status', AlertRuleStatus::Archived);
            $locked->setAttribute('archived_at', $now);
            $locked->save();

            DB::table('rule_evaluation_states')->where('alert_rule_id', $locked->getKey())->delete();

            $activeAlerts = Alert::query()
                ->where('alert_rule_id', $locked->getKey())
                ->whereNull('cleared_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($activeAlerts as $alert) {
                $alert->setAttribute('cleared_at', $now);
                $alert->setAttribute('clear_reason', AlertClearReason::RuleArchived);
                $alert->save();
                $this->outboxWriter->record($alert, AlertEventType::Cleared, $now, data: [
                    'clear_reason' => AlertClearReason::RuleArchived->value,
                ]);
            }

            return $locked;
        });
    }

    /** @param  RuleDefinition  $definition */
    private function appendRevision(AlertRule $rule, int $number, array $definition, User $actor): void
    {
        $revision = $rule->revisions()->make(['revision' => $number, 'definition' => $definition]);
        $revision->setAttribute('project_id', $rule->project_id);
        $revision->setAttribute('created_by', $actor->getKey());
        $revision->save();

        $rule->setAttribute('current_revision_id', $revision->getKey());
        $rule->save();
    }
}
