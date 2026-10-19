<?php

namespace Modules\Admin\Services\Webhook;

use Illuminate\Support\Str;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;

final class WebhookEndpointService
{
    /**
     * @param  array{name: string, url: string, event_types: list<string>, alert_rule_ids?: list<string>, enabled?: bool}  $attributes
     * @return array{0: WebhookEndpoint, 1: string} the endpoint and its plaintext secret, shown only once
     */
    public function create(Project $project, array $attributes): array
    {
        $secret = $this->newSecret();
        $endpoint = new WebhookEndpoint($attributes);
        $endpoint->setAttribute('project_id', $project->getKey());
        $endpoint->setAttribute('secret', $secret);
        $endpoint->setAttribute('enabled', $attributes['enabled'] ?? true);
        // An endpoint only receives the rules it lists; a new one starts with none.
        $endpoint->setAttribute('alert_rule_ids', $attributes['alert_rule_ids'] ?? []);
        $endpoint->save();

        return [$endpoint, $secret];
    }

    /** @param  array{name?: string, url?: string, event_types?: list<string>, alert_rule_ids?: list<string>, enabled?: bool}  $attributes */
    public function update(WebhookEndpoint $endpoint, array $attributes): WebhookEndpoint
    {
        $endpoint->fill($attributes);
        $endpoint->save();

        return $endpoint;
    }

    /** Deliveries sent after rotation are signed with the new secret immediately. */
    public function rotateSecret(WebhookEndpoint $endpoint): string
    {
        $secret = $this->newSecret();
        $endpoint->setAttribute('secret', $secret);
        $endpoint->setAttribute('secret_rotated_at', now()->toImmutable());
        $endpoint->save();

        return $secret;
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
