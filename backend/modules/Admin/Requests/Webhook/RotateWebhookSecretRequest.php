<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

final class RotateWebhookSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        $endpoint = $this->route('webhookEndpoint');
        $user = $this->user();

        return $endpoint instanceof WebhookEndpoint
            && $user instanceof User
            && Gate::forUser($user)->allows('rotateSecret', $endpoint);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
