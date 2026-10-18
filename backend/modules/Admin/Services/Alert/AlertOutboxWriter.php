<?php

namespace Modules\Admin\Services\Alert;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Admin\Enums\Alert\AlertEventType;
use Modules\Admin\Models\Alert\Alert;
use Modules\Admin\Models\Alert\AlertEvent;
use Modules\Admin\Models\Alert\OutboxEvent;
use Modules\Admin\Models\AlertRule\AlertRule;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;
use Modules\Admin\Models\Project\Project;
use Modules\Core\Models\User\User;

/**
 * Control-plane producer of alert history and outbox events. The payload shape must stay
 * identical to telemetry-service/app/storage/alerting.py::alert_payload.
 */
final class AlertOutboxWriter
{
    /**
     * Must run inside the caller's transaction so history, outbox and the alert change commit together.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(
        Alert $alert,
        AlertEventType $type,
        CarbonImmutable $occurredAt,
        ?User $actor = null,
        array $data = [],
    ): void {
        $event = new AlertEvent;
        $event->forceFill([
            'project_id' => $alert->project_id,
            'alert_id' => $alert->getKey(),
            'type' => $type,
            'occurred_at' => $occurredAt,
            'actor_id' => $actor?->getKey(),
            'alert_rule_revision_id' => $alert->alert_rule_revision_id,
            'data' => $data,
        ])->save();

        $eventId = (string) Str::uuid();
        $alertId = (string) $alert->getKey();
        OutboxEvent::query()->insertOrIgnore([
            'id' => $eventId,
            'project_id' => $alert->project_id,
            'event_type' => $type->outboxType(),
            'aggregate_id' => $alertId,
            'idempotency_key' => "alert:$alertId:$type->value",
            'payload' => json_encode($this->payload($alert, $eventId, $type, $occurredAt), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Alert $alert, string $eventId, AlertEventType $type, CarbonImmutable $occurredAt): array
    {
        $project = Project::query()->findOrFail($alert->project_id);
        $rule = AlertRule::query()->findOrFail($alert->alert_rule_id);
        $revision = AlertRuleRevision::query()->findOrFail($alert->alert_rule_revision_id);

        return [
            'id' => $eventId,
            'type' => $type->outboxType(),
            'occurred_at' => $occurredAt->toISOString(),
            'tenant_id' => (string) $project->getAttribute('tenant_id'),
            'project_id' => $alert->project_id,
            'alert' => [
                'id' => (string) $alert->getKey(),
                'rule_id' => $alert->alert_rule_id,
                'rule_code' => $rule->code,
                'rule_revision' => $revision->revision,
                'severity' => $alert->severity->value,
                'device_id' => $alert->device_id,
                'equipment_asset_id' => $alert->equipment_asset_id,
                'opened_at' => $alert->opened_at->toISOString(),
                'cleared_at' => $alert->cleared_at?->toISOString(),
                'clear_reason' => $alert->clear_reason?->value,
                'acknowledged_at' => $alert->acknowledged_at?->toISOString(),
            ],
        ];
    }
}
