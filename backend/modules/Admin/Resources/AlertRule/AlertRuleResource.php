<?php

namespace Modules\Admin\Resources\AlertRule;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Admin\Models\AlertRule\AlertRule;

/** @mixin AlertRule */
final class AlertRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AlertRule $rule */
        $rule = $this->resource;

        return [
            'id' => (string) $rule->getKey(),
            'project_id' => $rule->project_id,
            'device_profile_id' => $rule->device_profile_id,
            'code' => $rule->code,
            'name' => $rule->name,
            'status' => $rule->status->value,
            'current_revision' => AlertRuleRevisionResource::make($rule->currentRevision),
            'revisions' => AlertRuleRevisionResource::collection($this->whenLoaded('revisions')),
            'archived_at' => $rule->archived_at?->toISOString(),
            'created_at' => $rule->created_at?->toISOString(),
            'updated_at' => $rule->updated_at?->toISOString(),
        ];
    }
}
