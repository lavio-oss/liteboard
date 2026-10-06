<?php

namespace Modules\Admin\Requests\DeviceProvisioning;

use Illuminate\Foundation\Http\FormRequest;

final class DeviceApprovalStatusRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'provision_key' => $this->header('X-Provision-Key'),
            'provision_secret' => $this->header('X-Provision-Secret'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'provision_key' => ['required', 'string', 'max:64'],
            'provision_secret' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array{key: string, secret: string, request_id: string} */
    public function statusInput(): array
    {
        /** @var array{request_id: string, provision_key: string, provision_secret: string} $validated */
        $validated = $this->validated();

        return [
            'key' => $validated['provision_key'],
            'secret' => $validated['provision_secret'],
            'request_id' => $validated['request_id'],
        ];
    }
}
