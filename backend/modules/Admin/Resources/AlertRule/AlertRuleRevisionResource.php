<?php

namespace Modules\Admin\Resources\AlertRule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\AlertRule\AlertRuleRevision;

/** @mixin AlertRuleRevision */
final class AlertRuleRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AlertRuleRevision $revision */
        $revision = $this->resource;

        return [
            'id' => (string) $revision->getKey(),
            'alert_rule_id' => $revision->alert_rule_id,
            'revision' => $revision->revision,
            'definition' => $revision->definition,
            'created_by' => $revision->created_by,
            'created_at' => $revision->created_at?->toISOString(),
        ];
    }
}
