<?php

namespace Modules\Admin\Requests\Webhook;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Modules\Admin\Models\Webhook\WebhookEndpoint;
use Modules\Core\Models\User\User;

final class RedeliverWebhookDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $endpoint = $this->route('webhookEndpoint');
        $user = $this->user();

        return $endpoint instanceof WebhookEndpoint
            && $user instanceof User
            && Gate::forUser($user)->allows('redeliver', $endpoint);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }
}
