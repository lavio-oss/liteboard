<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Modules\Admin\Models\Project\Project;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

final class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof Project
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [WebhookEndpoint::class, $project]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return WebhookEndpointRules::rules('required');
    }

    /** @return array{name: string, url: string, event_types: list<string>, enabled?: bool} */
    public function endpointAttributes(): array
    {
        /** @var array{name: string, url: string, event_types: list<string>, enabled?: bool} $attributes */
        $attributes = $this->validated();

        return $attributes;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            WebhookEndpointRules::checkDestination($validator);
        });
    }
}
