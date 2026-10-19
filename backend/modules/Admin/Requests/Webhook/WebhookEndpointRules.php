<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Services\Webhook\WebhookDestinationGuard;
use Modules\Admin\Services\Webhook\WebhookDestinationRejected;

/**
 * Shared by create and update so both run the same SSRF check before an endpoint is saved.
 */
final class WebhookEndpointRules
{
    public const EVENT_TYPES = ['alert.opened', 'alert.cleared', 'alert.acknowledged', 'alert.severity_changed'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(string $presence): array
    {
        return [
            'name' => [$presence, 'string', 'min:2', 'max:255'],
            'url' => [$presence, 'string', 'max:2048', 'url:http,https'],
            'event_types' => [$presence, 'array', 'min:1'],
            'event_types.*' => ['required', 'string', 'distinct', 'in:'.implode(',', self::EVENT_TYPES)],
            'alert_rule_ids' => ['sometimes', 'array'],
            'alert_rule_ids.*' => ['required', 'uuid', 'distinct'],
            'enabled' => ['sometimes', 'boolean'],
            'secret' => ['prohibited'],
            'project_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** Rule filters may only name rules of the endpoint's own project. */
    public static function addProjectRules(Validator $validator, Project $project): void
    {
        $validator->addRules([
            'alert_rule_ids.*' => [
                Rule::exists('alert_rules', 'id')->where(static function (Builder $query) use ($project): void {
                    $query->where('project_id', $project->getKey());
                }),
            ],
        ]);
    }

    public static function checkDestination(Validator $validator): void
    {
        $url = $validator->getData()['url'] ?? null;

        if ($validator->errors()->has('url') || ! is_string($url)) {
            return;
        }

        try {
            app(WebhookDestinationGuard::class)->check($url);
        } catch (WebhookDestinationRejected $exception) {
            $validator->errors()->add('url', "The webhook destination is not allowed ($exception->reason).");
        }
    }
}
