<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/** Called by telemetry-service only; it proves itself with the shared internal secret, not a user session. */
final class RegisterGatewaySubDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = config('services.telemetry.internal_secret');
        $provided = $this->header('X-Internal-Token');

        return is_string($secret)
            && strlen($secret) >= 32
            && is_string($provided)
            && hash_equals($secret, $provided);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:64'],
        ];
    }

    public function subDeviceCode(): string
    {
        return $this->string('code')->value();
    }
}
