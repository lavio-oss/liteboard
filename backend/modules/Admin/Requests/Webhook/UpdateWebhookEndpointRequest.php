<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

final class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $endpoint = $this->route('webhookEndpoint');
        $user = $this->user();

        return $endpoint instanceof WebhookEndpoint
            && $user instanceof User
            && Gate::forUser($user)->allows('update', $endpoint);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return WebhookEndpointRules::rules('sometimes');
    }

    /** @return array{name?: string, url?: string, event_types?: list<string>, alert_rule_ids?: list<string>, enabled?: bool} */
    public function endpointAttributes(): array
    {
        /** @var array{name?: string, url?: string, event_types?: list<string>, alert_rule_ids?: list<string>, enabled?: bool} $attributes */
        $attributes = $this->validated();

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            throw new LogicException('The project route binding is required.');
        }

        WebhookEndpointRules::addProjectRules($validator, $project);
        $validator->after(static function (Validator $validator): void {
            WebhookEndpointRules::checkDestination($validator);
        });
    }
}
