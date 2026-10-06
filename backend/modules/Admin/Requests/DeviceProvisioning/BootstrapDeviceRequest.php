<?php

namespace Modules\Admin\Requests\DeviceProvisioning;

use Illuminate\Foundation\Http\FormRequest;

final class BootstrapDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'provision_key' => ['required', 'string', 'max:64'],
            'provision_secret' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'min:2', 'max:255'],
            'device_code' => ['required', 'string', 'max:64'],
            'request_id' => ['required', 'uuid'],
            'firmware_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'access_token' => ['prohibited'],
            'credential_type' => ['prohibited'],
            'encrypted_token' => ['prohibited'],
            'mqtt_password_hash' => ['prohibited'],
            'provision_secret_hash' => ['prohibited'],
            'device_id' => ['prohibited'],
            'token_hash' => ['prohibited'],
            'password' => ['prohibited'],
            'status' => ['prohibited'],
            'equipment_asset_id' => ['prohibited'],
        ];
    }

    /** @return array{key: string, secret: string, request_id: string, input: array{name: string, code: string, firmware_version: string|null}} */
    public function bootstrapInput(): array
    {
        /** @var array{provision_key: string, provision_secret: string, device_name: string, device_code: string, request_id: string, firmware_version?: string|null} $validated */
        $validated = $this->validated();

        return [
            'key' => $validated['provision_key'],
            'secret' => $validated['provision_secret'],
            'request_id' => $validated['request_id'],
            'input' => [
                'name' => $validated['device_name'],
                'code' => $validated['device_code'],
                'firmware_version' => array_key_exists('firmware_version', $validated)
                    ? $validated['firmware_version']
                    : null,
            ],
        ];
    }
}
