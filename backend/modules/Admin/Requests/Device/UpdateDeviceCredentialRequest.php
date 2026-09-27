<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Models\Device\Device;
use Modules\Admin\Models\Device\DeviceCredential;
use Modules\Core\Models\User\User;

final class UpdateDeviceCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');
        $user = $this->user();

        return $device instanceof Device
            && $user instanceof User
            && Gate::forUser($user)->allows('manageCredentials', $device);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'access_token' => [
                'sometimes',
                'nullable',
                'string',
                'min:16',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'status' => ['sometimes', Rule::enum(DeviceCredentialStatus::class)],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'device_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     access_token: string|null,
     *     status: DeviceCredentialStatus|null
     * }
     */
    public function credentialInput(): array
    {
        /** @var array{
         *     access_token?: string|null,
         *     status?: string
         * } $validated
         */
        $validated = $this->validated();

        return [
            'access_token' => $validated['access_token'] ?? null,
            'status' => isset($validated['status'])
                ? DeviceCredentialStatus::from($validated['status'])
                : null,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $device = $this->route('device');

        if (! $device instanceof Device) {
            throw new LogicException('The device route binding is required.');
        }

        $validator->after(function (Validator $validator) use ($device): void {
            if (! $this->filled('access_token') && ! $this->has('status')) {
                $validator->errors()->add(
                    'access_token',
                    'At least one of access_token or status must be provided.',
                );

                return;
            }

            if ($validator->errors()->has('access_token') || ! $this->filled('access_token')) {
                return;
            }

            $tokenHash = hash('sha256', $this->string('access_token')->value());
            $exists = DeviceCredential::query()
                ->where('token_hash', $tokenHash)
                ->where('device_id', '!=', $device->getKey())
                ->exists();

            if ($exists) {
                $validator->errors()->add('access_token', 'The access token has already been taken.');
            }
        });
    }
}
