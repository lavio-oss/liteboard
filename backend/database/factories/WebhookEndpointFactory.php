<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Admin\Models\Webhook\WebhookEndpoint;

/** @extends Factory<WebhookEndpoint> */
final class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array{name: string, url: string, secret: string, event_types: list<string>, enabled: bool}
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://hooks.example.com/liteboard',
            'secret' => 'whsec_'.fake()->regexify('[A-Za-z0-9]{40}'),
            'event_types' => ['alert.opened', 'alert.cleared', 'alert.acknowledged', 'alert.severity_changed'],
            'enabled' => true,
        ];
    }
}
