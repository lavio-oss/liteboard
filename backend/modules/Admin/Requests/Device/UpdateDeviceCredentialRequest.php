<?php

namespace Modules\Admin\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\Admin\Enums\Device\DeviceCredentialStatus;
use Modules\Admin\Models\Device\Device;
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
            'access_token' => ['missing'],
            'status' => ['required', Rule::enum(DeviceCredentialStatus::class)],
            'tenant_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'device_id' => ['prohibited'],
        ];
    }

    public function credentialStatus(): DeviceCredentialStatus
    {
        /** @var array{status: string} $validated */
        $validated = $this->validated();

        return DeviceCredentialStatus::from($validated['status']);
    }
}
