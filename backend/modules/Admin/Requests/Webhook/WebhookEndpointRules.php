<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Validation\Validator;
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
            'enabled' => ['sometimes', 'boolean'],
            'secret' => ['prohibited'],
            'project_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
        ];
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
